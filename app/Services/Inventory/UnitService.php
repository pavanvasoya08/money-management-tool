<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Support\Qty;

/**
 * Units of measure and conversions ("1 Carton = 12 Pieces"). A conversion is global or for one
 * product. Conversions form a graph; a new conversion is refused when its two units are already
 * connected (directly or through other units), so circular or contradictory conversions can
 * never exist. Factors are exact fractions — no floating point.
 */
final class UnitService
{
    public const TYPES = ['count' => 'Count', 'weight' => 'Weight', 'volume' => 'Volume', 'length' => 'Length', 'area' => 'Area', 'time' => 'Time', 'other' => 'Other'];
    private const FACTOR_SCALE = 1_000_000;

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function units(bool $activeOnly = false): array
    {
        return $this->db->select('SELECT u.*, (SELECT COUNT(*) FROM products p WHERE p.unit = u.code) AS product_count FROM inventory_units u'
            . ($activeOnly ? ' WHERE u.is_active = 1' : '') . ' ORDER BY u.sort_order, u.name');
    }

    /** @return array<string, mixed>|null */
    public function byCode(string $code): ?array
    {
        return $this->db->first('SELECT * FROM inventory_units WHERE code = ?', [$code]);
    }

    /** Decimal places allowed for a unit code (4 when unknown). */
    public function decimals(string $code): int
    {
        $d = $this->db->scalar('SELECT decimals FROM inventory_units WHERE code = ?', [$code]);
        return $d === null ? 4 : max(0, min(4, (int) $d));
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function saveUnit(array $data, ?int $id, array $actor): int
    {
        $code = strtolower(trim((string) ($data['code'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        $type = (string) ($data['unit_type'] ?? 'count');
        $decimals = (int) ($data['decimals'] ?? 0);
        if (!preg_match('/^[a-z0-9_]{1,20}$/', $code)) {
            throw ValidationException::withMessage('code', 'Use 1–20 lowercase letters, digits or underscores (e.g. ctn, bag_25kg).');
        }
        if (mb_strlen($name) < 1 || mb_strlen($name) > 60) {
            throw ValidationException::withMessage('name', 'Enter a name.');
        }
        if (!isset(self::TYPES[$type])) {
            throw ValidationException::withMessage('unit_type', 'Choose the unit type.');
        }
        if ($decimals < 0 || $decimals > 4) {
            throw ValidationException::withMessage('decimals', 'Decimal places must be between 0 and 4.');
        }
        if ($this->db->scalar('SELECT 1 FROM inventory_units WHERE code = ? AND id <> ?', [$code, (int) $id])) {
            throw ValidationException::withMessage('code', 'This unit code is already used.');
        }
        $row = ['name' => $name, 'unit_type' => $type, 'decimals' => $decimals, 'sort_order' => (int) ($data['sort_order'] ?? 0), 'updated_by' => (int) $actor['id'], 'updated_at' => now()];
        if ($id === null) {
            $id = $this->db->insert('inventory_units', $row + ['code' => $code, 'is_active' => 1, 'is_system' => 0, 'created_by' => (int) $actor['id'], 'created_at' => now()]);
        } else {
            $u = $this->db->first('SELECT * FROM inventory_units WHERE id = ?', [$id]) ?? throw new DomainRuleException('Unit not found.');
            $used = (bool) $this->db->scalar('SELECT 1 FROM products WHERE unit = ? LIMIT 1', [$u['code']]);
            if ($u['code'] !== $code && ($used || (int) $u['is_system'] === 1)) {
                throw ValidationException::withMessage('code', 'The code of a built-in unit or a unit used by products cannot change.');
            }
            if ($decimals < (int) $u['decimals'] && $this->db->scalar('SELECT 1 FROM inventory_stock_movements m JOIN products p ON p.id = m.product_id WHERE p.unit = ? LIMIT 1', [$u['code']])) {
                throw ValidationException::withMessage('decimals', 'Stock has already been recorded in this unit, so its decimal places can only increase.');
            }
            $row['is_active'] = !empty($data['is_active']) ? 1 : 0;
            if (!$row['is_active'] && $used) {
                throw ValidationException::withMessage('is_active', 'Products use this unit, so it stays active.');
            }
            $row['code'] = $code;
            $this->db->update('inventory_units', $row, ['id' => $id]);
        }
        InventoryHistory::write($this->db, 'unit', $id, 'saved', null, ['code' => $code, 'name' => $name, 'type' => $type, 'decimals' => $decimals], null, $actor);
        App::activity()->log('inventory.unit_saved', "Saved unit {$code} ({$name})", 'inventory_unit', $id, module: 'inventory', newValues: ['code' => $code, 'name' => $name, 'decimals' => $decimals]);
        return $id;
    }

    /** Active conversions, global and (optionally) of one product. @return list<array<string, mixed>> */
    public function conversions(?int $productId = null, bool $all = false): array
    {
        $sql = 'SELECT c.*, f.code AS from_code, f.name AS from_name, t.code AS to_code, t.name AS to_name, p.name AS product_name, p.product_code
                FROM inventory_unit_conversions c JOIN inventory_units f ON f.id = c.from_unit_id JOIN inventory_units t ON t.id = c.to_unit_id
                LEFT JOIN products p ON p.id = c.product_id WHERE c.is_active = 1';
        $params = [];
        if (!$all) {
            $sql .= ' AND (c.product_id IS NULL' . ($productId !== null ? ' OR c.product_id = ?' : '') . ')';
            if ($productId !== null) {
                $params[] = $productId;
            }
        }
        return $this->db->select($sql . ' ORDER BY c.product_id, f.sort_order, t.sort_order', $params);
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function addConversion(array $data, array $actor): int
    {
        $productId = ($data['product_id'] ?? '') !== '' ? (int) $data['product_id'] : null;
        $from = (int) ($data['from_unit_id'] ?? 0);
        $to = (int) ($data['to_unit_id'] ?? 0);
        $factor = $this->parseFactor((string) ($data['factor'] ?? ''));
        if ($factor === null) {
            throw ValidationException::withMessage('factor', 'Enter how many of the second unit make one of the first (greater than 0, up to 6 decimals).');
        }
        if (!$this->db->scalar('SELECT 1 FROM inventory_units WHERE id = ? AND is_active = 1', [$from]) || !$this->db->scalar('SELECT 1 FROM inventory_units WHERE id = ? AND is_active = 1', [$to])) {
            throw ValidationException::withMessage('from_unit_id', 'Choose two active units.');
        }
        if ($from === $to) {
            throw ValidationException::withMessage('to_unit_id', 'A unit cannot be converted to itself.');
        }
        if ($productId !== null && !$this->db->scalar('SELECT 1 FROM products WHERE id = ?', [$productId])) {
            throw ValidationException::withMessage('product_id', 'Choose a product.');
        }
        // Units already connected (in either direction, through any path) would create a loop or a second, possibly contradictory, route.
        $graphProducts = $productId !== null ? [$productId] : array_map('intval', array_column($this->db->select('SELECT DISTINCT product_id FROM inventory_unit_conversions WHERE product_id IS NOT NULL AND is_active = 1'), 'product_id'));
        foreach ($graphProducts === [] ? [null] : array_merge([null], $graphProducts) as $scope) {
            if ($this->path($scope, $from, $to) !== null) {
                throw ValidationException::withMessage('to_unit_id', 'These units are already connected' . ($scope !== null && $productId === null ? ' for at least one product' : '') . ' (directly or through other units). Adding this conversion would create a circular or conflicting conversion.');
            }
        }
        $key = ($productId ?? '*') . ':' . min($from, $to) . ':' . max($from, $to);
        $id = $this->db->insert('inventory_unit_conversions', ['product_id' => $productId, 'from_unit_id' => $from, 'to_unit_id' => $to,
            'factor' => intdiv($factor, self::FACTOR_SCALE) . '.' . str_pad((string) ($factor % self::FACTOR_SCALE), 6, '0', STR_PAD_LEFT), 'scope_key' => $key, 'is_active' => 1,
            'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()]);
        $codes = $this->db->first('SELECT f.code AS f, t.code AS t FROM inventory_units f, inventory_units t WHERE f.id = ? AND t.id = ?', [$from, $to]);
        $text = '1 ' . $codes['f'] . ' = ' . rtrim(rtrim(number_format($factor / self::FACTOR_SCALE, 6, '.', ''), '0'), '.') . ' ' . $codes['t'];
        InventoryHistory::write($this->db, 'unit_conversion', $id, 'added', null, ['conversion' => $text, 'product_id' => $productId], null, $actor, productId: $productId);
        App::activity()->log('inventory.unit_conversion_added', "Added unit conversion {$text}", 'inventory_unit_conversion', $id, module: 'inventory', newValues: ['conversion' => $text, 'product_id' => $productId]);
        return $id;
    }

    /** @param array<string, mixed> $actor */
    public function endConversion(int $id, string $reason, array $actor): void
    {
        $c = $this->db->first('SELECT * FROM inventory_unit_conversions WHERE id = ? AND is_active = 1', [$id]) ?? throw new DomainRuleException('Conversion not found.');
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $this->db->update('inventory_unit_conversions', ['is_active' => 0, 'scope_key' => $c['scope_key'] . '#' . $id, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $id]);
        InventoryHistory::write($this->db, 'unit_conversion', $id, 'ended', null, null, $reason, $actor, productId: $c['product_id'] !== null ? (int) $c['product_id'] : null);
        App::activity()->log('inventory.unit_conversion_ended', 'Ended a unit conversion', 'inventory_unit_conversion', $id, module: 'inventory', newValues: ['reason' => $reason]);
    }

    /**
     * Convert a quantity entered in $fromCode into the product's stock unit $toCode.
     * Returns the scaled quantity, or throws when no conversion exists.
     */
    public function toStockUnit(int $productId, string $fromCode, string $toCode, int $qty): int
    {
        if ($fromCode === $toCode) {
            return $qty;
        }
        $from = $this->byCode($fromCode);
        $to = $this->byCode($toCode);
        if ($from === null || $to === null) {
            throw ValidationException::withMessage('unit_code', 'Unknown unit.');
        }
        foreach ([$productId, null] as $scope) {
            $f = $this->path($scope, (int) $from['id'], (int) $to['id']);
            if ($f !== null) {
                return Qty::mulDiv($qty, $f[0], $f[1]);
            }
        }
        throw ValidationException::withMessage('unit_code', "There is no conversion from {$from['name']} to {$to['name']} for this product. Add one under Inventory → Units.");
    }

    /** Units a product's quantities can be entered in (its stock unit first). @return array<string, string> code => name */
    public function enterableUnits(int $productId, string $stockUnit): array
    {
        $out = [];
        $stock = $this->byCode($stockUnit);
        if ($stock === null) {
            return [$stockUnit => $stockUnit];
        }
        $out[$stockUnit] = (string) $stock['name'];
        foreach ($this->units(true) as $u) {
            if ($u['code'] !== $stockUnit && ($this->path($productId, (int) $u['id'], (int) $stock['id']) !== null || $this->path(null, (int) $u['id'], (int) $stock['id']) !== null)) {
                $out[(string) $u['code']] = (string) $u['name'];
            }
        }
        return $out;
    }

    /**
     * Factor from one unit to another as an exact fraction [numerator, denominator], following
     * global conversions plus those of $productId (when given). null = not connected.
     * @return array{0: int, 1: int}|null
     */
    public function path(?int $productId, int $from, int $to): ?array
    {
        $edges = [];
        $rows = $this->db->select('SELECT from_unit_id, to_unit_id, factor FROM inventory_unit_conversions WHERE is_active = 1 AND (product_id IS NULL' . ($productId !== null ? ' OR product_id = ?' : '') . ')',
            $productId !== null ? [$productId] : []);
        foreach ($rows as $r) {
            $f = $this->parseFactor((string) $r['factor']) ?? self::FACTOR_SCALE;
            $edges[(int) $r['from_unit_id']][] = [(int) $r['to_unit_id'], $f, self::FACTOR_SCALE];
            $edges[(int) $r['to_unit_id']][] = [(int) $r['from_unit_id'], self::FACTOR_SCALE, $f];
        }
        $queue = [[$from, 1, 1]];
        $seen = [$from => true];
        while ($queue !== []) {
            [$u, $num, $den] = array_shift($queue);
            if ($u === $to) {
                return [$num, $den];
            }
            foreach ($edges[$u] ?? [] as [$v, $n, $d]) {
                if (!isset($seen[$v])) {
                    $seen[$v] = true;
                    $nn = $num * $n;
                    $dd = $den * $d;
                    $g = self::gcd($nn, $dd);
                    $queue[] = [$v, intdiv($nn, $g), intdiv($dd, $g)];
                }
            }
        }
        return null;
    }

    private function parseFactor(string $s): ?int
    {
        $s = trim($s);
        if (preg_match('/^(\d{1,12})(?:\.(\d{1,6}))?$/', $s, $m) !== 1) {
            return null;
        }
        $v = (int) $m[1] * self::FACTOR_SCALE + (int) str_pad(rtrim($m[2] ?? '0', '0') ?: '0', 6, '0');
        return $v > 0 ? $v : null;
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return max(1, abs($a));
    }
}
