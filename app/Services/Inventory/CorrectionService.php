<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Approval\ApprovalEngine;
use App\Support\BusinessDate;
use App\Support\Money;
use App\Support\Qty;

/**
 * Corrections that change recorded facts and therefore go through approval workflows:
 * batch corrections (number, manufacturing and expiry dates of a batch that has stock history),
 * serial-number corrections, and inventory valuation corrections (revaluing the unit cost of
 * stock on hand — posted to the ledger as a zero-quantity revaluation row). Nothing applies
 * until approved; the old and new values are kept.
 */
final class CorrectionService
{
    public const SUBJECT = 'inventory_change';
    public const TYPES = ['batch_correction' => 'Batch correction', 'serial_correction' => 'Serial number correction', 'valuation_correction' => 'Valuation correction'];
    private const WORKFLOWS = ['batch_correction' => ['inventory_batch_correction', 'inventory.manage_batches'], 'serial_correction' => ['inventory_serial_correction', 'inventory.manage_serial_numbers'],
        'valuation_correction' => ['inventory_valuation_correction', 'inventory.manage_stock_valuation']];
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

    /** @return list<array<string, mixed>> */
    public function forSubject(string $type, int $id): array
    {
        $rows = $this->db->select('SELECT c.*, u.name AS requested_by_name, d.name AS decided_by_name FROM inventory_change_requests c LEFT JOIN users u ON u.id = c.created_by
            LEFT JOIN users d ON d.id = c.decided_by WHERE c.subject_type = ? AND c.subject_id = ? ORDER BY c.id DESC', [$type, $id]);
        foreach ($rows as &$r) {
            $r['old_values'] = $r['old_values'] !== null ? json_decode((string) $r['old_values'], true) : null;
            $r['new_values'] = json_decode((string) $r['new_values'], true);
        }
        return $rows;
    }

    /**
     * Correct batch number / manufacturing / expiry date. Applied at once while the batch has no
     * stock movements, otherwise through the batch-correction workflow.
     * @param array<string, mixed> $batch @param array<string, mixed> $data @param array<string, mixed> $actor
     */
    public function requestBatchCorrection(array $batch, array $data, string $reason, array $actor): string
    {
        $new = [];
        $no = strtoupper(trim((string) ($data['batch_no'] ?? $batch['batch_no'])));
        if (!preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,59}$/', $no)) {
            throw ValidationException::withMessage('batch_no', 'Enter a valid batch number.');
        }
        foreach (['mfg_date', 'expiry_date'] as $k) {
            $v = trim((string) ($data[$k] ?? '')) ?: null;
            if ($v !== null && !BusinessDate::isValid($v)) {
                throw ValidationException::withMessage($k, 'Enter a valid date.');
            }
            if ($v !== $batch[$k]) {
                $new[$k] = $v;
            }
        }
        $mfg = array_key_exists('mfg_date', $new) ? $new['mfg_date'] : $batch['mfg_date'];
        $exp = array_key_exists('expiry_date', $new) ? $new['expiry_date'] : $batch['expiry_date'];
        if ($mfg !== null && $exp !== null && $exp < $mfg) {
            throw ValidationException::withMessage('expiry_date', 'The expiry date cannot be before the manufacturing date.');
        }
        if ($no !== $batch['batch_no']) {
            $key = StockLineService::batchKey((int) $batch['product_id'], $batch['variant_id'] !== null ? (int) $batch['variant_id'] : null, $no);
            if ($this->db->scalar('SELECT 1 FROM inventory_batches WHERE batch_key = ? AND id <> ?', [$key, $batch['id']])) {
                throw ValidationException::withMessage('batch_no', 'This batch number already exists for the item.');
            }
            $new['batch_no'] = $no;
        }
        if ($new === []) {
            throw ValidationException::withMessage('batch_no', 'Nothing was changed.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $old = array_intersect_key($batch, $new);
        if (!$this->db->scalar('SELECT 1 FROM inventory_stock_movements WHERE batch_id = ? LIMIT 1', [$batch['id']])) {
            $this->applyBatch((int) $batch['id'], $new, $old, $reason, $actor, null);
            return 'applied';
        }
        return $this->request('batch_correction', 'batch', (int) $batch['id'], $old, $new, $reason, $actor, "Batch correction {$batch['batch_no']}", 0);
    }

    /** @param array<string, mixed> $serial @param array<string, mixed> $actor */
    public function requestSerialCorrection(array $serial, string $newNo, string $reason, array $actor): string
    {
        $list = StockLineService::serialList($newNo);
        if (count($list) !== 1) {
            throw ValidationException::withMessage('serial_no', 'Enter one serial number.');
        }
        $newNo = $list[0];
        if ($newNo === $serial['serial_no']) {
            throw ValidationException::withMessage('serial_no', 'Nothing was changed.');
        }
        if ($this->db->scalar('SELECT 1 FROM inventory_serial_numbers WHERE serial_key = ?', [$serial['product_id'] . ':' . $newNo])) {
            throw ValidationException::withMessage('serial_no', 'This serial number is already registered for the product.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        return $this->request('serial_correction', 'serial', (int) $serial['id'], ['serial_no' => $serial['serial_no']], ['serial_no' => $newNo], $reason, $actor, "Serial number correction {$serial['serial_no']}", 0);
    }

    /**
     * Revalue the stock on hand of one warehouse item at a new unit cost.
     * @param array<string, mixed> $item inventory_stock_items row @param array<string, mixed> $actor
     */
    public function requestRevaluation(array $item, string $newCost, string $reason, array $actor): string
    {
        $cost = Qty::parse($newCost);
        if ($cost === null) {
            throw ValidationException::withMessage('unit_cost', 'Enter the new unit cost (0 or more, up to 4 decimals).');
        }
        $qty = Qty::fromDb($item['qty_on_hand']);
        if ($qty <= 0) {
            throw new DomainRuleException('There is no stock on hand to revalue.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $oldCost = Qty::unitCost(Money::fromDb($item['stock_value']), $qty);
        if ($oldCost === $cost) {
            throw ValidationException::withMessage('unit_cost', 'The stock is already valued at this unit cost.');
        }
        if ($this->db->scalar("SELECT 1 FROM inventory_change_requests WHERE subject_type = 'stock_item' AND subject_id = ? AND status = 'pending_approval'", [$item['id']])) {
            throw new DomainRuleException('A revaluation of this stock is already waiting for approval.');
        }
        $change = Qty::value($qty, $cost) - Money::fromDb($item['stock_value']);
        return $this->request('valuation_correction', 'stock_item', (int) $item['id'], ['unit_cost' => Qty::toDb($oldCost), 'value' => Money::toDb(Money::fromDb($item['stock_value'])), 'qty' => Qty::toDb($qty)],
            ['unit_cost' => Qty::toDb($cost), 'value' => Money::toDb(Qty::value($qty, $cost))], $reason, $actor, 'Inventory revaluation ' . $item['item_key'], abs($change) / 100);
    }

    /** @param array<string, mixed> $request */
    public function applyDecision(array $request, string $status): void
    {
        $c = $this->db->first('SELECT * FROM inventory_change_requests WHERE id = ?', [$request['subject_id']]);
        if ($c === null || $c['status'] !== 'pending_approval') {
            return;
        }
        $last = $this->db->first('SELECT acted_by, comment FROM approval_actions WHERE request_id = ? ORDER BY id DESC LIMIT 1', [$request['id']]);
        $by = ['id' => $last !== null ? (int) $last['acted_by'] : (int) $c['created_by']];
        $old = $c['old_values'] !== null ? (array) json_decode((string) $c['old_values'], true) : [];
        $new = (array) json_decode((string) $c['new_values'], true);
        if ($status !== 'approved') {
            $this->db->update('inventory_change_requests', ['status' => $status === 'rejected' ? 'rejected' : 'cancelled', 'decided_by' => $by['id'], 'decided_at' => now(), 'updated_at' => now()], ['id' => $c['id']]);
            InventoryHistory::write($this->db, (string) $c['subject_type'], (int) $c['subject_id'], $c['change_type'] . '_' . ($status === 'rejected' ? 'rejected' : 'cancelled'), $old, $new, $last['comment'] ?? null, $by);
            return;
        }
        try {
            $this->db->transaction(function () use ($c, $old, $new, $by): void {
                match ($c['change_type']) {
                    'batch_correction' => $this->applyBatch((int) $c['subject_id'], $new, $old, (string) $c['reason'], ['id' => (int) $c['created_by']], $by),
                    'serial_correction' => $this->applySerial((int) $c['subject_id'], (string) $new['serial_no'], (string) $c['reason'], $by),
                    'valuation_correction' => $this->applyRevaluation((int) $c['subject_id'], Qty::fromDb($new['unit_cost']), Qty::fromDb($old['qty'] ?? '0'), (string) $c['reason'], (int) $c['id'], ['id' => (int) $c['created_by']], $by),
                    default => null,
                };
                $this->db->update('inventory_change_requests', ['status' => 'applied', 'decided_by' => $by['id'], 'decided_at' => now(), 'updated_at' => now()], ['id' => $c['id']]);
            });
        } catch (DomainRuleException $e) {
            $this->db->update('inventory_change_requests', ['status' => 'rejected', 'decided_by' => $by['id'], 'decided_at' => now(), 'updated_at' => now()], ['id' => $c['id']]);
            InventoryHistory::write($this->db, (string) $c['subject_type'], (int) $c['subject_id'], $c['change_type'] . '_failed', $old, $new, $e->getMessage(), $by);
        }
    }

    /**
     * Change the company default valuation method — refused while stock valued with it is on hand.
     * @param array<string, mixed> $actor
     */
    public function setDefaultMethod(string $method, string $reason, array $actor): void
    {
        if (!isset(InventoryLedger::METHODS[$method])) {
            throw ValidationException::withMessage('inventory_valuation_method', 'Choose FIFO or weighted average.');
        }
        $old = (string) App::settings()->get('inventory_valuation_method', 'weighted_average');
        if ($old === $method) {
            return;
        }
        foreach ($this->db->select('SELECT i.qty_on_hand, i.product_id FROM inventory_stock_items i LEFT JOIN product_inventory_settings s ON s.product_id = i.product_id WHERE s.valuation_method IS NULL') as $r) {
            if (Qty::fromDb($r['qty_on_hand']) !== 0) {
                throw ValidationException::withMessage('inventory_valuation_method', 'Stock valued with the current default method is on hand. Give those products their own method in their inventory settings, or change the default before stock is recorded.');
            }
        }
        $values = [];
        foreach (\App\Services\SettingsService::definitions() as $k => $d) {
            if (($d['group'] ?? '') === 'inventory') {
                $values[$k] = App::settings()->get($k, $d['default']);
            }
        }
        $values['inventory_valuation_method'] = $method;
        App::settings()->update(array_map(static fn ($v) => is_bool($v) ? ($v ? '1' : '') : (string) $v, $values), (int) $actor['id'], ['inventory']);
        $this->db->insert('inventory_valuation_history', ['event' => 'default_method', 'old_method' => $old, 'new_method' => $method, 'reason' => mb_substr($reason, 0, 500) ?: null,
            'created_by' => (int) $actor['id'], 'created_at' => now()]);
        App::activity()->log('inventory.valuation_method_changed', 'Changed the default stock valuation method from ' . InventoryLedger::METHODS[$old] . ' to ' . InventoryLedger::METHODS[$method], 'setting', 0,
            module: 'inventory', oldValues: ['method' => $old], newValues: ['method' => $method]);
    }

    // ---------------------------------------------------------------- internals

    /** @param array<string, mixed>|null $old @param array<string, mixed> $new @param array<string, mixed> $actor */
    private function request(string $type, string $subjectType, int $subjectId, ?array $old, array $new, string $reason, array $actor, string $title, float $amount): string
    {
        if ($this->db->scalar("SELECT 1 FROM inventory_change_requests WHERE subject_type = ? AND subject_id = ? AND change_type = ? AND status = 'pending_approval'", [$subjectType, $subjectId, $type])) {
            throw new DomainRuleException('A ' . strtolower(self::TYPES[$type]) . ' for this record is already waiting for approval.');
        }
        [$workflow, $permission] = self::WORKFLOWS[$type];
        $id = $this->db->insert('inventory_change_requests', ['change_type' => $type, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'old_values' => $old !== null ? json_encode($old) : null, 'new_values' => json_encode($new), 'reason' => mb_substr($reason, 0, 500), 'status' => 'pending_approval',
            'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()]);
        InventoryHistory::write($this->db, $subjectType, $subjectId, $type . '_requested', $old, $new, $reason, $actor);
        try {
            $req = App::approvals()->submit($workflow, self::SUBJECT, $id, $amount, $title, $actor, $permission);
        } catch (\Throwable $e) {
            $this->db->update('inventory_change_requests', ['status' => 'cancelled', 'updated_at' => now()], ['id' => $id]);
            throw $e;
        }
        $this->db->update('inventory_change_requests', ['approval_request_id' => (int) $req['id']], ['id' => $id]);
        App::activity()->log('inventory.' . $type . '_requested', $title, 'inventory_change_request', $id, module: 'inventory', oldValues: $old, newValues: $new + ['reason' => $reason]);
        $fresh = $this->db->scalar('SELECT status FROM inventory_change_requests WHERE id = ?', [$id]);
        return $fresh === 'applied' ? 'applied' : 'pending';
    }

    /** @param array<string, mixed> $new @param array<string, mixed> $old @param array<string, mixed> $actor @param array<string, mixed>|null $approver */
    private function applyBatch(int $batchId, array $new, array $old, string $reason, array $actor, ?array $approver): void
    {
        $b = $this->db->first('SELECT * FROM inventory_batches WHERE id = ?', [$batchId]) ?? throw new DomainRuleException('Batch not found.');
        $row = $new;
        if (isset($new['batch_no'])) {
            $row['batch_key'] = StockLineService::batchKey((int) $b['product_id'], $b['variant_id'] !== null ? (int) $b['variant_id'] : null, (string) $new['batch_no']);
            if ($this->db->scalar('SELECT 1 FROM inventory_batches WHERE batch_key = ? AND id <> ?', [$row['batch_key'], $batchId])) {
                throw new DomainRuleException('This batch number already exists for the item.');
            }
        }
        $this->db->update('inventory_batches', $row + ['updated_by' => (int) ($approver['id'] ?? $actor['id']), 'updated_at' => now()], ['id' => $batchId]);
        InventoryHistory::write($this->db, 'batch', $batchId, 'corrected', $old, $new, $reason, $approver ?? $actor, productId: (int) $b['product_id']);
        App::activity()->log('inventory.batch_corrected', "Corrected batch {$b['batch_no']}", 'inventory_batch', $batchId, module: 'inventory', oldValues: $old, newValues: $new + ['reason' => $reason]);
    }

    /** @param array<string, mixed> $by */
    private function applySerial(int $serialId, string $newNo, string $reason, array $by): void
    {
        $s = $this->db->first('SELECT * FROM inventory_serial_numbers WHERE id = ?', [$serialId]) ?? throw new DomainRuleException('Serial number not found.');
        $key = $s['product_id'] . ':' . $newNo;
        if ($this->db->scalar('SELECT 1 FROM inventory_serial_numbers WHERE serial_key = ? AND id <> ?', [$key, $serialId])) {
            throw new DomainRuleException('This serial number is already registered for the product.');
        }
        $this->db->update('inventory_serial_numbers', ['serial_no' => $newNo, 'serial_key' => $key, 'updated_by' => (int) $by['id'], 'updated_at' => now()], ['id' => $serialId]);
        InventoryHistory::write($this->db, 'serial', $serialId, 'corrected', ['serial_no' => $s['serial_no']], ['serial_no' => $newNo], $reason, $by, productId: (int) $s['product_id']);
        App::activity()->log('inventory.serial_corrected', "Corrected serial number {$s['serial_no']} → {$newNo}", 'inventory_serial', $serialId, module: 'inventory',
            oldValues: ['serial_no' => $s['serial_no']], newValues: ['serial_no' => $newNo, 'reason' => $reason]);
    }

    /** @param array<string, mixed> $requester @param array<string, mixed> $by */
    private function applyRevaluation(int $itemId, int $newCost, int $qtyAtRequest, string $reason, int $changeId, array $requester, array $by): void
    {
        $ledger = new InventoryLedger($this->db);
        $ledger->lock([$itemId]);
        $item = $ledger->freshItem($itemId);
        if (Qty::fromDb($item['qty_on_hand']) !== $qtyAtRequest) {
            throw new DomainRuleException('The quantity on hand changed after the revaluation was requested. Request it again.');
        }
        $oldCost = Qty::unitCost(Money::fromDb($item['stock_value']), Qty::fromDb($item['qty_on_hand']));
        $m = $ledger->revalue($itemId, $newCost, ['source_id' => $changeId, 'posting_key' => 'revaluation:' . $changeId, 'reference_no' => 'REV-' . str_pad((string) $changeId, 6, '0', STR_PAD_LEFT),
            'date' => BusinessDate::today(), 'reason' => $reason, 'actor' => $requester, 'approver_id' => (int) $by['id']]);
        $this->db->insert('inventory_valuation_history', ['event' => 'revaluation', 'product_id' => (int) $item['product_id'], 'variant_id' => $item['variant_id'], 'warehouse_id' => (int) $item['warehouse_id'],
            'quantity' => Qty::toDb($m['qty']), 'old_unit_cost' => Qty::toDb($oldCost), 'new_unit_cost' => Qty::toDb($newCost), 'value_change' => Money::toDb($m['new_value'] - $m['old_value']),
            'movement_id' => (int) $m['id'], 'reason' => mb_substr($reason, 0, 500), 'created_by' => (int) $requester['id'], 'approved_by' => (int) $by['id'], 'created_at' => now()]);
        InventoryHistory::write($this->db, 'stock_item', $itemId, 'revalued', ['unit_cost' => Qty::toDb($oldCost), 'value' => Money::toDb($m['old_value'])],
            ['unit_cost' => Qty::toDb($newCost), 'value' => Money::toDb($m['new_value'])], $reason, $by, (int) $item['warehouse_id'], productId: (int) $item['product_id']);
        App::activity()->log('inventory.revalued', 'Revalued stock ' . $item['item_key'] . ' to ' . Qty::format($newCost, 2) . ' per unit', 'inventory_stock_item', $itemId, module: 'inventory',
            oldValues: ['unit_cost' => Qty::toDb($oldCost)], newValues: ['unit_cost' => Qty::toDb($newCost), 'reason' => $reason], userId: (int) $by['id']);
    }
}
