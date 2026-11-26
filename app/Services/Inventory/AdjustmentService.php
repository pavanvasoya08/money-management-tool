<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Core\App;
use App\Core\Database;
use App\Core\Paginator;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Approval\ApprovalEngine;
use App\Support\BusinessDate;
use App\Support\Money;
use App\Support\Qty;

/**
 * Stock adjustments: physical counts (book quantity vs counted quantity → adjustment in / out)
 * and condition changes (e.g. available → damaged, inspection → available). Every adjustment is
 * posted as ledger rows — balances are never overwritten — and keeps the old and new quantity.
 * If stock moved after the count was entered, posting stops and asks for a recount. Posted
 * adjustments are reversed with opposite movements, never deleted.
 */
final class AdjustmentService
{
    public const SUBJECT = 'inventory_adjustment';
    public const REASONS = ['physical_difference' => 'Physical stock difference', 'damaged' => 'Damaged goods', 'expired' => 'Expired goods', 'missing' => 'Missing stock',
        'found' => 'Found stock', 'data_correction' => 'Data correction', 'condition_change' => 'Condition change (e.g. inspected, repaired)', 'other' => 'Other'];
    public const STATUSES = EntryService::STATUSES;
    public const STATES = ['sellable' => 'Available', 'quarantine' => 'Inspection / restock pending', 'damaged' => 'Damaged'];
    private static bool $listening = false;

    public function __construct(private readonly Database $db)
    {
    }

