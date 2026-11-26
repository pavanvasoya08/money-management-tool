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
 * Stock transfers between warehouses or between locations of one warehouse.
 *
 * Draft → submit → approval → dispatch. With "in transit" on (setting) and two different
 * warehouses, dispatch moves the stock out of available stock into the In-transit condition
 * of the source warehouse; the destination then receives it (partially or in full), and any
 * shortage is written off by an authorised user. Otherwise dispatch moves the stock at once.
 * Every step is one transaction with linked out / in ledger rows carrying the same cost (FIFO
 * layers move with the stock). Transfers are internal: never purchases or sales.
 */
final class TransferService
{
    public const SUBJECT = 'inventory_transfer';
    public const STATUSES = ['draft' => 'Draft', 'pending_approval' => 'Pending approval', 'approved' => 'Approved — not dispatched', 'in_transit' => 'In transit',
        'partially_received' => 'Partially received', 'received' => 'Received', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'reversed' => 'Reversed'];
    public const OPEN = ['draft', 'pending_approval', 'approved', 'in_transit', 'partially_received'];
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
        return $this->db->first('SELECT t.*, fw.code AS from_code, fw.name AS from_name, tw.code AS to_code, tw.name AS to_name, tw.uses_locations AS to_uses_locations,
                cu.name AS created_by_name, au.name AS approved_by_name, du.name AS dispatched_by_name, ru.name AS reversed_by_name
            FROM inventory_transfers t JOIN warehouses fw ON fw.id = t.from_warehouse_id JOIN warehouses tw ON tw.id = t.to_warehouse_id LEFT JOIN users cu ON cu.id = t.created_by
            LEFT JOIN users au ON au.id = t.approved_by LEFT JOIN users du ON du.id = t.dispatched_by LEFT JOIN users ru ON ru.id = t.reversed_by WHERE t.id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function items(int $transferId): array
    {
        return $this->db->select('SELECT i.*, p.name AS product_name, p.product_code, p.sku, p.unit AS stock_unit, v.name AS variant_name, v.sku AS variant_sku,
                fl.code AS from_location_code, tl.code AS to_location_code, b.batch_no
            FROM inventory_transfer_items i JOIN products p ON p.id = i.product_id LEFT JOIN product_variants v ON v.id = i.variant_id
            LEFT JOIN warehouse_locations fl ON fl.id = i.from_location_id LEFT JOIN warehouse_locations tl ON tl.id = i.to_location_id LEFT JOIN inventory_batches b ON b.id = i.batch_id
            WHERE i.transfer_id = ? ORDER BY i.line_no', [$transferId]);
    }

    /** @return list<array<string, mixed>> */
    public function receipts(int $transferId): array
    {
        $rows = $this->db->select('SELECT r.*, u.name AS created_by_name FROM inventory_transfer_receipts r LEFT JOIN users u ON u.id = r.created_by WHERE r.transfer_id = ? ORDER BY r.id', [$transferId]);
        foreach ($rows as &$r) {
            $r['items'] = $this->db->select('SELECT ri.*, i.line_no, p.name AS product_name, v.name AS variant_name FROM inventory_transfer_receipt_items ri
                JOIN inventory_transfer_items i ON i.id = ri.transfer_item_id JOIN products p ON p.id = i.product_id LEFT JOIN product_variants v ON v.id = i.variant_id WHERE ri.receipt_id = ?', [$r['id']]);
        }
        return $rows;
    }

    /** Transfers touching a warehouse the user may see. @param array<string, mixed> $user @param array<string, mixed> $f */
    public function paginate(array $user, array $f, int $page, int $perPage): Paginator
    {
        [$a, $pa] = InventoryScope::sql($user, 't.from_warehouse_id');
        [$b, $pb] = InventoryScope::sql($user, 't.to_warehouse_id');
        $sql = " FROM inventory_transfers t JOIN warehouses fw ON fw.id = t.from_warehouse_id JOIN warehouses tw ON tw.id = t.to_warehouse_id LEFT JOIN users u ON u.id = t.created_by WHERE ({$a} OR {$b})";
        $p = array_merge($pa, $pb);
        if (($f['status'] ?? '') === 'open') {
            $sql .= " AND t.transfer_status IN ('draft', 'pending_approval', 'approved', 'in_transit', 'partially_received')";
        } elseif (($f['status'] ?? '') !== '' && isset(self::STATUSES[$f['status']])) {
            $sql .= ' AND t.transfer_status = ?';
            $p[] = $f['status'];
        }
        if (($f['warehouse'] ?? '') !== '') {
            $sql .= ' AND (t.from_warehouse_id = ? OR t.to_warehouse_id = ?)';
            array_push($p, (int) $f['warehouse'], (int) $f['warehouse']);
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (($f[$k] ?? '') !== '' && BusinessDate::isValid((string) $f[$k])) {
                $sql .= " AND t.transfer_date {$op} ?";
                $p[] = $f[$k];
            }
        }
        if (($f['product'] ?? '') !== '') {
            $sql .= ' AND t.id IN (SELECT transfer_id FROM inventory_transfer_items WHERE product_id = ?)';
            $p[] = (int) $f['product'];
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $sql .= ' AND (t.transfer_no LIKE ? OR t.reason LIKE ?)';
            array_push($p, "%{$q}%", "%{$q}%");
        }
        $total = (int) $this->db->scalar('SELECT COUNT(*)' . $sql, $p);
        $rows = $this->db->select('SELECT t.*, fw.code AS from_code, fw.name AS from_name, tw.code AS to_code, tw.name AS to_name, u.name AS created_by_name,
                (SELECT COUNT(*) FROM inventory_transfer_items i WHERE i.transfer_id = t.id) AS line_count, (SELECT SUM(i.quantity) FROM inventory_transfer_items i WHERE i.transfer_id = t.id) AS total_qty,
                (SELECT SUM(i.qty_received + i.qty_damaged) FROM inventory_transfer_items i WHERE i.transfer_id = t.id) AS total_received'
            . $sql . ' ORDER BY t.transfer_date DESC, t.id DESC LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage), $p);
        return new Paginator($rows, $total, $page, $perPage);
    }

    /** @param array<string, mixed> $data @param array<string, mixed>|null $t @param array<string, mixed> $actor */
    public function save(array $data, ?array $t, array $actor): int
    {
        if ($t !== null && !in_array($t['transfer_status'], ['draft', 'rejected'], true)) {
            throw new DomainRuleException('Only drafts and rejected transfers can be edited.');
        }
        $ws = new WarehouseService($this->db);
        $from = $ws->find((int) ($data['from_warehouse_id'] ?? 0));
        $to = $ws->find((int) ($data['to_warehouse_id'] ?? 0));
        if ($from === null) {
            throw ValidationException::withMessage('from_warehouse_id', 'Choose the source warehouse.');
        }
        if ($to === null) {
            throw ValidationException::withMessage('to_warehouse_id', 'Choose the destination warehouse.');
        }
        WarehouseService::assertUsable($from);
        WarehouseService::assertUsable($to);
        if (!InventoryScope::allows($actor, (int) $from['id']) || !InventoryScope::allows($actor, (int) $to['id'])) {
            throw ValidationException::withMessage('to_warehouse_id', 'You can only transfer between warehouses you have access to.');
        }
        $same = (int) $from['id'] === (int) $to['id'];
        $date = trim((string) ($data['transfer_date'] ?? ''));
        if (!BusinessDate::isValid($date) || $date > BusinessDate::today()) {
            throw ValidationException::withMessage('transfer_date', 'Enter a valid date that is not in the future.');
        }
        $stock = new StockLineService($this->db);
        $locations = new LocationService($this->db);
        $lines = [];
        $n = 0;
        foreach ((array) ($data['lines'] ?? []) as $raw) {
            if (!is_array($raw) || ((int) ($raw['product_id'] ?? 0) === 0 && trim((string) ($raw['quantity'] ?? '')) === '')) {
                continue;
            }
            $n++;
            $raw['location_id'] = $raw['from_location_id'] ?? null;
            $l = $stock->normalize($raw, $from, 'out', $n);
            if ($l['batch_no'] !== null) {
                $b = $this->db->first('SELECT id FROM inventory_batches WHERE batch_key = ?', [StockLineService::batchKey($l['product_id'], $l['variant_id'], $l['batch_no'])]);
                if ($b === null) {
                    throw ValidationException::withMessage("lines.{$n}.batch_no", "Line {$n}: batch {$l['batch_no']} is not recorded for this item.");
                }
                $l['batch_id'] = (int) $b['id'];
            }
            try {
                $l['to_location_id'] = $locations->resolve((int) $to['id'], $raw['to_location_id'] ?? null, (int) $to['uses_locations'] === 1 || $same, "lines.{$n}.to_location_id");
            } catch (ValidationException $e) {
                throw ValidationException::withMessage("lines.{$n}.to_location_id", "Line {$n}: " . (array_values($e->errors)[0][0] ?? 'choose the destination location.'));
            }
            if ($same) {
                if ($l['location_id'] === null) {
                    throw ValidationException::withMessage("lines.{$n}.from_location_id", "Line {$n}: choose the location the stock moves from.");
                }
                if ($l['location_id'] === $l['to_location_id']) {
                    throw ValidationException::withMessage("lines.{$n}.to_location_id", "Line {$n}: stock cannot be transferred to the same location.");
                }
            }
            $lines[$n] = $l;
        }
        if ($lines === []) {
            throw ValidationException::withMessage('lines', 'Add at least one item.');
        }
        $header = ['from_warehouse_id' => (int) $from['id'], 'to_warehouse_id' => (int) $to['id'], 'transfer_date' => $date,
            'uses_transit' => !$same && (bool) App::settings()->get('inventory_transfer_in_transit', true) ? 1 : 0,
            'reason' => mb_substr(trim((string) ($data['reason'] ?? '')), 0, 255) ?: null, 'notes' => mb_substr(trim((string) ($data['notes'] ?? '')), 0, 5000) ?: null];
        $id = $this->db->transaction(function () use ($t, $header, $lines, $actor): int {
            if ($t === null) {
                $id = $this->db->insert('inventory_transfers', $header + ['transfer_no' => InventoryNumbers::next($this->db, 'transfer'), 'transfer_status' => 'draft',
                    'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $id = (int) $t['id'];
                $this->db->update('inventory_transfers', $header + ['transfer_status' => 'draft', 'rejection_reason' => null, 'posting_error' => null, 'approval_request_id' => null,
                    'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $id]);
                $this->db->execute('DELETE FROM inventory_transfer_items WHERE transfer_id = ?', [$id]);
            }
            foreach ($lines as $n => $l) {
                $this->db->insert('inventory_transfer_items', ['transfer_id' => $id, 'line_no' => $n, 'product_id' => $l['product_id'], 'variant_id' => $l['variant_id'],
                    'from_location_id' => $l['location_id'], 'to_location_id' => $l['to_location_id'], 'batch_id' => $l['batch_id'],
                    'serial_numbers' => $l['serials'] !== [] ? implode("\n", $l['serials']) : null, 'quantity' => Qty::toDb($l['base_quantity']), 'notes' => $l['notes']]);
            }
            InventoryHistory::write($this->db, 'transfer', $id, $t === null ? 'created' : 'edited', null, ['lines' => count($lines), 'from' => $header['from_warehouse_id'], 'to' => $header['to_warehouse_id']],
                null, $actor, $header['from_warehouse_id'], to: 'draft');
            return $id;
        });
        $tr = $this->find($id);
        App::activity()->log('inventory.transfer_' . ($t === null ? 'created' : 'edited'), ($t === null ? 'Created' : 'Edited') . " transfer {$tr['transfer_no']} ({$tr['from_code']} → {$tr['to_code']})",
            'inventory_transfer', $id, module: 'inventory', newValues: self::auditView($tr) + ['lines' => count($lines)]);
        return $id;
    }

    /** @param array<string, mixed> $t @param array<string, mixed> $actor @return array<string, mixed> */
    public function submit(array $t, array $actor): array
    {
        if (!in_array($t['transfer_status'], ['draft', 'rejected'], true)) {
            throw new DomainRuleException('Only drafts can be submitted.');
        }
        $this->checkAvailability($t, false);
        $changed = $this->db->execute("UPDATE inventory_transfers SET transfer_status = 'pending_approval', updated_at = ? WHERE id = ? AND transfer_status IN ('draft', 'rejected')", [now(), $t['id']]);
        if ($changed !== 1) {
            throw new DomainRuleException('This transfer was changed by someone else. Please refresh.');
        }
        InventoryHistory::write($this->db, 'transfer', (int) $t['id'], 'submitted', null, null, null, $actor, (int) $t['from_warehouse_id'], (string) $t['transfer_status'], 'pending_approval');
        try {
            $req = App::approvals()->submit('inventory_transfer', self::SUBJECT, (int) $t['id'], 0, "Stock transfer {$t['transfer_no']} ({$t['from_code']} → {$t['to_code']})", $actor, 'inventory.manage_stock_transfers');
        } catch (\Throwable $e) {
            $this->db->update('inventory_transfers', ['transfer_status' => $t['transfer_status'], 'updated_at' => now()], ['id' => $t['id']]);
            throw $e;
        }
        $this->db->update('inventory_transfers', ['approval_request_id' => (int) $req['id']], ['id' => $t['id']]);
        App::activity()->log('inventory.transfer_submitted', "Submitted transfer {$t['transfer_no']}", 'inventory_transfer', (int) $t['id'], module: 'inventory', newValues: ['request' => (int) $req['id']]);
        $fresh = $this->find((int) $t['id']);
        if ($fresh['transfer_status'] === 'approved' && $fresh['posting_error'] !== null) {
            throw new DomainRuleException('Approved, but not dispatched: ' . $fresh['posting_error']);
        }
        return $fresh;
    }

    /** @param array<string, mixed> $request */
    public function applyDecision(array $request, string $status): void
    {
        $t = $this->find((int) $request['subject_id']);
        if ($t === null || $t['transfer_status'] !== 'pending_approval') {
            return;
        }
        $last = $this->db->first('SELECT acted_by, comment FROM approval_actions WHERE request_id = ? ORDER BY id DESC LIMIT 1', [$request['id']]);
        $by = ['id' => $last !== null ? (int) $last['acted_by'] : (int) $t['created_by']];
        if ($status === 'approved') {
            $this->db->update('inventory_transfers', ['transfer_status' => 'approved', 'approved_by' => $by['id'], 'approved_at' => now(), 'updated_at' => now()], ['id' => $t['id']]);
            InventoryHistory::write($this->db, 'transfer', (int) $t['id'], 'approved', null, null, $last['comment'] ?? null, $by, (int) $t['from_warehouse_id'], 'pending_approval', 'approved');
            try {
                $this->dispatch($this->find((int) $t['id']), $by);
            } catch (DomainRuleException | ValidationException) {
                // stays approved with the reason; dispatch can be retried
            }
            return;
        }
        $to = $status === 'rejected' ? 'rejected' : 'draft';
        $this->db->update('inventory_transfers', ['transfer_status' => $to, 'rejection_reason' => $status === 'rejected' ? mb_substr((string) ($last['comment'] ?? 'Rejected'), 0, 500) : null,
            'approval_request_id' => null, 'updated_at' => now()], ['id' => $t['id']]);
        InventoryHistory::write($this->db, 'transfer', (int) $t['id'], $status === 'rejected' ? 'rejected' : 'withdrawn', null, null, $last['comment'] ?? null, $by, (int) $t['from_warehouse_id'], 'pending_approval', $to);
    }

    /**
     * Move the stock out of the source: into In transit, or straight to the destination.
     * @param array<string, mixed> $t @param array<string, mixed> $actor
     */
    public function dispatch(array $t, array $actor): void
    {
        if ($t['transfer_status'] !== 'approved') {
            throw new DomainRuleException('Only approved transfers can be dispatched.');
        }
        $transit = (int) $t['uses_transit'] === 1;
        try {
            $this->db->transaction(function () use ($t, $actor, $transit): void {
                $changed = $this->db->execute("UPDATE inventory_transfers SET transfer_status = ?, dispatched_by = ?, dispatched_at = ?, received_at = ?, posting_error = NULL, updated_at = ? WHERE id = ? AND transfer_status = 'approved'",
                    [$transit ? 'in_transit' : 'received', (int) $actor['id'], now(), $transit ? null : now(), now(), $t['id']]);
                if ($changed !== 1) {
                    throw new DomainRuleException('This transfer has already been dispatched.');
                }
                $this->postDispatch($t, $actor, $transit);
            });
        } catch (DomainRuleException | ValidationException $e) {
            $msg = $e instanceof ValidationException ? (array_values($e->errors)[0][0] ?? $e->getMessage()) : $e->getMessage();
            if ($msg !== 'This transfer has already been dispatched.') {
                $this->db->update('inventory_transfers', ['posting_error' => mb_substr($msg, 0, 500), 'updated_at' => now()], ['id' => $t['id']]);
                InventoryHistory::write($this->db, 'transfer', (int) $t['id'], 'dispatch_failed', null, null, $msg, $actor, (int) $t['from_warehouse_id']);
            }
            throw $e;
        }
        InventoryHistory::write($this->db, 'transfer', (int) $t['id'], 'dispatched', null, ['in_transit' => $transit], null, $actor, (int) $t['from_warehouse_id'], 'approved', $transit ? 'in_transit' : 'received', (string) $t['transfer_date']);
        App::activity()->log('inventory.transfer_dispatched', ($transit ? 'Dispatched' : 'Moved') . " transfer {$t['transfer_no']}", 'inventory_transfer', (int) $t['id'], module: 'inventory', userId: (int) $actor['id']);
    }

    /**
     * Record goods arriving at the destination. $lines: transfer_item_id => [received, damaged, serial_numbers, to_location_id].
     * @param array<string, mixed> $t @param array<int|string, array<string, mixed>> $lines @param array<string, mixed> $actor
     */
    public function receive(array $t, array $lines, string $date, string $notes, array $actor): int
    {
        if (!in_array($t['transfer_status'], ['in_transit', 'partially_received'], true)) {
            throw new DomainRuleException('Only transfers in transit can be received.');
        }
        if (!BusinessDate::isValid($date) || $date > BusinessDate::today() || $date < $t['transfer_date']) {
            throw ValidationException::withMessage('receipt_date', 'Enter a valid receipt date between the transfer date and today.');
        }
        return $this->recordArrival($t, $lines, $date, $notes, $actor, 'receipt');
    }

    /**
     * Write off stock lost or destroyed in transit (an authorised adjustment). $lines: transfer_item_id => [lost, serial_numbers].
     * @param array<string, mixed> $t @param array<int|string, array<string, mixed>> $lines @param array<string, mixed> $actor
     */
    public function writeOffLoss(array $t, array $lines, string $reason, array $actor): int
    {
        if (!in_array($t['transfer_status'], ['in_transit', 'partially_received'], true)) {
            throw new DomainRuleException('Only transfers in transit can have a loss written off.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        return $this->recordArrival($t, $lines, BusinessDate::today(), $reason, $actor, 'loss');
    }

    /** @param array<string, mixed> $t @param array<string, mixed> $actor */
    public function cancel(array $t, string $reason, array $actor): void
    {
        if (!in_array($t['transfer_status'], ['draft', 'rejected', 'pending_approval', 'approved'], true)) {
            throw new DomainRuleException('Only transfers that are not dispatched can be cancelled.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        if ($t['transfer_status'] === 'pending_approval' && $t['approval_request_id'] !== null) {
            $this->db->execute("UPDATE approval_requests SET status = 'cancelled', completed_at = ?, updated_at = ? WHERE id = ? AND status = 'pending'", [now(), now(), $t['approval_request_id']]);
            $this->db->insert('approval_actions', ['request_id' => $t['approval_request_id'], 'step_order' => null, 'action' => 'cancelled', 'acted_by' => (int) $actor['id'], 'comment' => mb_substr($reason, 0, 500), 'created_at' => now()]);
        }
        $changed = $this->db->execute("UPDATE inventory_transfers SET transfer_status = 'cancelled', rejection_reason = ?, updated_by = ?, updated_at = ? WHERE id = ? AND transfer_status = ?",
            [mb_substr($reason, 0, 500), (int) $actor['id'], now(), $t['id'], $t['transfer_status']]);
        if ($changed !== 1) {
            throw new DomainRuleException('This transfer was changed by someone else. Please refresh.');
        }
        InventoryHistory::write($this->db, 'transfer', (int) $t['id'], 'cancelled', null, null, $reason, $actor, (int) $t['from_warehouse_id'], (string) $t['transfer_status'], 'cancelled');
        App::activity()->log('inventory.transfer_cancelled', "Cancelled transfer {$t['transfer_no']}", 'inventory_transfer', (int) $t['id'], module: 'inventory', newValues: ['reason' => $reason]);
    }

    /**
     * Reverse a fully received transfer: stock goes back to the source (the destination must still hold it).
     * @param array<string, mixed> $t @param array<string, mixed> $actor
     */
    public function reverse(array $t, string $reason, array $actor): void
    {
        if ($t['transfer_status'] !== 'received') {
            throw new DomainRuleException('Only fully received transfers can be reversed. Receive or write off what is still in transit first.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        foreach ($this->items((int) $t['id']) as $i) {
            if (Qty::fromDb($i['qty_lost']) > 0) {
                throw new DomainRuleException('Part of this transfer was written off as lost in transit, so it cannot be reversed as a whole. Create a new transfer to send the received stock back.');
            }
        }
        $this->db->transaction(function () use ($t, $reason, $actor): void {
            $changed = $this->db->execute("UPDATE inventory_transfers SET transfer_status = 'reversed', reversed_by = ?, reversed_at = ?, reversal_reason = ?, updated_at = ? WHERE id = ? AND transfer_status = 'received'",
                [(int) $actor['id'], now(), mb_substr($reason, 0, 500), now(), $t['id']]);
            if ($changed !== 1) {
                throw new DomainRuleException('This transfer has already been reversed.');
            }
            $ledger = new InventoryLedger($this->db);
            $moves = $this->db->select("SELECT * FROM inventory_stock_movements WHERE source_type = 'transfer' AND source_id = ? AND reversal_of_id IS NULL AND movement_type <> 'reversal' ORDER BY id DESC", [$t['id']]);
            $ledger->lock(array_map(static fn (array $m): int => (int) $m['stock_item_id'], $moves));
            foreach ($moves as $m) {
                $ledger->reverse($m, ['actor' => $actor, 'date' => BusinessDate::today(), 'reason' => $reason, 'source_type' => 'transfer', 'source_id' => (int) $t['id']]);
            }
            InventoryHistory::write($this->db, 'transfer', (int) $t['id'], 'reversed', null, ['movements' => count($moves)], $reason, $actor, (int) $t['from_warehouse_id'], 'received', 'reversed');
        });
        App::activity()->log('inventory.transfer_reversed', "Reversed transfer {$t['transfer_no']}", 'inventory_transfer', (int) $t['id'], module: 'inventory', newValues: ['reason' => $reason]);
    }

    // ---------------------------------------------------------------- posting

    /** @param array<string, mixed> $t @param array<string, mixed> $actor */
    private function postDispatch(array $t, array $actor, bool $transit): void
    {
        $from = (new WarehouseService($this->db))->find((int) $t['from_warehouse_id']);
        $to = (new WarehouseService($this->db))->find((int) $t['to_warehouse_id']);
        WarehouseService::assertUsable($from);
        WarehouseService::assertUsable($to);
        $ledger = new InventoryLedger($this->db);
        $stock = new StockLineService($this->db);
        $rows = $this->items((int) $t['id']);
        $srcItems = [];
        $dstItems = [];
        foreach ($rows as $r) {
            $v = $r['variant_id'] !== null ? (int) $r['variant_id'] : null;
            $srcItems[(int) $r['id']] = $ledger->item((int) $from['id'], (int) $r['product_id'], $v);
            $dstItems[(int) $r['id']] = $ledger->item((int) $to['id'], (int) $r['product_id'], $v);
        }
        $ledger->lock(array_merge(array_map(static fn (array $i): int => (int) $i['id'], $srcItems), $transit ? [] : array_map(static fn (array $i): int => (int) $i['id'], $dstItems)));
        $this->checkAvailability($t, true);
        $allowExpired = !(bool) App::settings()->get('inventory_block_expired_dispatch', true);
        $base = ['source_type' => 'transfer', 'source_id' => (int) $t['id'], 'reference_no' => (string) $t['transfer_no'], 'date' => (string) $t['transfer_date'], 'actor' => $actor,
            'approver_id' => $t['approved_by'] !== null ? (int) $t['approved_by'] : (int) $actor['id']];
        foreach ($rows as $r) {
            $iid = (int) $r['id'];
            $cfg = (new ProductInventoryService($this->db))->assertStockable((int) $r['product_id'], $r['variant_id'] !== null ? (int) $r['variant_id'] : null, 'out');
            $qty = Qty::fromDb($r['quantity']);
            $serials = StockLineService::serialList((string) ($r['serial_numbers'] ?? ''));
            $chunks = $cfg['serial_tracking']
                ? $stock->serialAllocation((int) $r['product_id'], $r['variant_id'] !== null ? (int) $r['variant_id'] : null, (int) $from['id'], 'sellable', $serials,
                    $r['from_location_id'] !== null ? (int) $r['from_location_id'] : null, $r['batch_id'] !== null ? (int) $r['batch_id'] : null)
                : $stock->allocate((int) $srcItems[$iid]['id'], 'sellable', $qty, $r['from_location_id'] !== null ? (int) $r['from_location_id'] : null,
                    $r['batch_id'] !== null ? (int) $r['batch_id'] : null, $allowExpired, false);
            $value = 0;
            foreach ($chunks as $k => $ch) {
                $seq = $k + 1;
                $out = $ledger->post($base + ['item' => $srcItems[$iid], 'dir' => 'out', 'type' => $transit ? 'transit_out' : ((int) $from['id'] === (int) $to['id'] ? 'location_move' : 'transfer_out'), 'qty' => $ch['qty'], 'state' => 'sellable',
                    'location_id' => $ch['location_id'], 'batch_id' => $ch['batch_id'], 'source_line_id' => $iid, 'posting_key' => "transfer:{$t['id']}:{$iid}:d{$seq}:out",
                    'serial_ids' => $ch['serial_ids'] ?? [], 'unit_label' => $cfg['unit_name']]);
                if ($transit) {
                    $ledger->post($base + ['item' => $srcItems[$iid], 'dir' => 'in', 'type' => 'transit_in', 'qty' => $ch['qty'], 'state' => 'in_transit', 'location_id' => null,
                        'batch_id' => $ch['batch_id'], 'value' => $out['value'], 'source_line_id' => $iid, 'posting_key' => "transfer:{$t['id']}:{$iid}:d{$seq}:in", 'pair_id' => (int) $out['id'],
                        'serial_ids' => $ch['serial_ids'] ?? [], 'serial_after' => isset($ch['serial_ids']) ? ['status' => 'in_transit', 'location_id' => null, 'stock_state' => 'in_transit'] : null]);
                } else {
                    $ledger->post($base + ['item' => $dstItems[$iid], 'dir' => 'in', 'type' => (int) $from['id'] === (int) $to['id'] ? 'location_move' : 'transfer_in', 'qty' => $ch['qty'],
                        'state' => 'sellable', 'location_id' => $r['to_location_id'] !== null ? (int) $r['to_location_id'] : null, 'batch_id' => $ch['batch_id'],
                        'chunks' => (int) $from['id'] === (int) $to['id'] ? null : $out['chunks'], 'value' => (int) $from['id'] === (int) $to['id'] ? $out['value'] : null,
                        'source_line_id' => $iid, 'posting_key' => "transfer:{$t['id']}:{$iid}:d{$seq}:in", 'pair_id' => (int) $out['id'], 'serial_ids' => $ch['serial_ids'] ?? [],
                        'serial_after' => isset($ch['serial_ids']) ? ['status' => 'in_stock', 'warehouse_id' => (int) $to['id'], 'location_id' => $r['to_location_id'], 'stock_state' => 'sellable'] : null]);
                }
                $value += $out['value'];
            }
            $this->db->update('inventory_transfer_items', ['total_cost' => Money::toDb($value)] + ($transit ? [] : ['qty_received' => Qty::toDb($qty)]), ['id' => $iid]);
        }
    }

    /**
     * @param array<string, mixed> $t @param array<int|string, array<string, mixed>> $input @param array<string, mixed> $actor
     */
    private function recordArrival(array $t, array $input, string $date, string $notes, array $actor, string $kind): int
    {
        $rows = [];
        foreach ($this->items((int) $t['id']) as $r) {
            $rows[(int) $r['id']] = $r;
        }
        $plan = [];
        foreach ($input as $itemId => $in) {
            $r = $rows[(int) $itemId] ?? null;
            if ($r === null || !is_array($in)) {
                continue;
            }
            $cfg = (new ProductInventoryService($this->db))->config((int) $r['product_id']);
            $outstanding = Qty::fromDb($r['quantity']) - Qty::fromDb($r['qty_received']) - Qty::fromDb($r['qty_damaged']) - Qty::fromDb($r['qty_lost']);
            $got = $kind === 'receipt' ? (Qty::parse((string) ($in['received'] ?? '0') ?: '0', $cfg['decimals']) ?? -1) : 0;
            $dam = $kind === 'receipt' ? (Qty::parse((string) ($in['damaged'] ?? '0') ?: '0', $cfg['decimals']) ?? -1) : 0;
            $lost = $kind === 'loss' ? (Qty::parse((string) ($in['lost'] ?? '0') ?: '0', $cfg['decimals']) ?? -1) : 0;
            if ($got < 0 || $dam < 0 || $lost < 0) {
                throw ValidationException::withMessage("lines.{$r['line_no']}.received", "Line {$r['line_no']}: enter quantities of 0 or more.");
            }
            if ($got + $dam + $lost === 0) {
                continue;
            }
            if ($got + $dam + $lost > $outstanding) {
                throw ValidationException::withMessage("lines.{$r['line_no']}.received", "Line {$r['line_no']}: only " . Qty::format($outstanding) . " {$cfg['unit_name']} are still in transit.");
            }
            $serials = StockLineService::serialList((string) ($in['serial_numbers'] ?? ''));
            if ($cfg['serial_tracking'] && count($serials) !== intdiv($got + $dam + $lost, Qty::SCALE)) {
                throw ValidationException::withMessage("lines.{$r['line_no']}.serial_numbers", "Line {$r['line_no']}: list the serial numbers of the " . Qty::format($got + $dam + $lost) . ' units (damaged ones last).');
            }
            $loc = null;
            if ($kind === 'receipt') {
                $loc = (new LocationService($this->db))->resolve((int) $t['to_warehouse_id'], ($in['to_location_id'] ?? '') !== '' ? $in['to_location_id'] : $r['to_location_id'],
                    (int) $t['to_uses_locations'] === 1, "lines.{$r['line_no']}.to_location_id");
            }
            $plan[(int) $itemId] = ['row' => $r, 'cfg' => $cfg, 'got' => $got, 'dam' => $dam, 'lost' => $lost, 'serials' => $serials, 'loc' => $loc];
        }
        if ($plan === []) {
            throw ValidationException::withMessage('lines', $kind === 'receipt' ? 'Enter what arrived.' : 'Enter the quantity lost.');
        }
        $receiptId = $this->db->transaction(function () use ($t, $plan, $date, $notes, $actor, $kind): int {
            $fresh = $this->db->first('SELECT transfer_status FROM inventory_transfers WHERE id = ?', [$t['id']]);
            if (!in_array($fresh['transfer_status'] ?? '', ['in_transit', 'partially_received'], true)) {
                throw new DomainRuleException('This transfer is no longer in transit.');
            }
            $ledger = new InventoryLedger($this->db);
            $stock = new StockLineService($this->db);
            $srcItems = [];
            $dstItems = [];
            foreach ($plan as $iid => $p) {
                $v = $p['row']['variant_id'] !== null ? (int) $p['row']['variant_id'] : null;
                $srcItems[$iid] = $ledger->item((int) $t['from_warehouse_id'], (int) $p['row']['product_id'], $v);
                $dstItems[$iid] = $ledger->item((int) $t['to_warehouse_id'], (int) $p['row']['product_id'], $v);
            }
            $ledger->lock(array_merge(array_map(static fn (array $i): int => (int) $i['id'], $srcItems), array_map(static fn (array $i): int => (int) $i['id'], $dstItems)));
            $no = InventoryNumbers::next($this->db, 'receipt');
            $rid = $this->db->insert('inventory_transfer_receipts', ['transfer_id' => (int) $t['id'], 'receipt_no' => $no, 'kind' => $kind, 'receipt_date' => $date,
                'notes' => mb_substr($notes, 0, 500) ?: null, 'created_by' => (int) $actor['id'], 'created_at' => now()]);
            $base = ['source_type' => 'transfer', 'source_id' => (int) $t['id'], 'reference_no' => (string) $t['transfer_no'], 'date' => $date, 'actor' => $actor, 'approver_id' => (int) $actor['id']];
            foreach ($plan as $iid => $p) {
                $r = $p['row'];
                $serialsLeft = $p['serials'];
                foreach (['sellable' => $p['got'], 'damaged' => $p['dam'], 'lost' => $p['lost']] as $target => $qty) {
                    if ($qty <= 0) {
                        continue;
                    }
                    $take = $p['cfg']['serial_tracking'] ? array_splice($serialsLeft, 0, intdiv($qty, Qty::SCALE)) : [];
                    $chunks = $p['cfg']['serial_tracking']
                        ? $stock->serialAllocation((int) $r['product_id'], $r['variant_id'] !== null ? (int) $r['variant_id'] : null, (int) $t['from_warehouse_id'], 'in_transit', $take, null, null, 'in_transit')
                        : $stock->allocate((int) $srcItems[$iid]['id'], 'in_transit', $qty, null, $r['batch_id'] !== null ? (int) $r['batch_id'] : null, true, false);
                    foreach ($chunks as $k => $ch) {
                        $key = "transfer:{$t['id']}:{$iid}:r{$rid}:{$target}:" . ($k + 1);
                        $out = $ledger->post($base + ['item' => $srcItems[$iid], 'dir' => 'out', 'type' => $target === 'lost' ? 'transit_loss' : 'transfer_out', 'qty' => $ch['qty'], 'state' => 'in_transit',
                            'location_id' => null, 'batch_id' => $ch['batch_id'], 'source_line_id' => $iid, 'posting_key' => $key . ':out', 'serial_ids' => $ch['serial_ids'] ?? [],
                            'serial_after' => $target === 'lost' && isset($ch['serial_ids']) ? ['status' => 'written_off', 'warehouse_id' => null, 'location_id' => null, 'stock_state' => null] : null,
                            'notes' => $target === 'lost' ? mb_substr('Lost in transit: ' . $notes, 0, 255) : null]);
                        if ($target === 'lost') {
                            continue;
                        }
                        $ledger->post($base + ['item' => $dstItems[$iid], 'dir' => 'in', 'type' => 'transfer_in', 'qty' => $ch['qty'], 'state' => $target, 'location_id' => $p['loc'],
                            'batch_id' => $ch['batch_id'], 'chunks' => $out['chunks'], 'source_line_id' => $iid, 'posting_key' => $key . ':in', 'pair_id' => (int) $out['id'],
                            'serial_ids' => $ch['serial_ids'] ?? [], 'serial_after' => isset($ch['serial_ids']) ? ['status' => 'in_stock', 'warehouse_id' => (int) $t['to_warehouse_id'], 'location_id' => $p['loc'], 'stock_state' => $target] : null]);
                    }
                }
                $this->db->insert('inventory_transfer_receipt_items', ['receipt_id' => $rid, 'transfer_item_id' => $iid, 'qty_received' => Qty::toDb($p['got']), 'qty_damaged' => Qty::toDb($p['dam']),
                    'qty_lost' => Qty::toDb($p['lost']), 'serial_numbers' => $p['serials'] !== [] ? implode("\n", $p['serials']) : null]);
                $this->db->update('inventory_transfer_items', ['qty_received' => Qty::toDb(Qty::fromDb($r['qty_received']) + $p['got']), 'qty_damaged' => Qty::toDb(Qty::fromDb($r['qty_damaged']) + $p['dam']),
                    'qty_lost' => Qty::toDb(Qty::fromDb($r['qty_lost']) + $p['lost'])], ['id' => $iid]);
            }
            $open = false;
            foreach ($this->items((int) $t['id']) as $r) {
                if (Qty::fromDb($r['quantity']) > Qty::fromDb($r['qty_received']) + Qty::fromDb($r['qty_damaged']) + Qty::fromDb($r['qty_lost'])) {
                    $open = true;
                }
            }
            $status = $open ? 'partially_received' : 'received';
            $this->db->update('inventory_transfers', ['transfer_status' => $status, 'received_at' => $open ? null : now(), 'updated_at' => now()], ['id' => $t['id']]);
            InventoryHistory::write($this->db, 'transfer', (int) $t['id'], $kind === 'loss' ? 'loss_written_off' : 'received', null, ['receipt' => $no, 'lines' => count($plan)], $notes ?: null, $actor,
                (int) $t['to_warehouse_id'], (string) $t['transfer_status'], $status, $date);
            return $rid;
        });
        App::activity()->log($kind === 'loss' ? 'inventory.transfer_loss_written_off' : 'inventory.transfer_received', ($kind === 'loss' ? 'Wrote off transit loss on ' : 'Received goods on ') . "transfer {$t['transfer_no']}",
            'inventory_transfer', (int) $t['id'], module: 'inventory', newValues: ['receipt_id' => $receiptId, 'notes' => $notes]);
        return $receiptId;
    }

    /** Enough free stock in the source for every line (sellable, not reserved for others). @param array<string, mixed> $t */
    private function checkAvailability(array $t, bool $locked): void
    {
        $ledger = new InventoryLedger($this->db);
        $stock = new StockLineService($this->db);
        (new ReservationService($this->db))->expireDue();
        $need = [];
        $names = [];
        foreach ($this->items((int) $t['id']) as $r) {
            $item = $ledger->item((int) $t['from_warehouse_id'], (int) $r['product_id'], $r['variant_id'] !== null ? (int) $r['variant_id'] : null);
            $need[(int) $item['id']] = ($need[(int) $item['id']] ?? 0) + Qty::fromDb($r['quantity']);
            $names[(int) $item['id']] = $r['product_name'] . ($r['variant_name'] ? ' — ' . $r['variant_name'] : '');
        }
        $allowExpired = !(bool) App::settings()->get('inventory_block_expired_dispatch', true);
        foreach ($need as $iid => $qty) {
            $item = $ledger->freshItem($iid);
            $free = $stock->issuable($iid, $allowExpired) - $ledger->reserved((int) $item['warehouse_id'], (int) $item['product_id'], $item['variant_id'] !== null ? (int) $item['variant_id'] : null);
            if ($qty > $free) {
                throw new DomainRuleException("Not enough free stock of {$names[$iid]} in {$t['from_name']}: " . Qty::format(max(0, $free)) . ' available, ' . Qty::format($qty) . ' to transfer.');
            }
        }
    }

    /** @param array<string, mixed> $t @return array<string, mixed> */
    public static function auditView(array $t): array
    {
        return array_intersect_key($t, array_flip(['transfer_no', 'from_warehouse_id', 'to_warehouse_id', 'transfer_date', 'uses_transit', 'transfer_status', 'reason']));
    }
}
