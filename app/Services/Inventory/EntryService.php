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
 * Opening stock, stock inward and stock outward documents.
 *
 * Draft → submit → (approval when the workflow is active) → posted. Posting is one database
 * transaction: the status flips approved → posted with a conditional UPDATE (so a second request
 * cannot post again), the stock items are locked, stock is checked and the ledger rows written.
 * Posted documents are never edited or deleted; reverse() posts opposite movements.
 *
 * Purchase receipts, sales dispatches and customer returns carry an optional link to the future
 * source document (Phase 11 / 9 / 10). No purchase, sale, payment or journal entry is created.
 */
final class EntryService
{
    public const SUBJECT = 'inventory_entry';
    public const DIRECTIONS = ['opening' => 'Opening stock', 'inward' => 'Stock inward', 'outward' => 'Stock outward'];
    public const TYPES = [
        'opening' => ['opening' => 'Opening stock'],
        'inward' => ['purchase_receipt' => 'Purchase receipt', 'customer_return' => 'Customer return', 'other_receipt' => 'Other authorised receipt'],
        'outward' => ['sales_dispatch' => 'Sales dispatch', 'supplier_return' => 'Supplier return', 'damaged' => 'Damaged stock write-off', 'expired' => 'Expired stock write-off',
            'consumption' => 'Internal consumption', 'other_issue' => 'Other authorised issue'],
    ];
    /** Later phases that will create these documents themselves. */
    public const FUTURE_SOURCE = ['purchase_receipt' => ['purchase_receipt', 'Phase 11 — Purchases'], 'customer_return' => ['customer_return', 'Phase 10 — Returns'], 'sales_dispatch' => ['sales_order', 'Phase 9 — Sales & Orders'],
        'supplier_return' => ['purchase_return', 'Phase 11 — Purchases']];
    public const STATUSES = ['draft' => 'Draft', 'pending_approval' => 'Pending approval', 'approved' => 'Approved — not posted', 'posted' => 'Posted', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'reversed' => 'Reversed'];
    public const RETURN_CONDITIONS = ['sellable' => 'Sellable', 'damaged' => 'Damaged', 'inspection' => 'Inspection required', 'restock_pending' => 'Restock pending', 'rejected' => 'Rejected — not taken into stock'];
    public const CONDITION_STATE = ['sellable' => 'sellable', 'damaged' => 'damaged', 'inspection' => 'quarantine', 'restock_pending' => 'quarantine'];
    public const PERMISSIONS = ['opening' => 'manage_opening_stock', 'inward' => 'manage_stock_inward', 'outward' => 'manage_stock_outward'];
    public const WORKFLOWS = ['opening' => 'inventory_opening_stock', 'inward' => 'inventory_inward', 'outward' => 'inventory_outward'];
    private const SERIAL_OUT = ['sales_dispatch' => 'dispatched', 'supplier_return' => 'returned_to_supplier', 'damaged' => 'written_off', 'expired' => 'written_off', 'consumption' => 'consumed', 'other_issue' => 'dispatched'];
    private const REASON_REQUIRED = ['other_receipt', 'damaged', 'expired', 'consumption', 'other_issue'];
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
        return $this->db->first('SELECT e.*, w.code AS warehouse_code, w.name AS warehouse_name, w.uses_locations, s.name AS supplier_name, s.supplier_code,
                cu.name AS created_by_name, au.name AS approved_by_name, pu.name AS posted_by_name, ru.name AS reversed_by_name
            FROM inventory_entries e JOIN warehouses w ON w.id = e.warehouse_id LEFT JOIN suppliers s ON s.id = e.supplier_id LEFT JOIN users cu ON cu.id = e.created_by
            LEFT JOIN users au ON au.id = e.approved_by LEFT JOIN users pu ON pu.id = e.posted_by LEFT JOIN users ru ON ru.id = e.reversed_by WHERE e.id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $entryId): array
    {
        return $this->db->select('SELECT i.*, p.name AS product_name, p.product_code, p.sku, p.unit AS stock_unit, v.name AS variant_name, v.sku AS variant_sku, l.code AS location_code,
                b.batch_no AS batch_label, b.expiry_date AS batch_expiry, r.reservation_no
            FROM inventory_entry_items i JOIN products p ON p.id = i.product_id LEFT JOIN product_variants v ON v.id = i.variant_id LEFT JOIN warehouse_locations l ON l.id = i.location_id
            LEFT JOIN inventory_batches b ON b.id = i.batch_id LEFT JOIN inventory_reservation_items ri ON ri.id = i.reservation_item_id LEFT JOIN inventory_reservations r ON r.id = ri.reservation_id
            WHERE i.entry_id = ? ORDER BY i.line_no', [$entryId]);
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f */
    public function paginate(array $user, string $direction, array $f, int $page, int $perPage): Paginator
    {
        [$w, $p] = InventoryScope::sql($user, 'e.warehouse_id');
        $sql = " FROM inventory_entries e JOIN warehouses w ON w.id = e.warehouse_id LEFT JOIN suppliers s ON s.id = e.supplier_id LEFT JOIN users u ON u.id = e.created_by
                 WHERE e.direction = ? AND {$w}";
        $p = array_merge([$direction], $p);
        foreach (['warehouse' => 'e.warehouse_id', 'supplier' => 'e.supplier_id'] as $k => $col) {
            if (($f[$k] ?? '') !== '') {
                $sql .= " AND {$col} = ?";
                $p[] = (int) $f[$k];
            }
        }
        if (($f['status'] ?? '') !== '' && isset(self::STATUSES[$f['status']])) {
            $sql .= ' AND e.status = ?';
            $p[] = $f['status'];
        }
        if (($f['type'] ?? '') !== '' && isset(self::TYPES[$direction][$f['type']])) {
            $sql .= ' AND e.entry_type = ?';
            $p[] = $f['type'];
        }
        if (($f['from'] ?? '') !== '' && BusinessDate::isValid((string) $f['from'])) {
            $sql .= ' AND e.entry_date >= ?';
            $p[] = $f['from'];
        }
        if (($f['to'] ?? '') !== '' && BusinessDate::isValid((string) $f['to'])) {
            $sql .= ' AND e.entry_date <= ?';
            $p[] = $f['to'];
        }
        if (($f['product'] ?? '') !== '') {
            $sql .= ' AND e.id IN (SELECT entry_id FROM inventory_entry_items WHERE product_id = ?)';
            $p[] = (int) $f['product'];
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $sql .= ' AND (e.entry_no LIKE ? OR e.source_reference LIKE ? OR e.reason LIKE ?)';
            array_push($p, "%{$q}%", "%{$q}%", "%{$q}%");
        }
        $total = (int) $this->db->scalar('SELECT COUNT(*)' . $sql, $p);
        $rows = $this->db->select('SELECT e.*, w.code AS warehouse_code, w.name AS warehouse_name, s.name AS supplier_name, u.name AS created_by_name,
                (SELECT COUNT(*) FROM inventory_entry_items i WHERE i.entry_id = e.id) AS line_count,
                (SELECT SUM(i.base_quantity) FROM inventory_entry_items i WHERE i.entry_id = e.id) AS total_qty,
                (SELECT SUM(i.total_cost) FROM inventory_entry_items i WHERE i.entry_id = e.id) AS total_value'
            . $sql . ' ORDER BY e.entry_date DESC, e.id DESC LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage), $p);
        return new Paginator($rows, $total, $page, $perPage);
    }

    /**
     * Create or edit a draft (rejected documents become drafts again when edited).
     * @param array<string, mixed> $data @param array<string, mixed>|null $entry @param array<string, mixed> $actor
     */
    public function save(string $direction, array $data, ?array $entry, array $actor): int
    {
        if (!isset(self::DIRECTIONS[$direction])) {
            throw new DomainRuleException('Unknown document type.');
        }
        if ($entry !== null && !in_array($entry['status'], ['draft', 'rejected'], true)) {
            throw new DomainRuleException('Only drafts and rejected documents can be edited.');
        }
        [$header, $lines] = $this->validate($direction, $data, $actor, $entry);
        $id = $this->db->transaction(function () use ($direction, $header, $lines, $entry, $actor): int {
            $now = now();
            if ($entry === null) {
                $id = $this->db->insert('inventory_entries', $header + ['entry_no' => InventoryNumbers::next($this->db, $direction), 'direction' => $direction, 'status' => 'draft',
                    'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => $now, 'updated_at' => $now]);
            } else {
                $id = (int) $entry['id'];
                $this->db->update('inventory_entries', $header + ['status' => 'draft', 'rejection_reason' => null, 'posting_error' => null, 'approval_request_id' => null,
                    'updated_by' => (int) $actor['id'], 'updated_at' => $now], ['id' => $id]);
                $this->db->execute('DELETE FROM inventory_entry_items WHERE entry_id = ?', [$id]);
            }
            foreach ($lines as $n => $l) {
                $this->db->insert('inventory_entry_items', [
                    'entry_id' => $id, 'line_no' => $n, 'product_id' => $l['product_id'], 'variant_id' => $l['variant_id'], 'location_id' => $l['location_id'], 'batch_id' => $l['batch_id'],
                    'batch_no' => $l['batch_no'], 'mfg_date' => $l['mfg_date'], 'expiry_date' => $l['expiry_date'], 'serial_numbers' => $l['serials'] !== [] ? implode("\n", $l['serials']) : null,
                    'quantity' => Qty::toDb($l['quantity']), 'unit_code' => $l['unit_code'], 'base_quantity' => Qty::toDb($l['base_quantity']),
                    'unit_cost' => $l['unit_cost'] !== null ? Qty::toDb($l['unit_cost']) : null, 'total_cost' => $l['unit_cost'] !== null ? Money::toDb(Qty::value($l['base_quantity'], $l['unit_cost'])) : null,
                    'stock_state' => $l['stock_state'], 'return_condition' => $l['return_condition'], 'reservation_item_id' => $l['reservation_item_id'], 'notes' => $l['notes'],
                ]);
            }
            InventoryHistory::write($this->db, 'entry', $id, $entry === null ? 'created' : 'edited', null, ['lines' => count($lines), 'type' => $header['entry_type']], null, $actor, (int) $header['warehouse_id'], to: 'draft');
            return $id;
        });
        $e = $this->find($id);
        App::activity()->log('inventory.' . $direction . '_' . ($entry === null ? 'created' : 'edited'), ($entry === null ? 'Created ' : 'Edited ') . strtolower(self::DIRECTIONS[$direction]) . " {$e['entry_no']}",
            'inventory_entry', $id, module: 'inventory', newValues: self::auditView($e) + ['lines' => count($lines)]);
        return $id;
    }

    /** @param array<string, mixed> $entry @param array<string, mixed> $actor */
    public function submit(array $entry, array $actor): array
    {
        if (!in_array($entry['status'], ['draft', 'rejected'], true)) {
            throw new DomainRuleException('Only drafts can be submitted.');
        }
        // Re-validate against today's data (products, batches, duplicates).
        $this->validate((string) $entry['direction'], $this->storedAsInput($entry), $actor, $entry);
        $workflow = self::WORKFLOWS[$entry['direction']];
        $permission = 'inventory.' . self::PERMISSIONS[$entry['direction']];
        if ((int) $entry['negative_override'] === 1) {
            [$workflow, $permission] = ['inventory_negative_override', 'inventory.override_stock_rules'];
        } elseif ((int) $entry['reservation_override'] === 1) {
            [$workflow, $permission] = ['inventory_reservation_override', 'inventory.override_stock_rules'];
        }
        $amount = 0;
        foreach ($this->lines((int) $entry['id']) as $l) {
            $amount += Money::fromDb($l['total_cost']);
        }
        $changed = $this->db->execute("UPDATE inventory_entries SET status = 'pending_approval', updated_at = ? WHERE id = ? AND status IN ('draft', 'rejected')", [now(), $entry['id']]);
        if ($changed !== 1) {
            throw new DomainRuleException('This document was changed by someone else. Please refresh.');
        }
        InventoryHistory::write($this->db, 'entry', (int) $entry['id'], 'submitted', null, ['workflow' => $workflow], null, $actor, (int) $entry['warehouse_id'], (string) $entry['status'], 'pending_approval');
        try {
            $req = App::approvals()->submit($workflow, self::SUBJECT, (int) $entry['id'], $amount / 100, self::TYPES[$entry['direction']][$entry['entry_type']] . ' ' . $entry['entry_no'], $actor, $permission);
        } catch (\Throwable $e) {
            $this->db->update('inventory_entries', ['status' => $entry['status'], 'updated_at' => now()], ['id' => $entry['id']]);
            throw $e;
        }
        $this->db->update('inventory_entries', ['approval_request_id' => (int) $req['id']], ['id' => $entry['id']]);
        App::activity()->log('inventory.' . $entry['direction'] . '_submitted', "Submitted {$entry['entry_no']}", 'inventory_entry', (int) $entry['id'], module: 'inventory', newValues: ['workflow' => $workflow, 'request' => (int) $req['id']]);
        $fresh = $this->find((int) $entry['id']);
        if ($fresh['status'] === 'approved' && $fresh['posting_error'] !== null) {
            throw new DomainRuleException('Approved, but not posted: ' . $fresh['posting_error']);
        }
        return $fresh;
    }

    /** Approval decision (listener). Approved documents are posted at once. @param array<string, mixed> $request */
    public function applyDecision(array $request, string $status): void
    {
        $e = $this->find((int) $request['subject_id']);
        if ($e === null || $e['status'] !== 'pending_approval') {
            return;
        }
        $last = $this->db->first('SELECT acted_by, comment FROM approval_actions WHERE request_id = ? ORDER BY id DESC LIMIT 1', [$request['id']]);
        $by = ['id' => $last !== null ? (int) $last['acted_by'] : (int) $e['created_by']];
        if ($status === 'approved') {
            $this->db->update('inventory_entries', ['status' => 'approved', 'approved_by' => $by['id'], 'approved_at' => now(), 'updated_at' => now()], ['id' => $e['id']]);
            InventoryHistory::write($this->db, 'entry', (int) $e['id'], 'approved', null, null, $last['comment'] ?? null, $by, (int) $e['warehouse_id'], 'pending_approval', 'approved');
            try {
                $this->post($this->find((int) $e['id']), $by);
            } catch (DomainRuleException | ValidationException) {
                // kept as "approved — not posted" with the reason; can be posted again later
            }
            return;
        }
        $to = $status === 'rejected' ? 'rejected' : 'draft';
        $this->db->update('inventory_entries', ['status' => $to, 'rejection_reason' => $status === 'rejected' ? mb_substr((string) ($last['comment'] ?? 'Rejected'), 0, 500) : null, 'approval_request_id' => null, 'updated_at' => now()], ['id' => $e['id']]);
        InventoryHistory::write($this->db, 'entry', (int) $e['id'], $status === 'rejected' ? 'rejected' : 'withdrawn', null, null, $last['comment'] ?? null, $by, (int) $e['warehouse_id'], 'pending_approval', $to);
    }

    /**
     * Post an approved document to the stock ledger.
     * @param array<string, mixed> $entry @param array<string, mixed> $actor
     */
    public function post(array $entry, array $actor): void
    {
        if ($entry['status'] !== 'approved') {
            throw new DomainRuleException($entry['status'] === 'posted' ? 'This document has already been posted.' : 'Only approved documents can be posted.');
        }
        try {
            $this->db->transaction(function () use ($entry, $actor): void {
                $changed = $this->db->execute("UPDATE inventory_entries SET status = 'posted', posted_by = ?, posted_at = ?, posting_error = NULL, updated_at = ? WHERE id = ? AND status = 'approved'",
                    [(int) $actor['id'], now(), now(), $entry['id']]);
                if ($changed !== 1) {
                    throw new DomainRuleException('This document has already been posted.');
                }
                $this->postLines($entry, $actor);
            });
        } catch (DomainRuleException | ValidationException $e) {
            $msg = $e instanceof ValidationException ? (array_values($e->errors)[0][0] ?? $e->getMessage()) : $e->getMessage();
            if ($msg !== 'This document has already been posted.') {
                $this->db->update('inventory_entries', ['posting_error' => mb_substr($msg, 0, 500), 'updated_at' => now()], ['id' => $entry['id']]);
                InventoryHistory::write($this->db, 'entry', (int) $entry['id'], 'posting_failed', null, null, $msg, $actor, (int) $entry['warehouse_id']);
            }
            throw $e;
        }
        $fresh = $this->find((int) $entry['id']);
        InventoryHistory::write($this->db, 'entry', (int) $entry['id'], 'posted', null, ['posted_at' => $fresh['posted_at']], null, $actor, (int) $entry['warehouse_id'], 'approved', 'posted', (string) $entry['entry_date']);
        App::activity()->log('inventory.' . $entry['direction'] . '_posted', "Posted {$entry['entry_no']} to the stock ledger", 'inventory_entry', (int) $entry['id'], module: 'inventory',
            newValues: self::auditView($fresh), userId: (int) $actor['id']);
    }

    /** @param array<string, mixed> $entry @param array<string, mixed> $actor */
    public function cancel(array $entry, string $reason, array $actor): void
    {
        if (!in_array($entry['status'], ['draft', 'rejected', 'pending_approval', 'approved'], true)) {
            throw new DomainRuleException('Only documents that are not posted can be cancelled.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        if ($entry['status'] === 'pending_approval' && $entry['approval_request_id'] !== null) {
            $req = $this->db->first('SELECT * FROM approval_requests WHERE id = ?', [$entry['approval_request_id']]);
            if ($req !== null && $req['status'] === 'pending') {
                $this->db->update('approval_requests', ['status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now()], ['id' => $req['id']]);
                $this->db->insert('approval_actions', ['request_id' => $req['id'], 'step_order' => null, 'action' => 'cancelled', 'acted_by' => (int) $actor['id'], 'comment' => mb_substr($reason, 0, 500), 'created_at' => now()]);
            }
        }
        $changed = $this->db->execute("UPDATE inventory_entries SET status = 'cancelled', rejection_reason = ?, updated_by = ?, updated_at = ? WHERE id = ? AND status = ?",
            [mb_substr($reason, 0, 500), (int) $actor['id'], now(), $entry['id'], $entry['status']]);
        if ($changed !== 1) {
            throw new DomainRuleException('This document was changed by someone else. Please refresh.');
        }
        InventoryHistory::write($this->db, 'entry', (int) $entry['id'], 'cancelled', null, null, $reason, $actor, (int) $entry['warehouse_id'], (string) $entry['status'], 'cancelled');
        App::activity()->log('inventory.' . $entry['direction'] . '_cancelled', "Cancelled {$entry['entry_no']}", 'inventory_entry', (int) $entry['id'], module: 'inventory', newValues: ['reason' => $reason]);
    }

    /**
     * Reverse a posted document: every movement it created gets an opposite movement (stock and cost restored).
     * @param array<string, mixed> $entry @param array<string, mixed> $actor
     */
    public function reverse(array $entry, string $reason, array $actor): void
    {
        if ($entry['status'] !== 'posted') {
            throw new DomainRuleException('Only posted documents can be reversed.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $this->db->transaction(function () use ($entry, $reason, $actor): void {
            $changed = $this->db->execute("UPDATE inventory_entries SET status = 'reversed', reversed_by = ?, reversed_at = ?, reversal_reason = ?, updated_at = ? WHERE id = ? AND status = 'posted'",
                [(int) $actor['id'], now(), mb_substr($reason, 0, 500), now(), $entry['id']]);
            if ($changed !== 1) {
                throw new DomainRuleException('This document has already been reversed.');
            }
            $ledger = new InventoryLedger($this->db);
            $moves = $this->db->select("SELECT * FROM inventory_stock_movements WHERE source_type = ? AND source_id = ? AND reversal_of_id IS NULL AND movement_type <> 'reversal' ORDER BY id DESC",
                [$entry['direction'], $entry['id']]);
            $ledger->lock(array_map(static fn (array $m): int => (int) $m['stock_item_id'], $moves));
            foreach ($moves as $m) {
                $ledger->reverse($m, ['actor' => $actor, 'date' => BusinessDate::today(), 'reason' => $reason, 'source_type' => $entry['direction'], 'source_id' => (int) $entry['id']]);
            }
            $res = new ReservationService($this->db);
            foreach ($this->lines((int) $entry['id']) as $l) {
                if ($l['reservation_item_id'] !== null) {
                    $res->unfulfil((int) $l['reservation_item_id'], Qty::fromDb($l['base_quantity']));
                }
            }
            InventoryHistory::write($this->db, 'entry', (int) $entry['id'], 'reversed', null, ['movements' => count($moves)], $reason, $actor, (int) $entry['warehouse_id'], 'posted', 'reversed');
        });
        App::activity()->log('inventory.' . $entry['direction'] . '_reversed', "Reversed {$entry['entry_no']}", 'inventory_entry', (int) $entry['id'], module: 'inventory', newValues: ['reason' => $reason]);
    }

    /**
     * Phase 9 / 10 / 11 hook: link a document to the record in the source module once it exists
     * (each source record can be linked to one document only).
     * @param array<string, mixed> $entry @param array<string, mixed> $actor
     */
    public function linkSource(array $entry, string $sourceType, int $sourceId, array $actor): void
    {
        $expected = self::FUTURE_SOURCE[$entry['entry_type']][0] ?? null;
        if ($expected === null || $expected !== $sourceType) {
            throw new DomainRuleException('This kind of document cannot be linked to that source.');
        }
        if ($entry['source_document_id'] !== null) {
            throw new DomainRuleException('This document is already linked to a source record.');
        }
        try {
            $this->db->update('inventory_entries', ['source_document_type' => $sourceType, 'source_document_id' => $sourceId, 'updated_at' => now()], ['id' => $entry['id']]);
        } catch (\PDOException) {
            throw new DomainRuleException('That source record is already linked to another stock document.');
        }
        InventoryHistory::write($this->db, 'entry', (int) $entry['id'], 'source_linked', null, ['source_type' => $sourceType, 'source_id' => $sourceId], null, $actor, (int) $entry['warehouse_id']);
    }

    // ---------------------------------------------------------------- posting

    /** @param array<string, mixed> $entry @param array<string, mixed> $actor */
    private function postLines(array $entry, array $actor): void
    {
        $wh = (new WarehouseService($this->db))->find((int) $entry['warehouse_id']);
        WarehouseService::assertUsable($wh);
        $stock = new StockLineService($this->db);
        $ledger = new InventoryLedger($this->db);
        $res = new ReservationService($this->db);
        $res->expireDue();
        $rows = $this->lines((int) $entry['id']);
        $norm = [];
        $items = [];
        foreach ($rows as $r) {
            $l = $stock->normalize(StockLineService::storedToInput($r), $wh, $entry['direction'] === 'outward' ? 'out' : 'in', (int) $r['line_no']);
            $norm[(int) $r['line_no']] = [$r, $l];
            $items[(int) $r['line_no']] = $ledger->item((int) $wh['id'], (int) $r['product_id'], $r['variant_id'] !== null ? (int) $r['variant_id'] : null);
        }
        $ledger->lock(array_map(static fn (array $i): int => (int) $i['id'], $items));
        if ($entry['direction'] === 'opening') {
            $this->assertNoDuplicateOpening($entry, $rows, true);
        }
        $date = (string) $entry['entry_date'];
        $base = ['source_type' => $entry['direction'], 'source_id' => (int) $entry['id'], 'reference_no' => (string) $entry['entry_no'], 'date' => $date, 'actor' => $actor,
            'approver_id' => $entry['approved_by'] !== null ? (int) $entry['approved_by'] : (int) $actor['id']];

        if ($entry['direction'] === 'outward') {
            $this->assertIssuable($entry, $norm, $items, $stock, $ledger);
        }
        foreach ($norm as $n => [$r, $l]) {
            $item = $items[$n];
            $c = $l['config'];
            if ($entry['direction'] !== 'outward') {
                if ($r['return_condition'] === 'rejected') {
                    continue; // recorded on the document; not taken into stock
                }
                $state = $r['stock_state'];
                $batchId = $stock->resolveInwardBatch($l, ['supplier_id' => $entry['supplier_id'], 'reference' => $entry['source_reference'], 'entry_type' => $entry['entry_type'], 'actor' => $actor, 'warehouse_id' => $wh['id']]);
                $serialIds = $c['serial_tracking'] ? $stock->registerSerials($l, $batchId, ['actor' => $actor, 'warehouse_id' => $wh['id']]) : [];
                $cost = $l['unit_cost'] ?? (Qty::fromDb($item['qty_on_hand']) > 0 ? Qty::unitCost(Money::fromDb($item['stock_value']), Qty::fromDb($item['qty_on_hand'])) : Qty::fromDb($item['last_unit_cost']));
                $m = $ledger->post($base + ['item' => $item, 'dir' => 'in', 'type' => $entry['entry_type'], 'qty' => $l['base_quantity'], 'state' => $state, 'location_id' => $l['location_id'],
                    'batch_id' => $batchId, 'unit_cost' => $cost, 'source_line_id' => (int) $r['id'], 'posting_key' => "entry:{$entry['id']}:{$n}:1", 'serial_ids' => $serialIds,
                    'serial_after' => ['status' => 'in_stock', 'warehouse_id' => (int) $wh['id'], 'location_id' => $l['location_id'], 'stock_state' => $state, 'batch_id' => $batchId], 'unit_label' => $c['unit_name']]);
                $this->db->update('inventory_entry_items', ['batch_id' => $batchId, 'unit_cost' => Qty::toDb($cost), 'total_cost' => Money::toDb($m['value'])], ['id' => $r['id']]);
                continue;
            }
            // outward
            $state = (string) $r['stock_state'];
            $allowExpired = $entry['entry_type'] === 'expired' || (int) $entry['expired_override'] === 1 || !(bool) App::settings()->get('inventory_block_expired_dispatch', true);
            $allowNeg = $c['allow_negative'] || (int) $entry['negative_override'] === 1;
            $chunks = $c['serial_tracking']
                ? $stock->serialAllocation((int) $r['product_id'], $l['variant_id'], (int) $wh['id'], $state, $l['serials'], $l['location_id'], $l['batch_id'])
                : $stock->allocate((int) $item['id'], $state, $l['base_quantity'], $l['location_id'], $l['batch_id'], $allowExpired, $allowNeg);
            $value = 0;
            foreach ($chunks as $k => $ch) {
                $m = $ledger->post($base + ['item' => $item, 'dir' => 'out', 'type' => $entry['entry_type'], 'qty' => $ch['qty'], 'state' => $state, 'location_id' => $ch['location_id'],
                    'batch_id' => $ch['batch_id'], 'allow_negative' => $allowNeg, 'source_line_id' => (int) $r['id'], 'posting_key' => "entry:{$entry['id']}:{$n}:" . ($k + 1),
                    'serial_ids' => $ch['serial_ids'] ?? [], 'serial_after' => isset($ch['serial_ids']) ? ['status' => self::SERIAL_OUT[$entry['entry_type']], 'warehouse_id' => null, 'location_id' => null, 'stock_state' => null] : null,
                    'unit_label' => $c['unit_name']]);
                $value += $m['value'];
            }
            $this->db->update('inventory_entry_items', ['unit_cost' => Qty::toDb(Qty::unitCost($value, $l['base_quantity'])), 'total_cost' => Money::toDb($value)], ['id' => $r['id']]);
            if ($r['reservation_item_id'] !== null) {
                $res->fulfil((int) $r['reservation_item_id'], $l['base_quantity']);
            }
        }
    }

    /**
     * Before an issue: enough free stock per item (sellable stock not expired / on hold, minus what
     * is reserved for other references), unless a reservation override was approved.
     * @param array<string, mixed> $entry @param array<int, array{0: array<string, mixed>, 1: array<string, mixed>}> $norm @param array<int, array<string, mixed>> $items
     */
    private function assertIssuable(array $entry, array $norm, array $items, StockLineService $stock, InventoryLedger $ledger): void
    {
        $need = [];
        $linked = [];
        foreach ($norm as $n => [$r, $l]) {
            if ($r['stock_state'] !== 'sellable' || $entry['entry_type'] === 'expired') {
                continue;
            }
            $iid = (int) $items[$n]['id'];
            $need[$iid] = ($need[$iid] ?? 0) + $l['base_quantity'];
            if ($r['reservation_item_id'] !== null) {
                $ri = $this->db->first("SELECT ri.*, r.warehouse_id FROM inventory_reservation_items ri JOIN inventory_reservations r ON r.id = ri.reservation_id WHERE ri.id = ?", [$r['reservation_item_id']]);
                if ($ri === null || (int) $ri['warehouse_id'] !== (int) $entry['warehouse_id'] || (int) $ri['product_id'] !== (int) $r['product_id'] || (int) ($ri['variant_id'] ?? 0) !== (int) ($r['variant_id'] ?? 0)) {
                    throw new DomainRuleException("Line {$n}: the linked reservation is for another item or warehouse.");
                }
                if (!in_array($ri['status'], ['active', 'partially_fulfilled'], true)) {
                    throw new DomainRuleException("Line {$n}: the linked reservation is no longer open ({$ri['status']}).");
                }
                $open = Qty::fromDb($ri['quantity']) - Qty::fromDb($ri['qty_fulfilled']) - Qty::fromDb($ri['qty_released']);
                $linked[$iid] = ($linked[$iid] ?? 0) + min($open, $l['base_quantity']);
            }
        }
        if ((int) $entry['reservation_override'] === 1) {
            return;
        }
        foreach ($need as $iid => $qty) {
            $item = $ledger->freshItem($iid);
            $allowExpired = (int) $entry['expired_override'] === 1 || !(bool) App::settings()->get('inventory_block_expired_dispatch', true);
            $reservedOthers = $ledger->reserved((int) $item['warehouse_id'], (int) $item['product_id'], $item['variant_id'] !== null ? (int) $item['variant_id'] : null) - ($linked[$iid] ?? 0);
            $free = $stock->issuable($iid, $allowExpired) - max(0, $reservedOthers);
            $cfg = (new ProductInventoryService($this->db))->config((int) $item['product_id']);
            if ($qty > $free && !$cfg['allow_negative'] && (int) $entry['negative_override'] !== 1) {
                $name = $cfg['product']['name'];
                $blocked = $allowExpired ? 0 : $stock->issuable($iid, true) - $stock->issuable($iid);
                throw new DomainRuleException("Not enough free stock of {$name}: " . Qty::format(max(0, $free)) . " {$cfg['unit_name']} can be issued, " . Qty::format($qty) . ' requested'
                    . ($reservedOthers > 0 ? '; ' . Qty::format($reservedOthers) . ' are reserved for other references' : '')
                    . ($blocked > 0 ? '; ' . Qty::format($blocked) . ' more are in expired or on-hold batches' : '') . '.');
            }
        }
    }

    /**
     * Opening stock may be recorded once per warehouse, item and batch: no other opening document
     * (pending, approved or posted, not reversed) may cover the same stock.
     * @param array<string, mixed> $entry @param list<array<string, mixed>> $lines
     */
    private function assertNoDuplicateOpening(array $entry, array $lines, bool $postedOnly = false): void
    {
        $seen = [];
        foreach ($lines as $l) {
            $batch = strtoupper((string) ($l['batch_no'] ?? '') ?: (string) ($this->db->scalar('SELECT batch_no FROM inventory_batches WHERE id = ?', [$l['batch_id'] ?? 0]) ?? ''));
            $key = $l['product_id'] . ':' . ($l['variant_id'] ?? 0) . ':' . $batch;
            if (isset($seen[$key])) {
                throw new DomainRuleException('The same item' . ($batch !== '' ? " and batch {$batch}" : '') . ' is listed twice in this opening stock.');
            }
            $seen[$key] = true;
            $statuses = $postedOnly ? "('posted')" : "('pending_approval', 'approved', 'posted')";
            $others = $this->db->select("SELECT e.entry_no, i.batch_no, b.batch_no AS existing_batch FROM inventory_entry_items i JOIN inventory_entries e ON e.id = i.entry_id
                LEFT JOIN inventory_batches b ON b.id = i.batch_id WHERE e.direction = 'opening' AND e.warehouse_id = ? AND e.id <> ? AND e.status IN {$statuses}
                AND i.product_id = ? AND " . (($l['variant_id'] ?? null) === null ? 'i.variant_id IS NULL' : 'i.variant_id = ?'),
                array_values(array_filter([(int) $entry['warehouse_id'], (int) ($entry['id'] ?? 0), (int) $l['product_id'], $l['variant_id'] ?? null], static fn ($v): bool => $v !== null)));
            foreach ($others as $o) {
                $ob = strtoupper((string) ($o['batch_no'] ?: $o['existing_batch'] ?: ''));
                if ($ob === $batch) {
                    throw new DomainRuleException("Opening stock for this item" . ($batch !== '' ? " (batch {$batch})" : '') . " in this warehouse is already recorded in {$o['entry_no']}. Correct it with a stock adjustment or reverse {$o['entry_no']} first.");
                }
            }
        }
    }

    // ---------------------------------------------------------------- validation

    /**
     * @param array<string, mixed> $data @param array<string, mixed> $actor @param array<string, mixed>|null $entry
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    private function validate(string $direction, array $data, array $actor, ?array $entry): array
    {
        $type = $direction === 'opening' ? 'opening' : (string) ($data['entry_type'] ?? '');
        if (!isset(self::TYPES[$direction][$type])) {
            throw ValidationException::withMessage('entry_type', 'Choose the type.');
        }
        $wh = (new WarehouseService($this->db))->find((int) ($data['warehouse_id'] ?? 0));
        if ($wh === null) {
            throw ValidationException::withMessage('warehouse_id', 'Choose a warehouse.');
        }
        WarehouseService::assertUsable($wh);
        $date = trim((string) ($data['entry_date'] ?? ''));
        if (!BusinessDate::isValid($date)) {
            throw ValidationException::withMessage('entry_date', 'Enter a valid date.');
        }
        if ($date > BusinessDate::today()) {
            throw ValidationException::withMessage('entry_date', 'Stock cannot be recorded for a future date.');
        }
        $supplier = ($data['supplier_id'] ?? '') !== '' && $data['supplier_id'] !== null ? (int) $data['supplier_id'] : null;
        if ($supplier !== null) {
            $s = $this->db->first('SELECT * FROM suppliers WHERE id = ?', [$supplier]);
            if ($s === null || !in_array($type, ['purchase_receipt', 'supplier_return', 'other_receipt'], true)) {
                throw ValidationException::withMessage('supplier_id', 'A supplier can be named on purchase receipts, supplier returns and other receipts only.');
            }
            try {
                \App\Services\Supplier\SupplierService::assertUsable($s);
            } catch (DomainRuleException $e) {
                throw ValidationException::withMessage('supplier_id', $e->getMessage());
            }
        } elseif ($type === 'supplier_return') {
            throw ValidationException::withMessage('supplier_id', 'Choose the supplier the goods go back to.');
        }
        $reason = trim((string) ($data['reason'] ?? ''));
        if (in_array($type, self::REASON_REQUIRED, true) && $reason === '') {
            throw ValidationException::withMessage('reason', 'Enter the reason for this ' . strtolower(self::TYPES[$direction][$type]) . '.');
        }
        $overrides = ['negative_override' => 0, 'reservation_override' => 0, 'expired_override' => 0];
        if ($direction === 'outward') {
            foreach ($overrides as $k => $_) {
                $overrides[$k] = !empty($data[$k]) ? 1 : 0;
            }
            if (array_sum($overrides) > 0 && !App::gate()->allows($actor, 'inventory.override_stock_rules')) {
                throw ValidationException::withMessage('negative_override', 'You are not allowed to request stock overrides.');
            }
        }
        $stock = new StockLineService($this->db);
        $lines = [];
        $n = 0;
        foreach ((array) ($data['lines'] ?? []) as $raw) {
            if (!is_array($raw) || ((int) ($raw['product_id'] ?? 0) === 0 && trim((string) ($raw['quantity'] ?? '')) === '')) {
                continue;
            }
            $n++;
            if ($n > 200) {
                throw ValidationException::withMessage('lines', 'A document can have at most 200 lines.');
            }
            $l = $stock->normalize($raw, $wh, $direction === 'outward' ? 'out' : 'in', $n, in_array($type, ['opening', 'purchase_receipt'], true), $type !== 'purchase_receipt');
            $l['return_condition'] = null;
            $l['reservation_item_id'] = null;
            $l['stock_state'] = 'sellable';
            if ($type === 'customer_return') {
                $cond = (string) ($raw['return_condition'] ?? '');
                if (!isset(self::RETURN_CONDITIONS[$cond])) {
                    throw ValidationException::withMessage("lines.{$n}.return_condition", "Line {$n}: classify the returned goods (sellable, damaged, inspection required, restock pending or rejected).");
                }
                $l['return_condition'] = $cond;
                $l['stock_state'] = self::CONDITION_STATE[$cond] ?? 'sellable';
            }
            if ($direction === 'outward') {
                $state = (string) ($raw['stock_state'] ?? ($type === 'damaged' ? 'damaged' : 'sellable'));
                if (!in_array($state, ['sellable', 'damaged', 'quarantine'], true)) {
                    throw ValidationException::withMessage("lines.{$n}.stock_state", "Line {$n}: choose which stock is issued.");
                }
                $l['stock_state'] = $state;
                if (($raw['reservation_item_id'] ?? '') !== '' && $raw['reservation_item_id'] !== null) {
                    $l['reservation_item_id'] = (int) $raw['reservation_item_id'];
                }
                if ($l['batch_no'] !== null) {
                    $b = $this->db->first('SELECT id FROM inventory_batches WHERE batch_key = ?', [StockLineService::batchKey($l['product_id'], $l['variant_id'], $l['batch_no'])]);
                    if ($b === null) {
                        throw ValidationException::withMessage("lines.{$n}.batch_no", "Line {$n}: batch {$l['batch_no']} is not recorded for this item.");
                    }
                    $l['batch_id'] = (int) $b['id'];
                    $l['batch_no'] = null;
                }
            }
            $lines[$n] = $l;
        }
        if ($lines === []) {
            throw ValidationException::withMessage('lines', 'Add at least one line.');
        }
        if ($direction === 'opening') {
            $this->assertNoDuplicateOpening(['warehouse_id' => (int) $wh['id'], 'id' => (int) ($entry['id'] ?? 0)], array_values($lines));
        }
        $header = [
            'entry_type' => $type, 'entry_date' => $date, 'warehouse_id' => (int) $wh['id'], 'supplier_id' => $supplier,
            'source_reference' => mb_substr(trim((string) ($data['source_reference'] ?? '')), 0, 100) ?: null,
            'reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null, 'notes' => mb_substr(trim((string) ($data['notes'] ?? '')), 0, 5000) ?: null,
        ] + $overrides;
        return [$header, $lines];
    }

    /** @param array<string, mixed> $entry @return array<string, mixed> */
    public function storedAsInput(array $entry): array
    {
        $lines = [];
        foreach ($this->lines((int) $entry['id']) as $r) {
            $lines[] = StockLineService::storedToInput($r) + ['return_condition' => $r['return_condition'], 'stock_state' => $r['stock_state'], 'reservation_item_id' => $r['reservation_item_id']];
        }
        return ['entry_type' => $entry['entry_type'], 'warehouse_id' => $entry['warehouse_id'], 'entry_date' => $entry['entry_date'], 'supplier_id' => $entry['supplier_id'],
            'source_reference' => $entry['source_reference'], 'reason' => $entry['reason'], 'notes' => $entry['notes'], 'negative_override' => $entry['negative_override'],
            'reservation_override' => $entry['reservation_override'], 'expired_override' => $entry['expired_override'], 'lines' => $lines];
    }

    /** @param array<string, mixed> $e @return array<string, mixed> */
    public static function auditView(array $e): array
    {
        return array_intersect_key($e, array_flip(['entry_no', 'direction', 'entry_type', 'entry_date', 'warehouse_id', 'supplier_id', 'source_reference', 'source_document_type', 'source_document_id', 'status', 'reason']));
    }
}
