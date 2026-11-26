<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Core\App;
use App\Core\Database;
use App\Core\Validator;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Support\Uuid;

/**
 * Warehouses: unlimited, typed, with address and contact, mapped (by date) to the company's
 * GST registrations and business activities (Phase 4 masters, no copies). Users are assigned to
 * warehouses through the Phase 2 resource assignments ("warehouse" type), which drive the
 * "assigned warehouses" record scope. A warehouse with stock or any stock history is never
 * deleted — it can only be deactivated or archived.
 */
final class WarehouseService
{
    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'];
    private const FIELDS = ['name', 'code', 'warehouse_type_id', 'line1', 'line2', 'city', 'state_id', 'state_name', 'pin_code', 'country', 'contact_person', 'mobile', 'email', 'uses_locations', 'internal_notes'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT w.*, t.name AS type_name, t.code AS type_code, s.name AS state, s.code AS state_code, cu.name AS created_by_name, uu.name AS updated_by_name
            FROM warehouses w JOIN warehouse_types t ON t.id = w.warehouse_type_id LEFT JOIN gst_states s ON s.id = w.state_id
            LEFT JOIN users cu ON cu.id = w.created_by LEFT JOIN users uu ON uu.id = w.updated_by WHERE w.id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function types(bool $activeOnly = false): array
    {
        return $this->db->select('SELECT t.*, (SELECT COUNT(*) FROM warehouses w WHERE w.warehouse_type_id = t.id) AS warehouse_count FROM warehouse_types t'
            . ($activeOnly ? ' WHERE t.is_active = 1' : '') . ' ORDER BY t.sort_order, t.name');
    }

    /**
     * Warehouses a user may see, with stock figures.
     * @param array<string, mixed> $user @param array<string, mixed> $f @return list<array<string, mixed>>
     */
    public function list(array $user, array $f = []): array
    {
        [$w, $p] = InventoryScope::sql($user, 'w.id');
        $sql = "SELECT w.*, t.name AS type_name, s.name AS state,
                (SELECT COUNT(*) FROM warehouse_locations l WHERE l.warehouse_id = w.id AND l.status <> 'archived') AS location_count,
                (SELECT COUNT(DISTINCT i.product_id) FROM inventory_stock_items i WHERE i.warehouse_id = w.id AND i.qty_on_hand <> 0) AS product_count,
                (SELECT SUM(i.qty_on_hand) FROM inventory_stock_items i WHERE i.warehouse_id = w.id) AS qty_on_hand,
                (SELECT SUM(i.stock_value) FROM inventory_stock_items i WHERE i.warehouse_id = w.id) AS stock_value
                FROM warehouses w JOIN warehouse_types t ON t.id = w.warehouse_type_id LEFT JOIN gst_states s ON s.id = w.state_id WHERE {$w}";
        if (($f['status'] ?? '') !== '' && isset(self::STATUSES[$f['status']])) {
            $sql .= ' AND w.status = ?';
            $p[] = $f['status'];
        } elseif (($f['archived'] ?? '') === '') {
            $sql .= " AND w.status <> 'archived'";
        }
        if (($f['type'] ?? '') !== '') {
            $sql .= ' AND w.warehouse_type_id = ?';
            $p[] = (int) $f['type'];
        }
        if (($f['state'] ?? '') !== '') {
            $sql .= ' AND w.state_id = ?';
            $p[] = (int) $f['state'];
        }
        if (($f['gst'] ?? '') !== '') {
            $sql .= " AND w.id IN (SELECT warehouse_id FROM warehouse_gst_mappings WHERE status = 'active' AND gst_registration_id = ?)";
            $p[] = (int) $f['gst'];
        }
        if (($f['activity'] ?? '') !== '') {
            $sql .= " AND w.id IN (SELECT warehouse_id FROM warehouse_business_mappings WHERE status = 'active' AND business_activity_id = ?)";
            $p[] = (int) $f['activity'];
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $sql .= ' AND (w.name LIKE ? OR w.code LIKE ? OR w.city LIKE ?)';
            array_push($p, "%{$q}%", "%{$q}%", "%{$q}%");
        }
        return $this->db->select($sql . ' ORDER BY w.name', $p);
    }

    /** Active warehouses the user may use, for pickers. @param array<string, mixed> $user @return list<array<string, mixed>> */
    public function options(array $user, bool $includeInactive = false): array
    {
        [$w, $p] = InventoryScope::sql($user, 'w.id');
        return $this->db->select("SELECT w.id, w.code, w.name, w.status, w.uses_locations FROM warehouses w WHERE {$w} AND w.status " . ($includeInactive ? "<> 'archived'" : "= 'active'") . ' ORDER BY w.name', $p);
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function create(array $data, array $actor): int
    {
        $clean = $this->validate($data, null);
        $now = now();
        $id = $this->db->transaction(function () use ($clean, $actor, $now): int {
            if ($clean['code'] === '') {
                $clean['code'] = InventoryNumbers::next($this->db, 'warehouse');
            }
            $id = $this->db->insert('warehouses', $clean + ['uuid' => Uuid::v4(), 'status' => 'active', 'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'],
                'created_at' => $now, 'updated_at' => $now]);
            InventoryHistory::write($this->db, 'warehouse', $id, 'created', null, self::view($clean), null, $actor, $id, to: 'active');
            return $id;
        });
        $w = $this->find($id);
        App::activity()->log('inventory.warehouse_created', "Added warehouse {$w['code']} {$w['name']}", 'warehouse', $id, module: 'inventory', newValues: self::view($w));
        return $id;
    }

    /** @param array<string, mixed> $w @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function update(array $w, array $data, array $actor): void
    {
        $this->assertWritable($w);
        $clean = $this->validate($data, $w);
        $old = [];
        $new = [];
        foreach ($clean as $k => $v) {
            if ((string) ($w[$k] ?? '') !== (string) ($v ?? '')) {
                $old[$k] = $w[$k];
                $new[$k] = $v;
            }
        }
        if ($new === []) {
            return;
        }
        if (isset($new['uses_locations']) && (int) $new['uses_locations'] === 1 && $this->db->scalar("SELECT 1 FROM inventory_stock_balances WHERE warehouse_id = ? AND location_id IS NULL AND quantity <> 0", [$w['id']])) {
            throw ValidationException::withMessage('uses_locations', 'Some stock in this warehouse has no storage location yet. Move it to locations before making locations required.');
        }
        $this->db->transaction(function () use ($w, $new, $old, $actor): void {
            $this->db->update('warehouses', $new + ['updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $w['id']]);
            InventoryHistory::write($this->db, 'warehouse', (int) $w['id'], 'updated', self::view($old), self::view($new), null, $actor, (int) $w['id']);
        });
        App::activity()->log('inventory.warehouse_updated', "Updated warehouse {$w['code']}", 'warehouse', (int) $w['id'], module: 'inventory', oldValues: self::view($old), newValues: self::view($new));
    }

    /** active ↔ inactive. @param array<string, mixed> $w @param array<string, mixed> $actor */
    public function changeStatus(array $w, string $to, string $reason, array $actor): void
    {
        $this->assertWritable($w);
        if (!in_array($to, ['active', 'inactive'], true) || $to === $w['status']) {
            throw new DomainRuleException('This status change is not possible.');
        }
        if ($to === 'inactive' && trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $this->setStatus($w, $to, $reason, $actor, 'status_changed');
    }

    /**
     * Archive: only an empty warehouse without open documents (its history stays).
     * @param array<string, mixed> $w @param array<string, mixed> $actor
     */
    public function archive(array $w, string $reason, array $actor): void
    {
        $this->assertWritable($w);
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        if (($block = $this->blocker((int) $w['id'])) !== null) {
            throw new DomainRuleException('This warehouse cannot be archived: ' . $block);
        }
        $this->setStatus($w, 'archived', $reason, $actor, 'archived');
    }

    /** @param array<string, mixed> $w @param array<string, mixed> $actor */
    public function restore(array $w, array $actor): void
    {
        if ($w['status'] !== 'archived') {
            throw new DomainRuleException('Only archived warehouses can be restored.');
        }
        $this->setStatus($w, 'inactive', 'Restored', $actor, 'restored');
    }

    /**
     * Delete a warehouse that was never used (no stock, movements, documents, batches or serials).
     * Anything with history must be archived instead.
     * @param array<string, mixed> $w @param array<string, mixed> $actor
     */
    public function delete(array $w, array $actor): void
    {
        foreach (['inventory_stock_movements' => 'warehouse_id', 'inventory_stock_items' => 'warehouse_id', 'inventory_entries' => 'warehouse_id', 'inventory_adjustments' => 'warehouse_id',
            'inventory_reservations' => 'warehouse_id', 'inventory_serial_numbers' => 'warehouse_id', 'product_inventory_settings' => 'default_warehouse_id', 'inventory_reorder_rules' => 'warehouse_id'] as $table => $col) {
            if ($this->db->scalar("SELECT 1 FROM {$table} WHERE {$col} = ? LIMIT 1", [$w['id']])) {
                throw new DomainRuleException('This warehouse has stock, transaction history or settings that refer to it, so it cannot be deleted. Archive it instead.');
            }
        }
        if ($this->db->scalar('SELECT 1 FROM inventory_transfers WHERE from_warehouse_id = ? OR to_warehouse_id = ? LIMIT 1', [$w['id'], $w['id']])) {
            throw new DomainRuleException('This warehouse appears in stock transfers, so it cannot be deleted. Archive it instead.');
        }
        $this->db->transaction(function () use ($w): void {
            $this->db->execute('UPDATE warehouse_locations SET parent_id = NULL WHERE warehouse_id = ?', [$w['id']]);
            foreach (['warehouse_locations', 'warehouse_gst_mappings', 'warehouse_business_mappings'] as $t) {
                $this->db->execute("DELETE FROM {$t} WHERE warehouse_id = ?", [$w['id']]);
            }
            $this->db->execute("DELETE FROM user_resource_assignments WHERE resource_type = 'warehouse' AND resource_id = ?", [$w['id']]);
            $this->db->execute("UPDATE inventory_documents SET status = 'archived', archive_reason = 'Warehouse deleted' WHERE subject_type = 'warehouse' AND subject_id = ?", [$w['id']]);
            $this->db->execute('UPDATE inventory_history SET warehouse_id = NULL WHERE warehouse_id = ?', [$w['id']]);
            $this->db->delete('warehouses', ['id' => (int) $w['id']]);
        });
        App::activity()->log('inventory.warehouse_deleted', "Deleted unused warehouse {$w['code']} {$w['name']}", 'warehouse', (int) $w['id'], module: 'inventory', oldValues: self::view($w), userId: (int) $actor['id']);
    }

    /** Why the warehouse cannot be archived, or null. */
    public function blocker(int $id): ?string
    {
        foreach ($this->db->select('SELECT qty_on_hand FROM inventory_stock_items WHERE warehouse_id = ?', [$id]) as $r) {
            if (\App\Support\Qty::fromDb($r['qty_on_hand']) !== 0) {
                return 'it still holds stock. Transfer or adjust it to zero first.';
            }
        }
        if ($this->db->scalar("SELECT 1 FROM inventory_entries WHERE warehouse_id = ? AND status IN ('draft', 'pending_approval', 'approved')", [$id])
            || $this->db->scalar("SELECT 1 FROM inventory_adjustments WHERE warehouse_id = ? AND status IN ('draft', 'pending_approval', 'approved')", [$id])) {
            return 'it has stock documents that are not posted yet.';
        }
        if ($this->db->scalar("SELECT 1 FROM inventory_transfers WHERE (from_warehouse_id = ? OR to_warehouse_id = ?) AND transfer_status IN ('draft', 'pending_approval', 'approved', 'in_transit', 'partially_received')", [$id, $id])) {
            return 'it has open stock transfers.';
        }
        if ($this->db->scalar("SELECT 1 FROM inventory_reservations WHERE warehouse_id = ? AND status IN ('active', 'partially_fulfilled')", [$id])) {
            return 'it has open stock reservations.';
        }
        return null;
    }

    /** @param array<string, mixed> $w */
    public function assertWritable(array $w): void
    {
        if ($w['status'] === 'archived') {
            throw new DomainRuleException('Archived warehouses are read-only. Restore the warehouse first.');
        }
    }

    /** Stock documents can only use active warehouses. @param array<string, mixed> $w */
    public static function assertUsable(array $w): void
    {
        if ($w['status'] !== 'active') {
            throw new DomainRuleException("Warehouse {$w['name']} is " . strtolower(self::STATUSES[$w['status']] ?? $w['status']) . ' and cannot be used for stock movements.');
        }
    }

    // ---------------------------------------------------------------- assigned users (Phase 2 resource assignments)

    /** @return list<array<string, mixed>> */
    public function assignedUsers(int $warehouseId): array
    {
        return $this->db->select("SELECT u.id, u.name, u.email, u.status, a.created_at, ab.name AS assigned_by_name FROM user_resource_assignments a JOIN users u ON u.id = a.user_id
            LEFT JOIN users ab ON ab.id = a.assigned_by WHERE a.resource_type = 'warehouse' AND a.resource_id = ? ORDER BY u.name", [$warehouseId]);
    }

    /** Add or remove one user's assignment to this warehouse (Super Admin, through the Phase 2 service). @param array<string, mixed> $actor */
    public function setAssignment(int $warehouseId, int $userId, bool $assigned, array $actor): void
    {
        $svc = new \App\Services\Admin\ResourceAssignmentService($this->db);
        $ids = $svc->assigned($userId, 'warehouse');
        $ids = $assigned ? array_values(array_unique([...$ids, $warehouseId])) : array_values(array_diff($ids, [$warehouseId]));
        $svc->sync($userId, 'warehouse', $ids, $actor);
        InventoryHistory::write($this->db, 'warehouse', $warehouseId, $assigned ? 'user_assigned' : 'user_unassigned', null, ['user_id' => $userId], null, $actor, $warehouseId);
        App::flushAuthorization();
    }

    // ---------------------------------------------------------------- types

    /** @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function saveType(array $data, ?int $id, array $actor): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        $code = strtolower(trim((string) ($data['code'] ?? '')) ?: (string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)));
        $code = trim($code, '_');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            throw ValidationException::withMessage('name', 'Enter a name of 2–100 characters.');
        }
        if (!preg_match('/^[a-z0-9_]{2,40}$/', $code)) {
            throw ValidationException::withMessage('code', 'Use 2–40 lowercase letters, digits or underscores.');
        }
        if ($this->db->scalar('SELECT 1 FROM warehouse_types WHERE code = ? AND id <> ?', [$code, (int) $id])) {
            throw ValidationException::withMessage('code', 'This code is already used.');
        }
        $row = ['name' => $name, 'description' => trim((string) ($data['description'] ?? '')) ?: null, 'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => !empty($data['is_active']) || $id === null ? 1 : 0, 'updated_by' => (int) $actor['id'], 'updated_at' => now()];
        if ($id === null) {
            $id = $this->db->insert('warehouse_types', $row + ['code' => $code, 'is_system' => 0, 'created_by' => (int) $actor['id'], 'created_at' => now()]);
        } else {
            $t = $this->db->first('SELECT * FROM warehouse_types WHERE id = ?', [$id]) ?? throw new DomainRuleException('Type not found.');
            if (!(int) $t['is_system']) {
                $row['code'] = $code;
            }
            $this->db->update('warehouse_types', $row, ['id' => $id]);
        }
        InventoryHistory::write($this->db, 'warehouse_type', $id, 'saved', null, ['code' => $code, 'name' => $name, 'active' => $row['is_active']], null, $actor);
        App::activity()->log('inventory.warehouse_type_saved', "Saved warehouse type {$name}", 'warehouse_type', $id, module: 'inventory', newValues: ['code' => $code, 'name' => $name]);
        return $id;
    }

    // ---------------------------------------------------------------- internals

    /** @param array<string, mixed> $w @param array<string, mixed> $actor */
    private function setStatus(array $w, string $to, string $reason, array $actor, string $event): void
    {
        $this->db->transaction(function () use ($w, $to, $reason, $actor, $event): void {
            $this->db->update('warehouses', ['status' => $to, 'status_reason' => trim($reason) !== '' ? mb_substr($reason, 0, 255) : null, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $w['id']]);
            InventoryHistory::write($this->db, 'warehouse', (int) $w['id'], $event, null, null, $reason, $actor, (int) $w['id'], (string) $w['status'], $to);
        });
        App::activity()->log('inventory.warehouse_' . $event, "Warehouse {$w['code']}: {$w['status']} → {$to}", 'warehouse', (int) $w['id'], module: 'inventory',
            oldValues: ['status' => $w['status']], newValues: ['status' => $to, 'reason' => $reason]);
    }

    /** @param array<string, mixed> $data @param array<string, mixed>|null $w @return array<string, mixed> */
    private function validate(array $data, ?array $w): array
    {
        $d = [];
        foreach (self::FIELDS as $k) {
            $d[$k] = is_string($data[$k] ?? null) ? trim($data[$k]) : ($data[$k] ?? '');
        }
        $d['name'] = (string) preg_replace('/\s+/', ' ', (string) $d['name']);
        $d['code'] = strtoupper((string) $d['code']);
        $d['country'] = strtoupper((string) $d['country'] ?: 'IN');
        $d['mobile'] = (string) preg_replace('/[\s()-]+/', '', (string) $d['mobile']);
        $v = Validator::make($d, [
            'name' => 'required|string|min:2|max:150', 'code' => 'nullable|regex:/^[A-Z0-9][A-Z0-9_-]{1,29}$/', 'warehouse_type_id' => 'required|integer|exists:warehouse_types,id',
            'line1' => 'nullable|string|max:200', 'line2' => 'nullable|string|max:200', 'city' => 'nullable|string|max:100', 'state_id' => 'nullable|integer|exists:gst_states,id',
            'state_name' => 'nullable|string|max:100', 'pin_code' => 'nullable|string|max:10', 'country' => 'required|regex:/^[A-Z]{2}$/', 'contact_person' => 'nullable|string|max:150',
            'mobile' => 'nullable|regex:/^\+?\d{8,15}$/', 'email' => 'nullable|email|max:150', 'internal_notes' => 'nullable|string|max:5000',
        ], ['warehouse_type_id' => 'Warehouse type', 'state_id' => 'State', 'pin_code' => 'PIN code']);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        if ($d['code'] !== '' && $this->db->scalar('SELECT 1 FROM warehouses WHERE code = ? AND id <> ?', [$d['code'], (int) ($w['id'] ?? 0)])) {
            throw ValidationException::withMessage('code', 'This warehouse code is already used.');
        }
        if ($w !== null && $d['code'] === '') {
            $d['code'] = (string) $w['code'];
        }
        if ($w === null || (int) $w['warehouse_type_id'] !== (int) $d['warehouse_type_id']) {
            if (!$this->db->scalar('SELECT is_active FROM warehouse_types WHERE id = ?', [(int) $d['warehouse_type_id']])) {
                throw ValidationException::withMessage('warehouse_type_id', 'This warehouse type is inactive.');
            }
        }
        if ($d['country'] === 'IN') {
            if ($d['pin_code'] !== '' && !preg_match('/^[1-9]\d{5}$/', (string) $d['pin_code'])) {
                throw ValidationException::withMessage('pin_code', 'Enter a 6-digit PIN code.');
            }
            if ($d['mobile'] !== '' && !preg_match('/^(\+91)?[6-9]\d{9}$/', (string) $d['mobile'])) {
                throw ValidationException::withMessage('mobile', 'Enter a 10-digit Indian mobile number (optionally with +91).');
            }
            $d['state_name'] = '';
        } else {
            $d['state_id'] = '';
        }
        $n = static fn (mixed $x): mixed => $x === '' || $x === null ? null : $x;
        return [
            'name' => $d['name'], 'code' => $d['code'], 'warehouse_type_id' => (int) $d['warehouse_type_id'], 'line1' => $n($d['line1']), 'line2' => $n($d['line2']), 'city' => $n($d['city']),
            'state_id' => $d['state_id'] !== '' && $d['state_id'] !== null ? (int) $d['state_id'] : null, 'state_name' => $n($d['state_name']), 'pin_code' => $n($d['pin_code']),
            'country' => $d['country'], 'contact_person' => $n($d['contact_person']), 'mobile' => $n($d['mobile']), 'email' => $n($d['email']),
            'uses_locations' => !empty($data['uses_locations']) ? 1 : 0, 'internal_notes' => $n($d['internal_notes']),
        ];
    }

    /** Values safe for history and audit. @param array<string, mixed> $row @return array<string, mixed> */
    public static function view(array $row): array
    {
        return array_intersect_key($row, array_flip(['code', 'name', 'warehouse_type_id', 'city', 'state_id', 'pin_code', 'country', 'contact_person', 'mobile', 'email', 'uses_locations', 'status']));
    }
}
