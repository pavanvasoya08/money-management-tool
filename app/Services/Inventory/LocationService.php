<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Support\Qty;

/**
 * Storage locations inside a warehouse (zone → section → rack → shelf → bin, any depth up to
 * MAX_DEPTH). A location can never become its own ancestor. Locations with stock are archived
 * only once they are empty.
 */
final class LocationService
{
    public const TYPES = ['zone' => 'Zone', 'section' => 'Section', 'aisle' => 'Aisle', 'rack' => 'Rack', 'shelf' => 'Shelf', 'bin' => 'Bin', 'other' => 'Other'];
    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'];
    public const MAX_DEPTH = 8;

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT l.*, p.code AS parent_code, p.name AS parent_name FROM warehouse_locations l LEFT JOIN warehouse_locations p ON p.id = l.parent_id WHERE l.id = ?', [$id]);
    }

    /**
     * Locations of a warehouse in tree order with depth, path and stock on hand.
     * @return list<array<string, mixed>>
     */
    public function tree(int $warehouseId, bool $withArchived = false): array
    {
        $rows = $this->db->select('SELECT l.*, (SELECT SUM(b.quantity) FROM inventory_stock_balances b WHERE b.location_id = l.id) AS qty_on_hand,
            (SELECT COUNT(DISTINCT b.product_id) FROM inventory_stock_balances b WHERE b.location_id = l.id AND b.quantity <> 0) AS product_count
            FROM warehouse_locations l WHERE l.warehouse_id = ?' . ($withArchived ? '' : " AND l.status <> 'archived'") . ' ORDER BY l.sort_order, l.code', [$warehouseId]);
        $children = [];
        foreach ($rows as $r) {
            $children[(int) ($r['parent_id'] ?? 0)][] = $r;
        }
        $ids = array_column($rows, 'id');
        $out = [];
        $walk = function (int $parent, int $depth, string $path) use (&$walk, &$out, $children): void {
            foreach ($children[$parent] ?? [] as $r) {
                $r['depth'] = $depth;
                $r['path'] = ltrim($path . ' › ' . $r['code'], ' ›');
                $out[] = $r;
                $walk((int) $r['id'], $depth + 1, $r['path']);
            }
        };
        $walk(0, 0, '');
        // children whose parent is archived (hidden) are listed at the top level
        foreach ($rows as $r) {
            if ($r['parent_id'] !== null && !in_array($r['parent_id'], $ids, false) && !in_array($r['id'], array_column($out, 'id'), false)) {
                $r['depth'] = 0;
                $r['path'] = $r['code'];
                $out[] = $r;
                $walk((int) $r['id'], 1, $r['code']);
            }
        }
        return $out;
    }

    /** Active locations for pickers: id => path. @return array<int, string> */
    public function options(int $warehouseId): array
    {
        $out = [];
        foreach ($this->tree($warehouseId) as $l) {
            if ($l['status'] === 'active') {
                $out[(int) $l['id']] = $l['path'] . ' — ' . $l['name'];
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $warehouse @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function save(array $warehouse, array $data, ?array $loc, array $actor): int
    {
        (new WarehouseService($this->db))->assertWritable($warehouse);
        $wid = (int) $warehouse['id'];
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        $type = (string) ($data['location_type'] ?? 'rack');
        $parent = ($data['parent_id'] ?? '') !== '' ? (int) $data['parent_id'] : null;
        if (!preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,39}$/', $code)) {
            throw ValidationException::withMessage('code', 'Use 1–40 letters, digits, dots, dashes or slashes (e.g. RACK-A, A-01-03).');
        }
        if (mb_strlen($name) < 1 || mb_strlen($name) > 100) {
            throw ValidationException::withMessage('name', 'Enter a name.');
        }
        if (!isset(self::TYPES[$type])) {
            throw ValidationException::withMessage('location_type', 'Choose the location type.');
        }
        if ($this->db->scalar('SELECT 1 FROM warehouse_locations WHERE warehouse_id = ? AND code = ? AND id <> ?', [$wid, $code, (int) ($loc['id'] ?? 0)])) {
            throw ValidationException::withMessage('code', 'This code is already used in this warehouse.');
        }
        $capacity = null;
        if (trim((string) ($data['capacity'] ?? '')) !== '') {
            $capacity = Qty::parse((string) $data['capacity']);
            if ($capacity === null) {
                throw ValidationException::withMessage('capacity', 'Enter the capacity as a number (up to 4 decimals).');
            }
        }
        if ($parent !== null) {
            $p = $this->find($parent);
            if ($p === null || (int) $p['warehouse_id'] !== $wid || $p['status'] === 'archived') {
                throw ValidationException::withMessage('parent_id', 'Choose a parent location in this warehouse.');
            }
            if ($loc !== null && ($parent === (int) $loc['id'] || in_array($parent, $this->descendantIds((int) $loc['id']), true))) {
                throw ValidationException::withMessage('parent_id', 'A location cannot be placed under itself or one of its own sub-locations.');
            }
            $depth = count($this->ancestorIds($parent)) + 2 + ($loc !== null ? $this->height((int) $loc['id']) : 0);
            if ($depth > self::MAX_DEPTH) {
                throw ValidationException::withMessage('parent_id', 'Locations can be nested at most ' . self::MAX_DEPTH . ' levels deep.');
            }
        }
        $row = ['code' => $code, 'name' => $name, 'location_type' => $type, 'parent_id' => $parent, 'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'capacity' => $capacity !== null ? Qty::toDb($capacity) : null, 'capacity_unit' => trim((string) ($data['capacity_unit'] ?? '')) ?: null,
            'sort_order' => (int) ($data['sort_order'] ?? 0), 'updated_by' => (int) $actor['id'], 'updated_at' => now()];
        $id = $this->db->transaction(function () use ($loc, $row, $wid, $actor): int {
            if ($loc === null) {
                $id = $this->db->insert('warehouse_locations', $row + ['warehouse_id' => $wid, 'status' => 'active', 'created_by' => (int) $actor['id'], 'created_at' => now()]);
                InventoryHistory::write($this->db, 'location', $id, 'created', null, $row, null, $actor, $wid);
                return $id;
            }
            $this->db->update('warehouse_locations', $row, ['id' => $loc['id']]);
            InventoryHistory::write($this->db, 'location', (int) $loc['id'], 'updated', array_intersect_key($loc, $row), $row, null, $actor, $wid);
            return (int) $loc['id'];
        });
        App::activity()->log('inventory.location_saved', ($loc === null ? 'Added' : 'Updated') . " location {$code} in warehouse {$warehouse['code']}", 'warehouse', $wid, module: 'inventory',
            newValues: ['location' => $code, 'name' => $name, 'parent_id' => $parent]);
        return $id;
    }

    /** @param array<string, mixed> $loc @param array<string, mixed> $actor */
    public function setStatus(array $loc, string $to, string $reason, array $actor): void
    {
        if (!isset(self::STATUSES[$to]) || $to === $loc['status']) {
            throw new DomainRuleException('This status change is not possible.');
        }
        if ($to !== 'active' && trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        if ($to === 'archived') {
            if ($this->db->scalar("SELECT 1 FROM warehouse_locations WHERE parent_id = ? AND status <> 'archived'", [$loc['id']])) {
                throw new DomainRuleException('Archive or move its sub-locations first.');
            }
            foreach ($this->db->select('SELECT quantity FROM inventory_stock_balances WHERE location_id = ?', [$loc['id']]) as $b) {
                if (Qty::fromDb($b['quantity']) !== 0) {
                    throw new DomainRuleException('This location still holds stock. Move it to another location first.');
                }
            }
        }
        $this->db->transaction(function () use ($loc, $to, $reason, $actor): void {
            $this->db->update('warehouse_locations', ['status' => $to, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $loc['id']]);
            InventoryHistory::write($this->db, 'location', (int) $loc['id'], 'status_changed', null, ['code' => $loc['code']], $reason, $actor, (int) $loc['warehouse_id'], (string) $loc['status'], $to);
        });
        App::activity()->log('inventory.location_status', "Location {$loc['code']}: {$loc['status']} → {$to}", 'warehouse', (int) $loc['warehouse_id'], module: 'inventory',
            newValues: ['location' => $loc['code'], 'status' => $to, 'reason' => $reason]);
    }

    /** A location for a stock line: must be active and belong to the warehouse. @return int|null */
    public function resolve(int $warehouseId, mixed $locationId, bool $required, string $field = 'location_id'): ?int
    {
        if ($locationId === null || $locationId === '' || (int) $locationId === 0) {
            if ($required) {
                throw ValidationException::withMessage($field, 'Choose a storage location — this warehouse keeps stock on locations.');
            }
            return null;
        }
        $l = $this->find((int) $locationId);
        if ($l === null || (int) $l['warehouse_id'] !== $warehouseId) {
            throw ValidationException::withMessage($field, 'Choose a location in this warehouse.');
        }
        if ($l['status'] !== 'active') {
            throw ValidationException::withMessage($field, "Location {$l['code']} is {$l['status']}.");
        }
        return (int) $l['id'];
    }

    /** @return list<int> */
    public function descendantIds(int $id): array
    {
        $out = [];
        $frontier = [$id];
        while ($frontier !== []) {
            $in = implode(',', array_fill(0, count($frontier), '?'));
            $frontier = array_map('intval', array_column($this->db->select("SELECT id FROM warehouse_locations WHERE parent_id IN ({$in})", $frontier), 'id'));
            $frontier = array_values(array_diff($frontier, $out, [$id]));
            array_push($out, ...$frontier);
        }
        return $out;
    }

    /** @return list<int> */
    public function ancestorIds(int $id): array
    {
        $out = [];
        $cur = $this->db->scalar('SELECT parent_id FROM warehouse_locations WHERE id = ?', [$id]);
        while ($cur !== null && !in_array((int) $cur, $out, true) && count($out) < 50) {
            $out[] = (int) $cur;
            $cur = $this->db->scalar('SELECT parent_id FROM warehouse_locations WHERE id = ?', [$cur]);
        }
        return $out;
    }

    private function height(int $id): int
    {
        $h = 0;
        $frontier = [$id];
        while (true) {
            $in = implode(',', array_fill(0, count($frontier), '?'));
            $frontier = array_map('intval', array_column($this->db->select("SELECT id FROM warehouse_locations WHERE parent_id IN ({$in})", $frontier), 'id'));
            if ($frontier === [] || $h > self::MAX_DEPTH) {
                return $h;
            }
            $h++;
        }
    }
}