    public static function registerApprovalListener(): void
    {
        if (self::$listening) {
            return;
        }
        self::$listening = true;
        ApprovalEngine::onDecision(self::SUBJECT, static function (array $request, string $status): void {
            (new self(App::db()))->applyDecision($request, $status);
        });
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT a.*, w.code AS warehouse_code, w.name AS warehouse_name, cu.name AS created_by_name, au.name AS approved_by_name, pu.name AS posted_by_name, ru.name AS reversed_by_name
            FROM inventory_adjustments a JOIN warehouses w ON w.id = a.warehouse_id LEFT JOIN users cu ON cu.id = a.created_by LEFT JOIN users au ON au.id = a.approved_by
            LEFT JOIN users pu ON pu.id = a.posted_by LEFT JOIN users ru ON ru.id = a.reversed_by WHERE a.id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $id): array
    {
        return $this->db->select('SELECT i.*, p.name AS product_name, p.product_code, p.sku, p.unit AS stock_unit, v.name AS variant_name, l.code AS location_code, b.batch_no
            FROM inventory_adjustment_items i JOIN products p ON p.id = i.product_id LEFT JOIN product_variants v ON v.id = i.variant_id LEFT JOIN warehouse_locations l ON l.id = i.location_id
            LEFT JOIN inventory_batches b ON b.id = i.batch_id WHERE i.adjustment_id = ? ORDER BY i.line_no', [$id]);
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f */
    public function paginate(array $user, array $f, int $page, int $perPage): Paginator
    {
        [$w, $p] = InventoryScope::sql($user, 'a.warehouse_id');
        $sql = " FROM inventory_adjustments a JOIN warehouses w ON w.id = a.warehouse_id LEFT JOIN users u ON u.id = a.created_by WHERE {$w}";
        if (($f['warehouse'] ?? '') !== '') {
            $sql .= ' AND a.warehouse_id = ?';
            $p[] = (int) $f['warehouse'];
        }
        if (($f['status'] ?? '') !== '' && isset(self::STATUSES[$f['status']])) {
            $sql .= ' AND a.status = ?';
            $p[] = $f['status'];
        }
        if (($f['reason'] ?? '') !== '' && isset(self::REASONS[$f['reason']])) {
            $sql .= ' AND a.reason = ?';
            $p[] = $f['reason'];
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (($f[$k] ?? '') !== '' && BusinessDate::isValid((string) $f[$k])) {
                $sql .= " AND a.adjustment_date {$op} ?";
                $p[] = $f[$k];
            }
        }
        if (($f['product'] ?? '') !== '') {
            $sql .= ' AND a.id IN (SELECT adjustment_id FROM inventory_adjustment_items WHERE product_id = ?)';
            $p[] = (int) $f['product'];
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $sql .= ' AND (a.adjustment_no LIKE ? OR a.notes LIKE ?)';
            array_push($p, "%{$q}%", "%{$q}%");
        }
        $total = (int) $this->db->scalar('SELECT COUNT(*)' . $sql, $p);
        $rows = $this->db->select('SELECT a.*, w.code AS warehouse_code, w.name AS warehouse_name, u.name AS created_by_name,
                (SELECT COUNT(*) FROM inventory_adjustment_items i WHERE i.adjustment_id = a.id) AS line_count,
                (SELECT SUM(i.total_cost) FROM inventory_adjustment_items i WHERE i.adjustment_id = a.id) AS value_change'
            . $sql . ' ORDER BY a.adjustment_date DESC, a.id DESC LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage), $p);
        return new Paginator($rows, $total, $page, $perPage);
    }

    /**
     * Lines: [line_type count|condition, product_id, variant_id, location_id, batch_id | batch_no, stock_state, physical_qty (count) | quantity + to_state (condition), unit_cost, serial_numbers, notes].
     * @param array<string, mixed> $data @param array<string, mixed>|null $a @param array<string, mixed> $actor
     */
    public function save(array $data, ?array $a, array $actor): int
    {
        if ($a !== null && !in_array($a['status'], ['draft', 'rejected'], true)) {
            throw new DomainRuleException('Only drafts and rejected adjustments can be edited.');
        }
        $wh = (new WarehouseService($this->db))->find((int) ($data['warehouse_id'] ?? 0));
        if ($wh === null) {
            throw ValidationException::withMessage('warehouse_id', 'Choose a warehouse.');
        }
        WarehouseService::assertUsable($wh);
        $reason = (string) ($data['reason'] ?? '');
        if (!isset(self::REASONS[$reason])) {
            throw ValidationException::withMessage('reason', 'Choose the reason for the adjustment.');
        }
        $date = trim((string) ($data['adjustment_date'] ?? ''));
        if (!BusinessDate::isValid($date) || $date > BusinessDate::today()) {
            throw ValidationException::withMessage('adjustment_date', 'Enter a valid date that is not in the future.');
        }
        $notes = trim((string) ($data['notes'] ?? ''));
        if (in_array($reason, ['other', 'data_correction'], true) && $notes === '') {
            throw ValidationException::withMessage('notes', 'Explain the adjustment.');
        }
        $lines = $this->validateLines((array) ($data['lines'] ?? []), $wh);
        $id = $this->db->transaction(function () use ($a, $wh, $reason, $date, $notes, $lines, $actor): int {
            $header = ['adjustment_date' => $date, 'warehouse_id' => (int) $wh['id'], 'reason' => $reason, 'notes' => $notes !== '' ? mb_substr($notes, 0, 5000) : null];
            if ($a === null) {
                $id = $this->db->insert('inventory_adjustments', $header + ['adjustment_no' => InventoryNumbers::next($this->db, 'adjustment'), 'status' => 'draft',
                    'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $id = (int) $a['id'];
                $this->db->update('inventory_adjustments', $header + ['status' => 'draft', 'rejection_reason' => null, 'posting_error' => null, 'approval_request_id' => null,
                    'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $id]);
                $this->db->execute('DELETE FROM inventory_adjustment_items WHERE adjustment_id = ?', [$id]);
            }
            foreach ($lines as $n => $l) {
                $this->db->insert('inventory_adjustment_items', ['adjustment_id' => $id, 'line_no' => $n, 'line_type' => $l['line_type'], 'product_id' => $l['product_id'], 'variant_id' => $l['variant_id'],
                    'location_id' => $l['location_id'], 'batch_id' => $l['batch_id'], 'stock_state' => $l['stock_state'], 'to_state' => $l['to_state'],
                    'system_qty' => Qty::toDb($l['system_qty']), 'physical_qty' => $l['physical_qty'] !== null ? Qty::toDb($l['physical_qty']) : null, 'difference_qty' => Qty::toDb($l['difference']),
                    'unit_cost' => $l['unit_cost'] !== null ? Qty::toDb($l['unit_cost']) : null, 'serial_numbers' => $l['serials'] !== [] ? implode("\n", $l['serials']) : null, 'notes' => $l['notes']]);
            }
            InventoryHistory::write($this->db, 'adjustment', $id, $a === null ? 'created' : 'edited', null, ['lines' => count($lines), 'reason' => $reason], null, $actor, (int) $wh['id'], to: 'draft');
            return $id;
        });
        $row = $this->find($id);
        App::activity()->log('inventory.adjustment_' . ($a === null ? 'created' : 'edited'), ($a === null ? 'Created' : 'Edited') . " stock adjustment {$row['adjustment_no']}", 'inventory_adjustment', $id,
            module: 'inventory', newValues: ['adjustment_no' => $row['adjustment_no'], 'warehouse' => $wh['code'], 'reason' => $reason, 'lines' => count($lines)]);
        return $id;
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $actor @return array<string, mixed> */
    public function submit(array $a, array $actor): array
    {
        if (!in_array($a['status'], ['draft', 'rejected'], true)) {
            throw new DomainRuleException('Only drafts can be submitted.');
        }
        $changed = $this->db->execute("UPDATE inventory_adjustments SET status = 'pending_approval', updated_at = ? WHERE id = ? AND status IN ('draft', 'rejected')", [now(), $a['id']]);
        if ($changed !== 1) {
            throw new DomainRuleException('This adjustment was changed by someone else. Please refresh.');
        }
        InventoryHistory::write($this->db, 'adjustment', (int) $a['id'], 'submitted', null, null, null, $actor, (int) $a['warehouse_id'], (string) $a['status'], 'pending_approval');
        try {
            $req = App::approvals()->submit('inventory_adjustment', self::SUBJECT, (int) $a['id'], 0, 'Stock adjustment ' . $a['adjustment_no'] . ' — ' . self::REASONS[$a['reason']], $actor, 'inventory.manage_stock_adjustments');
        } catch (\Throwable $e) {
            $this->db->update('inventory_adjustments', ['status' => $a['status'], 'updated_at' => now()], ['id' => $a['id']]);
            throw $e;
        }
        $this->db->update('inventory_adjustments', ['approval_request_id' => (int) $req['id']], ['id' => $a['id']]);
        App::activity()->log('inventory.adjustment_submitted', "Submitted stock adjustment {$a['adjustment_no']}", 'inventory_adjustment', (int) $a['id'], module: 'inventory', newValues: ['request' => (int) $req['id']]);
        $fresh = $this->find((int) $a['id']);
        if ($fresh['status'] === 'approved' && $fresh['posting_error'] !== null) {
            throw new DomainRuleException('Approved, but not posted: ' . $fresh['posting_error']);
        }
        return $fresh;
    }

    /** @param array<string, mixed> $request */
    public function applyDecision(array $request, string $status): void
    {
        $a = $this->find((int) $request['subject_id']);
        if ($a === null || $a['status'] !== 'pending_approval') {
            return;
        }
        $last = $this->db->first('SELECT acted_by, comment FROM approval_actions WHERE request_id = ? ORDER BY id DESC LIMIT 1', [$request['id']]);
        $by = ['id' => $last !== null ? (int) $last['acted_by'] : (int) $a['created_by']];
        if ($status === 'approved') {
            $this->db->update('inventory_adjustments', ['status' => 'approved', 'approved_by' => $by['id'], 'approved_at' => now(), 'updated_at' => now()], ['id' => $a['id']]);
            InventoryHistory::write($this->db, 'adjustment', (int) $a['id'], 'approved', null, null, $last['comment'] ?? null, $by, (int) $a['warehouse_id'], 'pending_approval', 'approved');
            try {
                $this->post($this->find((int) $a['id']), $by);
            } catch (DomainRuleException | ValidationException) {
                // stays approved with the reason
            }
            return;
        }
        $to = $status === 'rejected' ? 'rejected' : 'draft';
        $this->db->update('inventory_adjustments', ['status' => $to, 'rejection_reason' => $status === 'rejected' ? mb_substr((string) ($last['comment'] ?? 'Rejected'), 0, 500) : null,
            'approval_request_id' => null, 'updated_at' => now()], ['id' => $a['id']]);
        InventoryHistory::write($this->db, 'adjustment', (int) $a['id'], $status === 'rejected' ? 'rejected' : 'withdrawn', null, null, $last['comment'] ?? null, $by, (int) $a['warehouse_id'], 'pending_approval', $to);
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $actor */
    public function post(array $a, array $actor): void
    {
        if ($a['status'] !== 'approved') {
            throw new DomainRuleException($a['status'] === 'posted' ? 'This adjustment has already been posted.' : 'Only approved adjustments can be posted.');
        }
        try {
            $this->db->transaction(function () use ($a, $actor): void {
                $changed = $this->db->execute("UPDATE inventory_adjustments SET status = 'posted', posted_by = ?, posted_at = ?, posting_error = NULL, updated_at = ? WHERE id = ? AND status = 'approved'",
                    [(int) $actor['id'], now(), now(), $a['id']]);
                if ($changed !== 1) {
                    throw new DomainRuleException('This adjustment has already been posted.');
                }
                $this->postLines($a, $actor);
            });
        } catch (DomainRuleException | ValidationException $e) {
            $msg = $e instanceof ValidationException ? (array_values($e->errors)[0][0] ?? $e->getMessage()) : $e->getMessage();
            if ($msg !== 'This adjustment has already been posted.') {
                $this->db->update('inventory_adjustments', ['posting_error' => mb_substr($msg, 0, 500), 'updated_at' => now()], ['id' => $a['id']]);
                InventoryHistory::write($this->db, 'adjustment', (int) $a['id'], 'posting_failed', null, null, $msg, $actor, (int) $a['warehouse_id']);
            }
            throw $e;
        }
        InventoryHistory::write($this->db, 'adjustment', (int) $a['id'], 'posted', null, null, null, $actor, (int) $a['warehouse_id'], 'approved', 'posted', (string) $a['adjustment_date']);
        App::activity()->log('inventory.adjustment_posted', "Posted stock adjustment {$a['adjustment_no']}", 'inventory_adjustment', (int) $a['id'], module: 'inventory', userId: (int) $actor['id']);
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $actor */
    public function cancel(array $a, string $reason, array $actor): void
    {
        if (!in_array($a['status'], ['draft', 'rejected', 'pending_approval', 'approved'], true)) {
            throw new DomainRuleException('Only adjustments that are not posted can be cancelled.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        if ($a['status'] === 'pending_approval' && $a['approval_request_id'] !== null) {
            $this->db->execute("UPDATE approval_requests SET status = 'cancelled', completed_at = ?, updated_at = ? WHERE id = ? AND status = 'pending'", [now(), now(), $a['approval_request_id']]);
            $this->db->insert('approval_actions', ['request_id' => $a['approval_request_id'], 'step_order' => null, 'action' => 'cancelled', 'acted_by' => (int) $actor['id'], 'comment' => mb_substr($reason, 0, 500), 'created_at' => now()]);
        }
        $changed = $this->db->execute("UPDATE inventory_adjustments SET status = 'cancelled', rejection_reason = ?, updated_by = ?, updated_at = ? WHERE id = ? AND status = ?",
            [mb_substr($reason, 0, 500), (int) $actor['id'], now(), $a['id'], $a['status']]);
        if ($changed !== 1) {
            throw new DomainRuleException('This adjustment was changed by someone else. Please refresh.');
        }
        InventoryHistory::write($this->db, 'adjustment', (int) $a['id'], 'cancelled', null, null, $reason, $actor, (int) $a['warehouse_id'], (string) $a['status'], 'cancelled');
        App::activity()->log('inventory.adjustment_cancelled', "Cancelled stock adjustment {$a['adjustment_no']}", 'inventory_adjustment', (int) $a['id'], module: 'inventory', newValues: ['reason' => $reason]);
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $actor */
    public function reverse(array $a, string $reason, array $actor): void
    {
        if ($a['status'] !== 'posted') {
            throw new DomainRuleException('Only posted adjustments can be reversed.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $this->db->transaction(function () use ($a, $reason, $actor): void {
            $changed = $this->db->execute("UPDATE inventory_adjustments SET status = 'reversed', reversed_by = ?, reversed_at = ?, reversal_reason = ?, updated_at = ? WHERE id = ? AND status = 'posted'",
                [(int) $actor['id'], now(), mb_substr($reason, 0, 500), now(), $a['id']]);
            if ($changed !== 1) {
                throw new DomainRuleException('This adjustment has already been reversed.');
            }
            $ledger = new InventoryLedger($this->db);
            $moves = $this->db->select("SELECT * FROM inventory_stock_movements WHERE source_type = 'adjustment' AND source_id = ? AND reversal_of_id IS NULL AND movement_type <> 'reversal' ORDER BY id DESC", [$a['id']]);
            $ledger->lock(array_map(static fn (array $m): int => (int) $m['stock_item_id'], $moves));
            foreach ($moves as $m) {
                $ledger->reverse($m, ['actor' => $actor, 'date' => BusinessDate::today(), 'reason' => $reason, 'source_type' => 'adjustment', 'source_id' => (int) $a['id']]);
            }
            InventoryHistory::write($this->db, 'adjustment', (int) $a['id'], 'reversed', null, ['movements' => count($moves)], $reason, $actor, (int) $a['warehouse_id'], 'posted', 'reversed');
        });
        App::activity()->log('inventory.adjustment_reversed', "Reversed stock adjustment {$a['adjustment_no']}", 'inventory_adjustment', (int) $a['id'], module: 'inventory', newValues: ['reason' => $reason]);
    }

    /** Book quantity for a count line (all of the item in that location / batch / condition). */
    public function systemQty(int $warehouseId, int $productId, ?int $variantId, ?int $locationId, ?int $batchId, string $state): int
    {
        $item = $this->db->first('SELECT id FROM inventory_stock_items WHERE item_key = ?', [$warehouseId . ':' . $productId . ':' . ($variantId ?? 0)]);
        return $item === null ? 0 : (new InventoryLedger($this->db))->balance((int) $item['id'], $locationId, $batchId, $state);
    }

    // ---------------------------------------------------------------- internals

    /** @param array<string, mixed> $a @param array<string, mixed> $actor */
    private function postLines(array $a, array $actor): void
    {
        $wh = (new WarehouseService($this->db))->find((int) $a['warehouse_id']);
        WarehouseService::assertUsable($wh);
        $ledger = new InventoryLedger($this->db);
        $stock = new StockLineService($this->db);
        $rows = $this->lines((int) $a['id']);
        $items = [];
        foreach ($rows as $r) {
            $items[(int) $r['id']] = $ledger->item((int) $wh['id'], (int) $r['product_id'], $r['variant_id'] !== null ? (int) $r['variant_id'] : null);
        }
        $ledger->lock(array_map(static fn (array $i): int => (int) $i['id'], $items));
        $base = ['source_type' => 'adjustment', 'source_id' => (int) $a['id'], 'reference_no' => (string) $a['adjustment_no'], 'date' => (string) $a['adjustment_date'], 'actor' => $actor,
            'approver_id' => $a['approved_by'] !== null ? (int) $a['approved_by'] : (int) $actor['id'], 'notes' => self::REASONS[$a['reason']]];
        foreach ($rows as $r) {
            $iid = (int) $r['id'];
            $item = $items[$iid];
            $cfg = (new ProductInventoryService($this->db))->config((int) $r['product_id']);
            $loc = $r['location_id'] !== null ? (int) $r['location_id'] : null;
            $batch = $r['batch_id'] !== null ? (int) $r['batch_id'] : null;
            $state = (string) $r['stock_state'];
            $diff = Qty::fromDb($r['difference_qty']);
            $serials = StockLineService::serialList((string) ($r['serial_numbers'] ?? ''));
            if ($r['line_type'] === 'count') {
                $now = $ledger->balance((int) $item['id'], $loc, $batch, $state);
                if ($now !== Qty::fromDb($r['system_qty'])) {
                    throw new DomainRuleException("Line {$r['line_no']} ({$r['product_name']}): the book quantity changed from " . Qty::format(Qty::fromDb($r['system_qty'])) . ' to ' . Qty::format($now)
                        . ' since the count was entered. Edit the adjustment to recount.');
                }
                if ($diff === 0) {
                    continue;
                }
                if ($diff > 0) {
                    $serialIds = $cfg['serial_tracking'] ? $stock->registerSerials(['product_id' => (int) $r['product_id'], 'variant_id' => $r['variant_id'] !== null ? (int) $r['variant_id'] : null,
                        'serials' => $serials, 'unit_cost' => $r['unit_cost'] !== null ? Qty::fromDb($r['unit_cost']) : null], $batch, ['actor' => $actor, 'warehouse_id' => $wh['id']]) : [];
                    $cost = $r['unit_cost'] !== null ? Qty::fromDb($r['unit_cost']) : (Qty::fromDb($item['qty_on_hand']) > 0
                        ? Qty::unitCost(Money::fromDb($ledger->freshItem((int) $item['id'])['stock_value']), Qty::fromDb($ledger->freshItem((int) $item['id'])['qty_on_hand'])) : Qty::fromDb($item['last_unit_cost']));
                    $m = $ledger->post($base + ['item' => $item, 'dir' => 'in', 'type' => 'adjustment_in', 'qty' => $diff, 'state' => $state, 'location_id' => $loc, 'batch_id' => $batch,
                        'unit_cost' => $cost, 'source_line_id' => $iid, 'posting_key' => "adjustment:{$a['id']}:{$iid}:in", 'serial_ids' => $serialIds,
                        'serial_after' => ['status' => 'in_stock', 'warehouse_id' => (int) $wh['id'], 'location_id' => $loc, 'stock_state' => $state, 'batch_id' => $batch]]);
                    $this->db->update('inventory_adjustment_items', ['unit_cost' => Qty::toDb($cost), 'total_cost' => Money::toDb($m['value'])], ['id' => $iid]);
                } else {
                    $chunks = $cfg['serial_tracking'] ? $stock->serialAllocation((int) $r['product_id'], $r['variant_id'] !== null ? (int) $r['variant_id'] : null, (int) $wh['id'], $state, $serials, $loc, $batch) : [['qty' => -$diff, 'location_id' => $loc, 'batch_id' => $batch]];
                    $value = 0;
                    foreach ($chunks as $k => $ch) {
                        $m = $ledger->post($base + ['item' => $item, 'dir' => 'out', 'type' => 'adjustment_out', 'qty' => $ch['qty'], 'state' => $state, 'location_id' => $ch['location_id'], 'batch_id' => $ch['batch_id'],
                            'source_line_id' => $iid, 'posting_key' => "adjustment:{$a['id']}:{$iid}:out" . ($k + 1), 'serial_ids' => $ch['serial_ids'] ?? [],
                            'serial_after' => isset($ch['serial_ids']) ? ['status' => 'written_off', 'warehouse_id' => null, 'location_id' => null, 'stock_state' => null] : null]);
                        $value += $m['value'];
                    }
                    $this->db->update('inventory_adjustment_items', ['unit_cost' => Qty::toDb(Qty::unitCost($value, -$diff)), 'total_cost' => Money::toDb(-$value)], ['id' => $iid]);
                }
                continue;
            }
            // condition change: same location and batch, new condition, same cost
            $to = (string) $r['to_state'];
            $chunks = $cfg['serial_tracking'] ? $stock->serialAllocation((int) $r['product_id'], $r['variant_id'] !== null ? (int) $r['variant_id'] : null, (int) $wh['id'], $state, $serials, $loc, $batch)
                : $stock->allocate((int) $item['id'], $state, $diff, $loc, $batch, true, false);
            foreach ($chunks as $k => $ch) {
                $out = $ledger->post($base + ['item' => $item, 'dir' => 'out', 'type' => 'condition_out', 'qty' => $ch['qty'], 'state' => $state, 'location_id' => $ch['location_id'], 'batch_id' => $ch['batch_id'],
                    'source_line_id' => $iid, 'posting_key' => "adjustment:{$a['id']}:{$iid}:c" . ($k + 1) . ':out', 'serial_ids' => $ch['serial_ids'] ?? []]);
                $ledger->post($base + ['item' => $item, 'dir' => 'in', 'type' => 'condition_in', 'qty' => $ch['qty'], 'state' => $to, 'location_id' => $ch['location_id'], 'batch_id' => $ch['batch_id'],
                    'value' => $out['value'], 'source_line_id' => $iid, 'posting_key' => "adjustment:{$a['id']}:{$iid}:c" . ($k + 1) . ':in', 'pair_id' => (int) $out['id'], 'serial_ids' => $ch['serial_ids'] ?? [],
                    'serial_after' => isset($ch['serial_ids']) ? ['stock_state' => $to] : null]);
            }
        }
    }

    /** @param array<int|string, mixed> $raw @param array<string, mixed> $wh @return array<int, array<string, mixed>> */
    private function validateLines(array $raw, array $wh): array
    {
        $stock = new StockLineService($this->db);
        $lines = [];
        $n = 0;
        $seen = [];
        foreach ($raw as $in) {
            if (!is_array($in) || (int) ($in['product_id'] ?? 0) === 0) {
                continue;
            }
            $n++;
            $type = (string) ($in['line_type'] ?? 'count');
            if (!in_array($type, ['count', 'condition'], true)) {
                throw ValidationException::withMessage("lines.{$n}.line_type", "Line {$n}: choose count or condition change.");
            }
            $state = (string) ($in['stock_state'] ?? 'sellable');
            if (!isset(self::STATES[$state])) {
                throw ValidationException::withMessage("lines.{$n}.stock_state", "Line {$n}: choose the stock condition.");
            }
            // Re-use the line validation for product, variant, unit, batch and location.
            $probe = $in;
            $probe['quantity'] = $type === 'count' ? '1' : (string) ($in['quantity'] ?? '');
            $probe['serial_numbers'] = '';
            $cfgProbe = (new ProductInventoryService($this->db))->config((int) $in['product_id']);
            if ($cfgProbe['serial_tracking'] && $type === 'condition') {
                $probe['serial_numbers'] = (string) ($in['serial_numbers'] ?? '');
            }
            if ($cfgProbe['serial_tracking'] && $type === 'count') {
                $probe['serial_numbers'] = 'PROBE-1';
            }
            $l = $stock->normalize($probe, $wh, 'out', $n);
            $c = $l['config'];
            if ($l['batch_no'] !== null) {
                $b = $this->db->first('SELECT id FROM inventory_batches WHERE batch_key = ?', [StockLineService::batchKey($l['product_id'], $l['variant_id'], $l['batch_no'])]);
                if ($b === null) {
                    throw ValidationException::withMessage("lines.{$n}.batch_no", "Line {$n}: batch {$l['batch_no']} is not recorded for this item. Receive new batches with a stock inward.");
                }
                $l['batch_id'] = (int) $b['id'];
            }
            if ($c['batch_tracking'] && $l['batch_id'] === null) {
                throw ValidationException::withMessage("lines.{$n}.batch_no", "Line {$n}: {$c['product']['name']} tracks batches — choose the batch counted.");
            }
            $key = $type . ':' . $l['product_id'] . ':' . ($l['variant_id'] ?? 0) . ':' . ($l['location_id'] ?? 0) . ':' . ($l['batch_id'] ?? 0) . ':' . $state;
            if (isset($seen[$key])) {
                throw ValidationException::withMessage("lines.{$n}.product_id", "Line {$n}: the same item, location, batch and condition appears twice.");
            }
            $seen[$key] = true;
            $system = $this->systemQty((int) $wh['id'], $l['product_id'], $l['variant_id'], $l['location_id'], $l['batch_id'], $state);
            $serials = StockLineService::serialList((string) ($in['serial_numbers'] ?? ''));
            $toState = null;
            $physical = null;
            if ($type === 'count') {
                $physical = Qty::parse((string) ($in['physical_qty'] ?? ''), $c['decimals']);
                if ($physical === null) {
                    throw ValidationException::withMessage("lines.{$n}.physical_qty", "Line {$n}: enter the counted quantity (0 or more).");
                }
                $diff = $physical - $system;
                if ($c['serial_tracking'] && count($serials) !== intdiv(abs($diff), Qty::SCALE)) {
                    throw ValidationException::withMessage("lines.{$n}.serial_numbers", "Line {$n}: list the " . Qty::format(abs($diff)) . ' serial numbers ' . ($diff > 0 ? 'found' : 'missing') . '.');
                }
            } else {
                $toState = (string) ($in['to_state'] ?? '');
                if (!isset(self::STATES[$toState]) || $toState === $state) {
                    throw ValidationException::withMessage("lines.{$n}.to_state", "Line {$n}: choose a different condition to move the stock to.");
                }
                $diff = $l['base_quantity'];
                if ($diff > $system) {
                    throw ValidationException::withMessage("lines.{$n}.quantity", "Line {$n}: only " . Qty::format($system) . " {$c['unit_name']} are " . strtolower(self::STATES[$state]) . ' there.');
                }
            }
            $cost = null;
            if (trim((string) ($in['unit_cost'] ?? '')) !== '') {
                $cost = Qty::parse((string) $in['unit_cost']);
                if ($cost === null) {
                    throw ValidationException::withMessage("lines.{$n}.unit_cost", "Line {$n}: enter the unit cost as a number.");
                }
            }
            $lines[$n] = ['line_type' => $type, 'product_id' => $l['product_id'], 'variant_id' => $l['variant_id'], 'location_id' => $l['location_id'], 'batch_id' => $l['batch_id'],
                'stock_state' => $state, 'to_state' => $toState, 'system_qty' => $system, 'physical_qty' => $physical, 'difference' => $diff, 'unit_cost' => $cost, 'serials' => $serials,
                'notes' => $l['notes']];
        }
        if ($lines === []) {
            throw ValidationException::withMessage('lines', 'Add at least one line.');
        }
        return $lines;
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    public function storedAsInput(array $a): array
    {
        $lines = [];
        foreach ($this->lines((int) $a['id']) as $r) {
            $lines[] = ['line_type' => $r['line_type'], 'product_id' => $r['product_id'], 'variant_id' => $r['variant_id'], 'location_id' => $r['location_id'], 'batch_id' => $r['batch_id'],
                'stock_state' => $r['stock_state'], 'to_state' => $r['to_state'], 'physical_qty' => $r['physical_qty'] !== null ? Qty::toInput(Qty::fromDb($r['physical_qty'])) : '',
                'quantity' => Qty::toInput(abs(Qty::fromDb($r['difference_qty']))), 'unit_cost' => $r['unit_cost'] !== null ? Qty::toInput(Qty::fromDb($r['unit_cost'])) : '',
                'serial_numbers' => $r['serial_numbers'], 'notes' => $r['notes']];
        }
        return ['warehouse_id' => $a['warehouse_id'], 'adjustment_date' => $a['adjustment_date'], 'reason' => $a['reason'], 'notes' => $a['notes'], 'lines' => $lines];
    }
}
