<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Core\App;
use App\Core\Database;
use App\Services\Product\CatalogService;
use App\Support\BusinessDate;
use App\Support\Money;
use App\Support\Qty;

/**
 * Read side of inventory: stock per warehouse item with its conditions (available, inspection,
 * damaged, in transit), expired and reserved quantities, free (available) stock, value and
 * reorder status; product totals; and the filters shared by the dashboard, the stock pages and
 * the reports. Everything is read from the ledger summaries and limited to the warehouses the
 * user may see.
 */
final class StockQueryService
{
    public const STOCK_STATUSES = ['in_stock' => 'In stock', 'low' => 'Low stock', 'out' => 'Out of stock', 'negative' => 'Negative', 'over' => 'Above maximum'];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $rules = null;
    /** @var array<int, array<string, mixed>>|null */
    private ?array $settings = null;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Stock items (warehouse × product × variant) with quantities per condition and status.
     * Filters: warehouse, product, variant, category (incl. sub-categories), brand, batch, gst, activity, status, q.
     * @param array<string, mixed> $user @param array<string, mixed> $f
     * @return list<array<string, mixed>>
     */
    public function items(array $user, array $f = []): array
    {
        (new ReservationService($this->db))->expireDue();
        [$where, $params] = $this->where($user, $f);
        $rows = $this->db->select("SELECT i.*, p.name AS product_name, p.product_code, p.sku, p.uuid AS product_uuid, p.unit, p.category_id, p.brand_id, v.name AS variant_name, v.sku AS variant_sku, v.uuid AS variant_uuid,
                w.code AS warehouse_code, w.name AS warehouse_name, c.name AS category_name, b.name AS brand_name
            FROM inventory_stock_items i JOIN products p ON p.id = i.product_id LEFT JOIN product_variants v ON v.id = i.variant_id JOIN warehouses w ON w.id = i.warehouse_id
            LEFT JOIN product_categories c ON c.id = COALESCE(p.subcategory_id, p.category_id) LEFT JOIN product_brands b ON b.id = p.brand_id
            WHERE {$where} ORDER BY p.name, v.name, w.name", $params);
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $states = [];
        foreach ($this->db->select("SELECT stock_item_id, stock_state, SUM(quantity) AS q FROM inventory_stock_balances WHERE stock_item_id IN ({$in}) GROUP BY stock_item_id, stock_state", $ids) as $s) {
            $states[(int) $s['stock_item_id']][$s['stock_state']] = Qty::fromDb($s['q']);
        }
        $expired = [];
        $onHold = [];
        foreach ($this->db->select("SELECT s.stock_item_id, CASE WHEN b.expiry_date < ? THEN 'expired' ELSE 'on_hold' END AS kind, SUM(s.quantity) AS q FROM inventory_stock_balances s
            JOIN inventory_batches b ON b.id = s.batch_id WHERE s.stock_item_id IN ({$in}) AND s.stock_state = 'sellable' AND (b.expiry_date < ? OR b.status = 'on_hold')
            GROUP BY s.stock_item_id, CASE WHEN b.expiry_date < ? THEN 'expired' ELSE 'on_hold' END", array_merge([BusinessDate::today()], $ids, [BusinessDate::today(), BusinessDate::today()])) as $s) {
            if ($s['kind'] === 'expired') {
                $expired[(int) $s['stock_item_id']] = Qty::fromDb($s['q']);
            } else {
                $onHold[(int) $s['stock_item_id']] = Qty::fromDb($s['q']);
            }
        }
        $reserved = [];
        foreach ($this->db->select("SELECT r.warehouse_id, ri.product_id, ri.variant_id, SUM(ri.quantity - ri.qty_fulfilled - ri.qty_released) AS q FROM inventory_reservation_items ri
            JOIN inventory_reservations r ON r.id = ri.reservation_id WHERE ri.status IN ('active', 'partially_fulfilled') GROUP BY r.warehouse_id, ri.product_id, ri.variant_id") as $s) {
            $reserved[$s['warehouse_id'] . ':' . $s['product_id'] . ':' . ($s['variant_id'] ?? 0)] = Qty::fromDb($s['q']);
        }
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $st = $states[$id] ?? [];
            $r['sellable'] = $st['sellable'] ?? 0;
            $r['quarantine'] = $st['quarantine'] ?? 0;
            $r['damaged'] = $st['damaged'] ?? 0;
            $r['in_transit'] = $st['in_transit'] ?? 0;
            $r['on_hand'] = Qty::fromDb($r['qty_on_hand']);
            $r['expired'] = $expired[$id] ?? 0;
            $r['on_hold'] = $onHold[$id] ?? 0;
            $r['reserved'] = $reserved[$r['warehouse_id'] . ':' . $r['product_id'] . ':' . ($r['variant_id'] ?? 0)] ?? 0;
            $r['available'] = $r['sellable'] - $r['expired'] - $r['on_hold'] - $r['reserved'];
            $r['value'] = Money::fromDb($r['stock_value']);
            $r['avg_cost'] = $r['on_hand'] > 0 ? Qty::unitCost($r['value'], $r['on_hand']) : Qty::fromDb($r['last_unit_cost']);
            $lv = $this->levels((int) $r['product_id'], $r['variant_id'] !== null ? (int) $r['variant_id'] : null, (int) $r['warehouse_id']);
            $r += $lv;
            $r['stock_status'] = self::status($r['available'], $r['sellable'], $lv);
            $out[] = $r;
        }
        if (($f['status'] ?? '') !== '' && isset(self::STOCK_STATUSES[$f['status']])) {
            $out = array_values(array_filter($out, static fn (array $r): bool => $r['stock_status'] === $f['status'] || ($f['status'] === 'in_stock' && in_array($r['stock_status'], ['in_stock', 'low', 'over'], true))));
        }
        if (($f['nonzero'] ?? '') !== '') {
            $out = array_values(array_filter($out, static fn (array $r): bool => $r['on_hand'] !== 0 || $r['reserved'] !== 0));
        }
        return $out;
    }

    /**
     * Inventory-tracked products (and variants when stocked per variant) with totals across the
     * user's warehouses — including those never received, so out-of-stock items show up.
     * @param array<string, mixed> $user @param array<string, mixed> $f @return list<array<string, mixed>>
     */
    public function products(array $user, array $f = []): array
    {
        $items = $this->items($user, array_diff_key($f, ['status' => 1, 'nonzero' => 1]));
        $sum = [];
        foreach ($items as $i) {
            $k = $i['product_id'] . ':' . ($i['variant_id'] ?? 0);
            $sum[$k] ??= ['on_hand' => 0, 'sellable' => 0, 'available' => 0, 'reserved' => 0, 'damaged' => 0, 'quarantine' => 0, 'in_transit' => 0, 'expired' => 0, 'value' => 0, 'warehouses' => 0];
            foreach (['on_hand', 'sellable', 'available', 'reserved', 'damaged', 'quarantine', 'in_transit', 'expired', 'value'] as $x) {
                $sum[$k][$x] += $i[$x];
            }
            $sum[$k]['warehouses'] += $i['on_hand'] !== 0 ? 1 : 0;
        }
        $params = [];
        $sql = "SELECT p.id AS product_id, p.name AS product_name, p.product_code, p.sku, p.uuid, p.unit, p.category_id, p.brand_id, v.id AS variant_id, v.name AS variant_name, v.sku AS variant_sku,
                COALESCE(s.track_level, CASE WHEN EXISTS (SELECT 1 FROM product_variants x WHERE x.product_id = p.id AND x.status <> 'archived') THEN 'variant' ELSE 'product' END) AS track_level
            FROM products p JOIN product_types t ON t.id = p.product_type_id LEFT JOIN product_inventory_settings s ON s.product_id = p.id
            LEFT JOIN product_variants v ON v.product_id = p.id AND v.status <> 'archived'
            WHERE p.tracks_inventory = 1 AND t.is_service = 0 AND p.status <> 'archived'";
        $sql .= $this->productFilterSql($f, $params);
        $out = [];
        foreach ($this->db->select($sql . ' ORDER BY p.name, v.name', $params) as $r) {
            if ($r['track_level'] === 'product' && $r['variant_id'] !== null) {
                if (isset($out[$r['product_id'] . ':0'])) {
                    continue;
                }
                $r['variant_id'] = null;
                $r['variant_name'] = null;
                $r['variant_sku'] = null;
            }
            if ($r['track_level'] === 'variant' && $r['variant_id'] === null) {
                continue;
            }
            if (($f['variant'] ?? '') !== '' && (int) $r['variant_id'] !== (int) $f['variant']) {
                continue;
            }
            $k = $r['product_id'] . ':' . ($r['variant_id'] ?? 0);
            $t = $sum[$k] ?? ['on_hand' => 0, 'sellable' => 0, 'available' => 0, 'reserved' => 0, 'damaged' => 0, 'quarantine' => 0, 'in_transit' => 0, 'expired' => 0, 'value' => 0, 'warehouses' => 0];
            $lv = $this->levels((int) $r['product_id'], $r['variant_id'] !== null ? (int) $r['variant_id'] : null, null);
            $out[$k] = $r + $t + $lv + ['stock_status' => self::status($t['available'], $t['sellable'], $lv)];
        }
        $out = array_values($out);
        if (($f['status'] ?? '') !== '' && isset(self::STOCK_STATUSES[$f['status']])) {
            $out = array_values(array_filter($out, static fn (array $r): bool => $r['stock_status'] === $f['status'] || ($f['status'] === 'in_stock' && in_array($r['stock_status'], ['in_stock', 'low', 'over'], true))));
        }
        return $out;
    }

    /**
     * Items at or below their reorder level / minimum (per warehouse), with a suggested order
     * quantity and the Phase 6 preferred supplier as a reference. No purchase order is created.
     * @param array<string, mixed> $user @param array<string, mixed> $f @return list<array<string, mixed>>
     */
    public function reorderSuggestions(array $user, array $f = []): array
    {
        $maps = new \App\Services\Product\SupplierMappingService($this->db);
        $out = [];
        foreach ($this->items($user, $f) as $i) {
            if (!in_array($i['stock_status'], ['low', 'out', 'negative'], true)) {
                continue;
            }
            $target = $i['max_qty'] ?? (($i['reorder_level'] ?? $i['min_qty'] ?? 0) + ($i['reorder_qty'] ?? 0));
            $suggest = max($i['reorder_qty'] ?? 0, ($target ?? 0) - $i['available']);
            $pref = $maps->preferredFor((int) $i['product_id'], $i['variant_id'] !== null ? (int) $i['variant_id'] : null);
            $i['suggested_qty'] = max(0, $suggest);
            $i['preferred_supplier'] = $pref !== null ? ($pref['supplier_name'] ?? null) : null;
            $i['preferred_supplier_id'] = $pref['supplier_id'] ?? null;
            $out[] = $i;
        }
        return $out;
    }

    /** Totals for the dashboard. @param array<string, mixed> $user @param array<string, mixed> $f @return array<string, mixed> */
    public function totals(array $user, array $f = []): array
    {
        $items = $this->items($user, array_diff_key($f, ['status' => 1]));
        $t = ['on_hand' => 0, 'sellable' => 0, 'available' => 0, 'reserved' => 0, 'damaged' => 0, 'quarantine' => 0, 'in_transit' => 0, 'expired' => 0, 'value' => 0,
            'low' => 0, 'out' => 0, 'negative' => 0, 'items' => count($items)];
        $products = [];
        $variants = [];
        foreach ($items as $i) {
            foreach (['on_hand', 'sellable', 'available', 'reserved', 'damaged', 'quarantine', 'in_transit', 'expired', 'value'] as $k) {
                $t[$k] += $i[$k];
            }
            if (isset($t[$i['stock_status']])) {
                $t[$i['stock_status']]++;
            }
            $products[(int) $i['product_id']] = true;
            if ($i['variant_id'] !== null) {
                $variants[(int) $i['variant_id']] = true;
            }
        }
        $t['stocked_products'] = count($products);
        $t['stocked_variants'] = count($variants);
        return $t;
    }

    /** Status from free stock and levels. @param array<string, int|null> $lv */
    public static function status(int $available, int $sellable, array $lv): string
    {
        if ($sellable < 0) {
            return 'negative';
        }
        if ($available <= 0) {
            return 'out';
        }
        $low = $lv['reorder_level'] ?? $lv['min_qty'] ?? null;
        if ($lv['safety_stock'] !== null && $available <= $lv['safety_stock']) {
            return 'low';
        }
        if ($low !== null && $available <= $low) {
            return 'low';
        }
        if ($lv['max_qty'] !== null && $available > $lv['max_qty']) {
            return 'over';
        }
        return 'in_stock';
    }

    /** Reorder levels: variant+warehouse rule, warehouse rule, variant rule, product settings. @return array<string, int|null> */
    public function levels(int $productId, ?int $variantId, ?int $warehouseId): array
    {
        if ($this->rules === null) {
            $this->rules = [];
            foreach ($this->db->select('SELECT * FROM inventory_reorder_rules WHERE is_active = 1') as $r) {
                $this->rules[(string) $r['scope_key']] = $r;
            }
            $this->settings = [];
            foreach ($this->db->select('SELECT * FROM product_inventory_settings') as $s) {
                $this->settings[(int) $s['product_id']] = $s;
            }
        }
        $row = null;
        foreach ([[$variantId, $warehouseId], [null, $warehouseId], [$variantId, null]] as [$v, $w]) {
            if ($v === null && $w === null) {
                continue;
            }
            $key = $productId . ':' . ($v ?? 0) . ':' . ($w ?? 0);
            if (isset($this->rules[$key])) {
                $row = $this->rules[$key];
                break;
            }
        }
        $row ??= $this->settings[$productId] ?? [];
        $out = [];
        foreach (['reorder_level', 'min_qty', 'max_qty', 'safety_stock', 'reorder_qty'] as $k) {
            $out[$k] = isset($row[$k]) && $row[$k] !== null ? Qty::fromDb($row[$k]) : null;
        }
        return $out;
    }

    /**
     * Ledger rows within the user's warehouses.
     * Filters: warehouse, product, variant, location, batch, serial, type, source, from, to, q.
     * @param array<string, mixed> $user @param array<string, mixed> $f
     * @return array{0: string, 1: list<mixed>} WHERE clause on alias m
     */
    public function movementWhere(array $user, array $f): array
    {
        [$w, $p] = InventoryScope::sql($user, 'm.warehouse_id');
        $sql = $w;
        foreach (['warehouse' => 'm.warehouse_id', 'product' => 'm.product_id', 'variant' => 'm.variant_id', 'location' => 'm.location_id', 'batch' => 'm.batch_id'] as $k => $col) {
            if (($f[$k] ?? '') !== '') {
                $sql .= " AND {$col} = ?";
                $p[] = (int) $f[$k];
            }
        }
        if (($f['type'] ?? '') !== '' && isset(InventoryLedger::TYPES[$f['type']])) {
            $sql .= ' AND m.movement_type = ?';
            $p[] = $f['type'];
        }
        if (($f['source'] ?? '') !== '') {
            $sql .= ' AND m.source_type = ?';
            $p[] = (string) $f['source'];
        }
        if (($f['state'] ?? '') !== '' && isset(InventoryLedger::STATES[$f['state']])) {
            $sql .= ' AND m.stock_state = ?';
            $p[] = $f['state'];
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (($f[$k] ?? '') !== '' && BusinessDate::isValid((string) $f[$k])) {
                $sql .= " AND m.movement_date {$op} ?";
                $p[] = $f[$k];
            }
        }
        if (($f['serial'] ?? '') !== '') {
            $sql .= ' AND m.id IN (SELECT sm.movement_id FROM inventory_serial_movements sm JOIN inventory_serial_numbers sn ON sn.id = sm.serial_id WHERE sn.serial_no LIKE ?)';
            $p[] = '%' . strtoupper(trim((string) $f['serial'])) . '%';
        }
        if (($f['category'] ?? '') !== '') {
            $cats = array_merge([(int) $f['category']], (new CatalogService($this->db))->descendantIds((int) $f['category']));
            $sql .= ' AND m.product_id IN (SELECT id FROM products WHERE category_id IN (' . implode(',', array_fill(0, count($cats), '?')) . ') OR subcategory_id IN (' . implode(',', array_fill(0, count($cats), '?')) . '))';
            array_push($p, ...$cats, ...$cats);
        }
        if (($f['brand'] ?? '') !== '') {
            $sql .= ' AND m.product_id IN (SELECT id FROM products WHERE brand_id = ?)';
            $p[] = (int) $f['brand'];
        }
        if (($f['gst'] ?? '') !== '') {
            $sql .= " AND m.warehouse_id IN (SELECT warehouse_id FROM warehouse_gst_mappings WHERE status = 'active' AND gst_registration_id = ?)";
            $p[] = (int) $f['gst'];
        }
        if (($f['activity'] ?? '') !== '') {
            $sql .= " AND m.warehouse_id IN (SELECT warehouse_id FROM warehouse_business_mappings WHERE status = 'active' AND business_activity_id = ?)";
            $p[] = (int) $f['activity'];
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $sql .= ' AND (m.reference_no LIKE ? OR m.movement_no LIKE ?)';
            array_push($p, "%{$q}%", "%{$q}%");
        }
        return [$sql, $p];
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f @return list<array<string, mixed>> */
    public function movements(array $user, array $f, int $limit = 500, int $offset = 0): array
    {
        [$w, $p] = $this->movementWhere($user, $f);
        return $this->db->select("SELECT m.*, p.name AS product_name, p.product_code, p.unit, v.name AS variant_name, wh.code AS warehouse_code, wh.name AS warehouse_name,
                l.code AS location_code, b.batch_no, u.name AS created_by_name, a.name AS approved_by_name, o.movement_no AS reverses_no
            FROM inventory_stock_movements m JOIN products p ON p.id = m.product_id LEFT JOIN product_variants v ON v.id = m.variant_id JOIN warehouses wh ON wh.id = m.warehouse_id
            LEFT JOIN warehouse_locations l ON l.id = m.location_id LEFT JOIN inventory_batches b ON b.id = m.batch_id LEFT JOIN users u ON u.id = m.created_by LEFT JOIN users a ON a.id = m.approved_by
            LEFT JOIN inventory_stock_movements o ON o.id = m.reversal_of_id
            WHERE {$w} ORDER BY m.movement_date DESC, m.id DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset), $p);
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f */
    public function movementCount(array $user, array $f): int
    {
        [$w, $p] = $this->movementWhere($user, $f);
        return (int) $this->db->scalar("SELECT COUNT(*) FROM inventory_stock_movements m WHERE {$w}", $p);
    }

    /** Where clause for stock items. @param array<string, mixed> $user @param array<string, mixed> $f @return array{0: string, 1: list<mixed>} */
    private function where(array $user, array $f): array
    {
        [$w, $p] = InventoryScope::sql($user, 'i.warehouse_id');
        $sql = $w;
        foreach (['warehouse' => 'i.warehouse_id', 'product' => 'i.product_id', 'variant' => 'i.variant_id'] as $k => $col) {
            if (($f[$k] ?? '') !== '') {
                $sql .= " AND {$col} = ?";
                $p[] = (int) $f[$k];
            }
        }
        if (($f['batch'] ?? '') !== '') {
            $sql .= ' AND i.id IN (SELECT stock_item_id FROM inventory_stock_balances WHERE batch_id = ? AND quantity <> 0)';
            $p[] = (int) $f['batch'];
        }
        if (($f['gst'] ?? '') !== '') {
            $sql .= " AND i.warehouse_id IN (SELECT warehouse_id FROM warehouse_gst_mappings WHERE status = 'active' AND gst_registration_id = ?)";
            $p[] = (int) $f['gst'];
        }
        if (($f['activity'] ?? '') !== '') {
            $sql .= " AND i.warehouse_id IN (SELECT warehouse_id FROM warehouse_business_mappings WHERE status = 'active' AND business_activity_id = ?)";
            $p[] = (int) $f['activity'];
        }
        $sql .= $this->productFilterSql($f, $p, 'p');
        return [$sql, $p];
    }

    /** @param array<string, mixed> $f @param list<mixed> $p */
    private function productFilterSql(array $f, array &$p, string $alias = 'p'): string
    {
        $sql = '';
        if (($f['category'] ?? '') !== '') {
            $cats = array_merge([(int) $f['category']], (new CatalogService($this->db))->descendantIds((int) $f['category']));
            $in = implode(',', array_fill(0, count($cats), '?'));
            $sql .= " AND ({$alias}.category_id IN ({$in}) OR {$alias}.subcategory_id IN ({$in}))";
            array_push($p, ...$cats, ...$cats);
        }
        if (($f['brand'] ?? '') !== '') {
            $sql .= " AND {$alias}.brand_id = ?";
            $p[] = (int) $f['brand'];
        }
        if (($f['product'] ?? '') !== '' && $alias === 'p' && !str_contains($sql, '.product_id')) {
            $sql .= " AND {$alias}.id = ?";
            $p[] = (int) $f['product'];
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $sql .= " AND ({$alias}.name LIKE ? OR {$alias}.product_code LIKE ? OR {$alias}.sku LIKE ?)";
            array_push($p, "%{$q}%", "%{$q}%", "%{$q}%");
        }
        return $sql;
    }

    /** Default valuation method label for display. */
    public static function defaultMethod(): string
    {
        return InventoryLedger::METHODS[(string) App::settings()->get('inventory_valuation_method', 'weighted_average')] ?? 'Weighted average cost';
    }
}
