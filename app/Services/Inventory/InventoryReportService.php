<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\HttpException;
use App\Services\Export\ReportFields;
use App\Support\BusinessDate;
use App\Support\Money;
use App\Support\Qty;

/**
 * The twenty inventory reports. Every report reads the stock ledger and its summaries, is
 * limited to the user's warehouses, hides cost columns from users without
 * inventory.view_stock_cost and applies the stock_value / unit_cost field rules — on screen
 * and in CSV / Excel / PDF exports.
 */
final class InventoryReportService
{
    public const REPORTS = [
        'summary' => ['Complete stock summary', 'Every stocked item per warehouse: on hand, available, reserved, damaged, in transit, expired and value.'],
        'warehouse' => ['Warehouse-wise stock', 'Totals per warehouse.'],
        'product' => ['Product-wise stock', 'Totals per product across your warehouses, including products with no stock.'],
        'variant' => ['Variant-wise stock', 'Totals per variant for products stocked per variant.'],
        'ledger' => ['Stock ledger', 'Every posted stock movement with running reference, cost and who posted it.'],
        'inward' => ['Stock inward', 'Posted stock inward lines (purchase receipts, customer returns, other receipts).'],
        'outward' => ['Stock outward', 'Posted stock outward lines (dispatches, supplier returns, write-offs, consumption).'],
        'transfers' => ['Stock transfers', 'Transfer lines with sent, received, damaged and lost quantities.'],
        'adjustments' => ['Stock adjustments', 'Adjustment lines with system, physical and difference quantities.'],
        'opening' => ['Opening stock', 'Posted opening stock lines with value.'],
        'batch' => ['Batch-wise stock', 'Stock per batch, warehouse and condition.'],
        'expiry' => ['Expiry report', 'Batches that are expired or expire within the warning period, with stock.'],
        'serials' => ['Serial number report', 'Serial numbers with status, warehouse and location.'],
        'reserved' => ['Reserved stock', 'Open reservation lines with reserved, fulfilled and outstanding quantities.'],
        'available' => ['Available stock', 'Items with free stock (sellable minus reserved, expired and on-hold).'],
        'low' => ['Low stock & suggested reorder', 'Items at or below their reorder level or minimum, with a suggested quantity and preferred supplier. No purchase order is created.'],
        'out' => ['Out-of-stock', 'Inventory products and variants with no free stock.'],
        'valuation' => ['Inventory valuation', 'Quantity, average cost and value per item, with the valuation method.'],
        'movement_summary' => ['Stock movement summary', 'Opening balance, receipts, issues and closing balance per item for a date range.'],
        'damaged' => ['Damaged stock', 'Stock held as damaged or awaiting inspection, per location and batch.'],
    ];
    /** Reports that use a date range. */
    public const DATED = ['ledger', 'inward', 'outward', 'transfers', 'adjustments', 'opening', 'movement_summary'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f @return array<string, mixed> */
    public function run(array $user, string $key, array $f): array
    {
        if (!isset(self::REPORTS[$key])) {
            throw new HttpException(404, 'Report not found.');
        }
        $f = $this->dates($key, $f);
        $out = match ($key) {
            'summary' => $this->items($user, $f, false),
            'warehouse' => $this->warehouses($user, $f),
            'product' => $this->products($user, $f, false),
            'variant' => $this->products($user, $f, true),
            'ledger' => $this->ledger($user, $f),
            'inward', 'outward', 'opening' => $this->entries($user, $f, $key),
            'transfers' => $this->transfers($user, $f),
            'adjustments' => $this->adjustments($user, $f),
            'batch' => $this->batches($user, $f, false),
            'expiry' => $this->batches($user, $f, true),
            'serials' => $this->serials($user, $f),
            'reserved' => $this->reserved($user, $f),
            'available' => $this->items($user, $f, true),
            'low' => $this->low($user, $f),
            'out' => $this->out($user, $f),
            'valuation' => $this->valuation($user, $f),
            'movement_summary' => $this->movementSummary($user, $f),
            'damaged' => $this->damaged($user, $f),
        };
        $out += ['totals' => null, 'notice' => null];
        if (!App::gate()->allows($user, 'inventory.view_stock_cost')) {
            foreach ($out['columns'] as $col => $def) {
                if (in_array($def['field'][1] ?? '', ['stock_value', 'unit_cost'], true)) {
                    unset($out['columns'][$col]);
                    foreach ($out['rows'] as &$r) {
                        unset($r[$col]);
                    }
                    unset($r);
                    if (is_array($out['totals'])) {
                        unset($out['totals'][$col]);
                    }
                }
            }
        }
        $out = ReportFields::apply($user, $out);
        $out['title'] = self::REPORTS[$key][0];
        $scope = InventoryScope::warehouseIds($user);
        $out['meta'] = [($scope === null ? 'All warehouses' : count($scope) . ' warehouse' . (count($scope) === 1 ? '' : 's') . ' in your scope') . ' · generated by ' . $user['name'] . ' · ' . format_datetime(now())];
        if (in_array($key, self::DATED, true)) {
            $out['meta'][] = BusinessDate::format((string) $f['from']) . ' – ' . BusinessDate::format((string) $f['to']);
        }
        return $out;
    }

    /** @param array<string, mixed> $f @return array<string, mixed> */
    private function dates(string $key, array $f): array
    {
        $today = BusinessDate::today();
        $f['to'] = BusinessDate::isValid((string) ($f['to'] ?? '')) ? (string) $f['to'] : $today;
        $f['from'] = BusinessDate::isValid((string) ($f['from'] ?? '')) ? (string) $f['from'] : (in_array($key, self::DATED, true) ? BusinessDate::addDays($f['to'], -29) : '');
        if ($f['from'] !== '' && $f['from'] > $f['to']) {
            [$f['from'], $f['to']] = [$f['to'], $f['from']];
        }
        return $f;
    }

    private static function q(int $q): string
    {
        return Qty::toInput($q);
    }

    /** @return array<string, array<string, mixed>> */
    private static function itemCols(): array
    {
        return ['warehouse' => ['label' => 'Warehouse'], 'product' => ['label' => 'Product'], 'variant' => ['label' => 'Variant'], 'sku' => ['label' => 'SKU / code'], 'unit' => ['label' => 'Unit']];
    }

    /** @param array<string, mixed> $i @return array<string, mixed> */
    private static function itemRow(array $i): array
    {
        return ['warehouse' => $i['warehouse_code'] . ' · ' . $i['warehouse_name'], 'product' => $i['product_name'], 'variant' => $i['variant_name'] ?? '',
            'sku' => ($i['variant_sku'] ?? '') ?: ($i['sku'] ?: $i['product_code']), 'unit' => $i['unit']];
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f @return array<string, mixed> */
    private function items(array $user, array $f, bool $availableOnly): array
    {
        $rows = [];
        $tot = ['on_hand' => 0, 'available' => 0, 'reserved' => 0, 'damaged' => 0, 'quarantine' => 0, 'in_transit' => 0, 'expired' => 0, 'value' => 0];
        foreach ((new StockQueryService($this->db))->items($user, $f) as $i) {
            if ($availableOnly ? $i['available'] <= 0 : ($i['on_hand'] === 0 && $i['reserved'] === 0)) {
                continue;
            }
            $rows[] = self::itemRow($i) + ['on_hand' => self::q($i['on_hand']), 'available' => self::q($i['available']), 'reserved' => self::q($i['reserved']),
                'damaged' => self::q($i['damaged']), 'quarantine' => self::q($i['quarantine']), 'in_transit' => self::q($i['in_transit']), 'expired' => self::q($i['expired']),
                'value' => $i['value'], 'status' => StockQueryService::STOCK_STATUSES[$i['stock_status']]];
            foreach ($tot as $k => $_) {
                $tot[$k] += $i[$k];
            }
        }
        $totals = ['warehouse' => 'Total'];
        foreach ($tot as $k => $v) {
            $totals[$k] = $k === 'value' ? $v : self::q($v);
        }
        return ['columns' => self::itemCols() + ['on_hand' => ['label' => 'On hand', 'type' => 'number'], 'available' => ['label' => 'Available', 'type' => 'number'],
            'reserved' => ['label' => 'Reserved', 'type' => 'number'], 'damaged' => ['label' => 'Damaged', 'type' => 'number'], 'quarantine' => ['label' => 'Inspection', 'type' => 'number'],
            'in_transit' => ['label' => 'In transit', 'type' => 'number'], 'expired' => ['label' => 'Expired', 'type' => 'number'],
            'value' => ['label' => 'Value', 'type' => 'money', 'field' => ['inventory', 'stock_value']], 'status' => ['label' => 'Status']],
            'rows' => $rows, 'totals' => $rows !== [] ? $totals : null, 'notice' => 'Quantities are in each product\'s stock unit; totals add different units together and are indicative only.'];
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f @return array<string, mixed> */
    private function warehouses(array $user, array $f): array
    {
        $by = [];
        foreach ((new WarehouseService($this->db))->list($user, ['archived' => 'with']) as $w) {
            if (($f['warehouse'] ?? '') !== '' && (int) $f['warehouse'] !== (int) $w['id']) {
                continue;
            }
            $by[(int) $w['id']] = ['warehouse' => $w['code'] . ' · ' . $w['name'], 'type' => $w['type_name'], 'status' => ucfirst((string) $w['status']), 'items' => 0,
                'on_hand' => 0, 'available' => 0, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0, 'value' => 0];
        }
        foreach ((new StockQueryService($this->db))->items($user, $f) as $i) {
            $w = (int) $i['warehouse_id'];
            if (!isset($by[$w])) {
                continue;
            }
            $by[$w]['items'] += $i['on_hand'] !== 0 ? 1 : 0;
            foreach (['on_hand', 'available', 'reserved', 'damaged', 'in_transit', 'value'] as $k) {
                $by[$w][$k] += $i[$k];
            }
        }
        $tot = ['warehouse' => 'Total', 'items' => 0, 'value' => 0];
        $rows = [];
        foreach ($by as $r) {
            $tot['items'] += $r['items'];
            $tot['value'] += $r['value'];
            foreach (['on_hand', 'available', 'reserved', 'damaged', 'in_transit'] as $k) {
                $r[$k] = self::q($r[$k]);
            }
            $rows[] = $r;
        }
        return ['columns' => ['warehouse' => ['label' => 'Warehouse'], 'type' => ['label' => 'Type'], 'status' => ['label' => 'Status'], 'items' => ['label' => 'Items in stock', 'type' => 'number'],
            'on_hand' => ['label' => 'On hand', 'type' => 'number'], 'available' => ['label' => 'Available', 'type' => 'number'], 'reserved' => ['label' => 'Reserved', 'type' => 'number'],
            'damaged' => ['label' => 'Damaged', 'type' => 'number'], 'in_transit' => ['label' => 'In transit (sent)', 'type' => 'number'],
            'value' => ['label' => 'Value', 'type' => 'money', 'field' => ['inventory', 'stock_value']]], 'rows' => $rows, 'totals' => $rows !== [] ? $tot : null];
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f @return array<string, mixed> */
    private function products(array $user, array $f, bool $variants): array
    {
        $rows = [];
        $agg = [];
        foreach ((new StockQueryService($this->db))->products($user, $f) as $r) {
            if ($variants && $r['variant_id'] === null) {
                continue;
            }
            $k = $variants ? $r['product_id'] . ':' . $r['variant_id'] : (string) $r['product_id'];
            if (!isset($agg[$k])) {
                $agg[$k] = ['product' => $r['product_name'], 'code' => $r['product_code'], 'variant' => $r['variant_name'] ?? '', 'sku' => ($r['variant_sku'] ?? '') ?: ($r['sku'] ?? ''), 'unit' => $r['unit'],
                    'warehouses' => 0, 'on_hand' => 0, 'available' => 0, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0, 'value' => 0, 'status' => []];
            }
            foreach (['on_hand', 'available', 'reserved', 'damaged', 'in_transit', 'value'] as $x) {
                $agg[$k][$x] += $r[$x];
            }
            $agg[$k]['warehouses'] = max($agg[$k]['warehouses'], $r['warehouses']);
            $agg[$k]['status'][] = $r['stock_status'];
        }
        $value = 0;
        foreach ($agg as $a) {
            $st = in_array('negative', $a['status'], true) ? 'negative' : ($a['available'] <= 0 ? 'out' : (in_array('low', $a['status'], true) ? 'low' : 'in_stock'));
            $a['status'] = StockQueryService::STOCK_STATUSES[$st];
            $value += $a['value'];
            foreach (['on_hand', 'available', 'reserved', 'damaged', 'in_transit'] as $x) {
                $a[$x] = self::q($a[$x]);
            }
            if (!$variants) {
                unset($a['variant'], $a['sku']);
            }
            $rows[] = $a;
        }
        $cols = ['product' => ['label' => 'Product'], 'code' => ['label' => 'Code']] + ($variants ? ['variant' => ['label' => 'Variant'], 'sku' => ['label' => 'Variant SKU']] : [])
            + ['unit' => ['label' => 'Unit'], 'warehouses' => ['label' => 'Warehouses with stock', 'type' => 'number'], 'on_hand' => ['label' => 'On hand', 'type' => 'number'],
            'available' => ['label' => 'Available', 'type' => 'number'], 'reserved' => ['label' => 'Reserved', 'type' => 'number'], 'damaged' => ['label' => 'Damaged', 'type' => 'number'],
            'in_transit' => ['label' => 'In transit', 'type' => 'number'], 'value' => ['label' => 'Value', 'type' => 'money', 'field' => ['inventory', 'stock_value']], 'status' => ['label' => 'Status']];
        return ['columns' => $cols, 'rows' => $rows, 'totals' => $rows !== [] ? ['product' => 'Total', 'value' => $value] : null,
            'notice' => $variants && $rows === [] ? 'No product is stocked per variant.' : null];
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f @return array<string, mixed> */
    private function ledger(array $user, array $f): array
    {
        $rows = [];
        $value = 0;
        $q = new StockQueryService($this->db);
        $count = $q->movementCount($user, $f);
        foreach ($q->movements($user, $f, 5000) as $m) {
            $value += Money::fromDb($m['total_cost']);
            $rows[] = ['date' => BusinessDate::format($m['movement_date']), 'no' => $m['movement_no'], 'type' => InventoryLedger::TYPES[$m['movement_type']] ?? $m['movement_type'],
                'reference' => $m['reference_no'] . ($m['reverses_no'] ? ' (reverses ' . $m['reverses_no'] . ')' : ''), 'warehouse' => $m['warehouse_code'], 'location' => $m['location_code'] ?? '',
                'product' => $m['product_name'] . ($m['variant_name'] ? ' — ' . $m['variant_name'] : ''), 'batch' => $m['batch_no'] ?? '', 'state' => InventoryLedger::STATES[$m['stock_state']] ?? $m['stock_state'],
                'in' => Qty::fromDb($m['qty_in']) ? self::q(Qty::fromDb($m['qty_in'])) : '', 'out' => Qty::fromDb($m['qty_out']) ? self::q(Qty::fromDb($m['qty_out'])) : '', 'unit' => $m['unit'],
                'unit_cost' => Qty::toInput(Qty::fromDb($m['unit_cost'])), 'value' => Money::fromDb($m['total_cost']), 'by' => $m['created_by_name'] ?? '', 'approved_by' => $m['approved_by_name'] ?? ''];
        }
        return ['columns' => ['date' => ['label' => 'Date', 'type' => 'date'], 'no' => ['label' => 'Movement'], 'type' => ['label' => 'Type'], 'reference' => ['label' => 'Reference'],
            'warehouse' => ['label' => 'Warehouse'], 'location' => ['label' => 'Location'], 'product' => ['label' => 'Product'], 'batch' => ['label' => 'Batch'], 'state' => ['label' => 'Condition'],
            'in' => ['label' => 'In', 'type' => 'number'], 'out' => ['label' => 'Out', 'type' => 'number'], 'unit' => ['label' => 'Unit'],
            'unit_cost' => ['label' => 'Unit cost', 'type' => 'number', 'field' => ['inventory', 'unit_cost']], 'value' => ['label' => 'Value change', 'type' => 'money', 'field' => ['inventory', 'stock_value']],
            'by' => ['label' => 'Posted by'], 'approved_by' => ['label' => 'Approved by']],
            'rows' => $rows, 'totals' => $rows !== [] ? ['date' => 'Net', 'value' => $value] : null, 'notice' => $count > 5000 ? "Showing the latest 5,000 of {$count} movements — narrow the filters." : null];
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f @return array<string, mixed> */
    private function entries(array $user, array $f, string $direction): array
    {
        [$w, $p] = InventoryScope::sql($user, 'e.warehouse_id');
        $sql = "SELECT e.entry_no, e.entry_date, e.entry_type, e.status, e.source_reference, e.reason, w.code AS warehouse_code, s.name AS supplier_name, i.*, p.name AS product_name, p.product_code,
                v.name AS variant_name, l.code AS location_code, COALESCE(b.batch_no, i.batch_no) AS batch_label, u.name AS posted_by_name
            FROM inventory_entry_items i JOIN inventory_entries e ON e.id = i.entry_id JOIN warehouses w ON w.id = e.warehouse_id JOIN products p ON p.id = i.product_id
            LEFT JOIN product_variants v ON v.id = i.variant_id LEFT JOIN warehouse_locations l ON l.id = i.location_id LEFT JOIN inventory_batches b ON b.id = i.batch_id
            LEFT JOIN suppliers s ON s.id = e.supplier_id LEFT JOIN users u ON u.id = e.posted_by
            WHERE {$w} AND e.direction = ? AND e.entry_date BETWEEN ? AND ?";
        array_push($p, $direction, $f['from'], $f['to']);
        $sql .= ($f['status'] ?? '') !== '' && isset(EntryService::STATUSES[$f['status']]) ? ' AND e.status = ?' : " AND e.status IN ('posted', 'reversed')";
        if (($f['status'] ?? '') !== '' && isset(EntryService::STATUSES[$f['status']])) {
            $p[] = $f['status'];
        }
        $sql .= $this->lineFilters($f, $p, 'e.warehouse_id', 'i');
        if (($f['type'] ?? '') !== '' && isset(EntryService::TYPES[$direction][$f['type']])) {
            $sql .= ' AND e.entry_type = ?';
            $p[] = $f['type'];
        }
        $rows = [];
        $value = 0;
        foreach ($this->db->select($sql . ' ORDER BY e.entry_date DESC, e.id DESC, i.line_no LIMIT 5000', $p) as $r) {
            $v = $r['total_cost'] !== null ? Money::fromDb($r['total_cost']) : null;
            $value += $r['status'] === 'posted' ? (int) $v : 0;
            $rows[] = ['date' => BusinessDate::format($r['entry_date']), 'no' => $r['entry_no'], 'type' => EntryService::TYPES[$direction][$r['entry_type']] ?? $r['entry_type'],
                'status' => EntryService::STATUSES[$r['status']], 'warehouse' => $r['warehouse_code'], 'location' => $r['location_code'] ?? '',
                'product' => $r['product_name'] . ($r['variant_name'] ? ' — ' . $r['variant_name'] : ''), 'batch' => $r['batch_label'] ?? '',
                'qty' => self::q(Qty::fromDb($r['base_quantity'])), 'entered' => Qty::toInput(Qty::fromDb($r['quantity'])) . ' ' . $r['unit_code'],
                'condition' => $r['return_condition'] ? EntryService::RETURN_CONDITIONS[$r['return_condition']] : '', 'supplier' => $r['supplier_name'] ?? '',
                'reference' => $r['source_reference'] ?? '', 'reason' => $r['reason'] ?? '',
                'unit_cost' => $r['unit_cost'] !== null ? Qty::toInput(Qty::fromDb($r['unit_cost'])) : '', 'value' => $v, 'by' => $r['posted_by_name'] ?? ''];
        }
        $cols = ['date' => ['label' => 'Date', 'type' => 'date'], 'no' => ['label' => 'Document'], 'type' => ['label' => 'Type'], 'status' => ['label' => 'Status'], 'warehouse' => ['label' => 'Warehouse'],
            'location' => ['label' => 'Location'], 'product' => ['label' => 'Product'], 'batch' => ['label' => 'Batch'], 'qty' => ['label' => 'Qty (stock unit)', 'type' => 'number'], 'entered' => ['label' => 'Entered as'],
            'condition' => ['label' => 'Return condition'], 'supplier' => ['label' => 'Supplier'], 'reference' => ['label' => 'Reference'], 'reason' => ['label' => 'Reason'],
            'unit_cost' => ['label' => 'Unit cost', 'type' => 'number', 'field' => ['inventory', 'unit_cost']], 'value' => ['label' => 'Value', 'type' => 'money', 'field' => ['inventory', 'stock_value']], 'by' => ['label' => 'Posted by']];
        if ($direction !== 'inward') {
            unset($cols['condition']);
        }
        if ($direction === 'opening') {
            unset($cols['supplier'], $cols['type']);
        }
        return ['columns' => $cols, 'rows' => $rows, 'totals' => $rows !== [] ? ['date' => 'Total posted', 'value' => $value] : null];
    }

    /** @param array<string, mixed> $f @param list<mixed> $p */
    private function lineFilters(array $f, array &$p, string $warehouseCol, string $alias): string
    {
        $sql = '';
        foreach (['warehouse' => $warehouseCol, 'product' => "{$alias}.product_id", 'variant' => "{$alias}.variant_id", 'batch' => "{$alias}.batch_id"] as $k => $col) {
            if (($f[$k] ?? '') !== '') {
                $sql .= " AND {$col} = ?";
                $p[] = (int) $f[$k];
            }
        }
        if (($f['category'] ?? '') !== '') {
            $cats = array_merge([(int) $f['category']], (new \App\Services\Product\CatalogService($this->db))->descendantIds((int) $f['category']));
            $in = implode(',', array_fill(0, count($cats), '?'));
            $sql .= " AND {$alias}.product_id IN (SELECT id FROM products WHERE category_id IN ({$in}) OR subcategory_id IN ({$in}))";
            array_push($p, ...$cats, ...$cats);
        }
        if (($f['brand'] ?? '') !== '') {
            $sql .= " AND {$alias}.product_id IN (SELECT id FROM products WHERE brand_id = ?)";
            $p[] = (int) $f['brand'];
        }
        if (($f['gst'] ?? '') !== '') {
            $sql .= " AND {$warehouseCol} IN (SELECT warehouse_id FROM warehouse_gst_mappings WHERE status = 'active' AND gst_registration_id = ?)";
            $p[] = (int) $f['gst'];
        }
        if (($f['activity'] ?? '') !== '') {
            $sql .= " AND {$warehouseCol} IN (SELECT warehouse_id FROM warehouse_business_mappings WHERE status = 'active' AND business_activity_id = ?)";
            $p[] = (int) $f['activity'];
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $sql .= " AND {$alias}.product_id IN (SELECT id FROM products WHERE name LIKE ? OR product_code LIKE ? OR sku LIKE ?)";
            array_push($p, "%{$q}%", "%{$q}%", "%{$q}%");
        }
        return $sql;
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f @return array<string, mixed> */
    private function transfers(array $user, array $f): array
    {
        [$a, $ap] = InventoryScope::sql($user, 't.from_warehouse_id');
        [$b, $bp] = InventoryScope::sql($user, 't.to_warehouse_id');
        $p = array_merge($ap, $bp, [$f['from'], $f['to']]);
        $sql = "SELECT t.transfer_no, t.transfer_date, t.transfer_status, fw.code AS from_code, tw.code AS to_code, i.*, p.name AS product_name, v.name AS variant_name, b.batch_no
            FROM inventory_transfer_items i JOIN inventory_transfers t ON t.id = i.transfer_id JOIN warehouses fw ON fw.id = t.from_warehouse_id JOIN warehouses tw ON tw.id = t.to_warehouse_id
            JOIN products p ON p.id = i.product_id LEFT JOIN product_variants v ON v.id = i.variant_id LEFT JOIN inventory_batches b ON b.id = i.batch_id
            WHERE ({$a} OR {$b}) AND t.transfer_date BETWEEN ? AND ?";
        if (($f['status'] ?? '') !== '' && isset(TransferService::STATUSES[$f['status']])) {
            $sql .= ' AND t.transfer_status = ?';
            $p[] = $f['status'];
        }
        if (($f['warehouse'] ?? '') !== '') {
            $sql .= ' AND (t.from_warehouse_id = ? OR t.to_warehouse_id = ?)';
            array_push($p, (int) $f['warehouse'], (int) $f['warehouse']);
        }
        $sql .= $this->lineFilters(array_diff_key($f, ['warehouse' => 1]), $p, 't.from_warehouse_id', 'i');
        $rows = [];
        foreach ($this->db->select($sql . ' ORDER BY t.transfer_date DESC, t.id DESC, i.line_no LIMIT 5000', $p) as $r) {
            $sent = Qty::fromDb($r['quantity']);
            $done = Qty::fromDb($r['qty_received']) + Qty::fromDb($r['qty_damaged']) + Qty::fromDb($r['qty_lost']);
            $rows[] = ['date' => BusinessDate::format($r['transfer_date']), 'no' => $r['transfer_no'], 'status' => TransferService::STATUSES[$r['transfer_status']] ?? $r['transfer_status'],
                'from' => $r['from_code'], 'to' => $r['to_code'], 'product' => $r['product_name'] . ($r['variant_name'] ? ' — ' . $r['variant_name'] : ''), 'batch' => $r['batch_no'] ?? '',
                'qty' => self::q($sent), 'received' => self::q(Qty::fromDb($r['qty_received'])), 'damaged' => self::q(Qty::fromDb($r['qty_damaged'])), 'lost' => self::q(Qty::fromDb($r['qty_lost'])),
                'outstanding' => in_array($r['transfer_status'], ['in_transit', 'partially_received'], true) ? self::q($sent - $done) : '',
                'value' => $r['total_cost'] !== null ? Money::fromDb($r['total_cost']) : null];
        }
        return ['columns' => ['date' => ['label' => 'Date', 'type' => 'date'], 'no' => ['label' => 'Transfer'], 'status' => ['label' => 'Status'], 'from' => ['label' => 'From'], 'to' => ['label' => 'To'],
            'product' => ['label' => 'Product'], 'batch' => ['label' => 'Batch'], 'qty' => ['label' => 'Sent', 'type' => 'number'], 'received' => ['label' => 'Received', 'type' => 'number'],
            'damaged' => ['label' => 'Received damaged', 'type' => 'number'], 'lost' => ['label' => 'Lost', 'type' => 'number'], 'outstanding' => ['label' => 'Still in transit', 'type' => 'number'],
            'value' => ['label' => 'Value sent', 'type' => 'money', 'field' => ['inventory', 'stock_value']]], 'rows' => $rows];
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f @return array<string, mixed> */
    private function adjustments(array $user, array $f): array
    {
        [$w, $p] = InventoryScope::sql($user, 'a.warehouse_id');
        array_push($p, $f['from'], $f['to']);
        $sql = "SELECT a.adjustment_no, a.adjustment_date, a.reason, a.status, w.code AS warehouse_code, i.*, p.name AS product_name, v.name AS variant_name, l.code AS location_code, b.batch_no
            FROM inventory_adjustment_items i JOIN inventory_adjustments a ON a.id = i.adjustment_id JOIN warehouses w ON w.id = a.warehouse_id JOIN products p ON p.id = i.product_id
            LEFT JOIN product_variants v ON v.id = i.variant_id LEFT JOIN warehouse_locations l ON l.id = i.location_id LEFT JOIN inventory_batches b ON b.id = i.batch_id
            WHERE {$w} AND a.adjustment_date BETWEEN ? AND ?";
        if (($f['status'] ?? '') !== '' && isset(AdjustmentService::STATUSES[$f['status']])) {
            $sql .= ' AND a.status = ?';
            $p[] = $f['status'];
        } else {
            $sql .= " AND a.status IN ('posted', 'reversed')";
        }
        $sql .= $this->lineFilters($f, $p, 'a.warehouse_id', 'i');
        $rows = [];
        $value = 0;
        foreach ($this->db->select($sql . ' ORDER BY a.adjustment_date DESC, a.id DESC, i.line_no LIMIT 5000', $p) as $r) {
            $v = $r['total_cost'] !== null ? Money::fromDb($r['total_cost']) : null;
            $value += $r['status'] === 'posted' ? (int) $v : 0;
            $count = $r['line_type'] === 'count';
            $rows[] = ['date' => BusinessDate::format($r['adjustment_date']), 'no' => $r['adjustment_no'], 'reason' => AdjustmentService::REASONS[$r['reason']] ?? $r['reason'],
                'status' => AdjustmentService::STATUSES[$r['status']] ?? $r['status'], 'warehouse' => $r['warehouse_code'], 'location' => $r['location_code'] ?? '',
                'product' => $r['product_name'] . ($r['variant_name'] ? ' — ' . $r['variant_name'] : ''), 'batch' => $r['batch_no'] ?? '',
                'kind' => $count ? 'Count — ' . (AdjustmentService::STATES[$r['stock_state']] ?? $r['stock_state']) : (AdjustmentService::STATES[$r['stock_state']] ?? '') . ' → ' . (AdjustmentService::STATES[$r['to_state']] ?? ''),
                'system' => $count ? self::q(Qty::fromDb($r['system_qty'])) : '', 'physical' => $count && $r['physical_qty'] !== null ? self::q(Qty::fromDb($r['physical_qty'])) : '',
                'difference' => self::q(Qty::fromDb($r['difference_qty'])), 'value' => $v];
        }
        return ['columns' => ['date' => ['label' => 'Date', 'type' => 'date'], 'no' => ['label' => 'Adjustment'], 'reason' => ['label' => 'Reason'], 'status' => ['label' => 'Status'],
            'warehouse' => ['label' => 'Warehouse'], 'location' => ['label' => 'Location'], 'product' => ['label' => 'Product'], 'batch' => ['label' => 'Batch'], 'kind' => ['label' => 'Line'],
            'system' => ['label' => 'System qty', 'type' => 'number'], 'physical' => ['label' => 'Physical qty', 'type' => 'number'], 'difference' => ['label' => 'Difference / moved', 'type' => 'number'],
            'value' => ['label' => 'Value change', 'type' => 'money', 'field' => ['inventory', 'stock_value']]], 'rows' => $rows, 'totals' => $rows !== [] ? ['date' => 'Net posted', 'value' => $value] : null];
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f @return array<string, mixed> */
    private function batches(array $user, array $f, bool $expiryOnly): array
    {
        [$w, $p] = InventoryScope::sql($user, 's.warehouse_id');
        $sql = "SELECT b.batch_no, b.mfg_date, b.expiry_date, b.status, p.name AS product_name, p.unit, v.name AS variant_name, wh.code AS warehouse_code, s.stock_state, SUM(s.quantity) AS qty, sp.name AS supplier_name
            FROM inventory_stock_balances s JOIN inventory_batches b ON b.id = s.batch_id JOIN products p ON p.id = s.product_id LEFT JOIN product_variants v ON v.id = s.variant_id
            JOIN warehouses wh ON wh.id = s.warehouse_id LEFT JOIN suppliers sp ON sp.id = b.supplier_id WHERE {$w} AND s.quantity <> 0";
        if ($expiryOnly) {
            $sql .= ' AND b.expiry_date IS NOT NULL AND b.expiry_date <= ?';
            $p[] = BusinessDate::addDays(BusinessDate::today(), BatchSerialService::warningDays());
        }
        $sql .= $this->lineFilters($f, $p, 's.warehouse_id', 's');
        $rows = [];
        foreach ($this->db->select($sql . ' GROUP BY b.id, b.batch_no, b.mfg_date, b.expiry_date, b.status, p.name, p.unit, v.name, wh.code, s.stock_state, sp.name ORDER BY ' . ($expiryOnly ? 'b.expiry_date, ' : '') . 'p.name, b.batch_no', $p) as $r) {
            $exp = BatchSerialService::expiryStatus($r['expiry_date']);
            $rows[] = ['product' => $r['product_name'] . ($r['variant_name'] ? ' — ' . $r['variant_name'] : ''), 'batch' => $r['batch_no'], 'warehouse' => $r['warehouse_code'],
                'state' => InventoryLedger::STATES[$r['stock_state']] ?? $r['stock_state'], 'qty' => self::q(Qty::fromDb($r['qty'])), 'unit' => $r['unit'],
                'mfg' => $r['mfg_date'] ? BusinessDate::format($r['mfg_date']) : '', 'expiry' => $r['expiry_date'] ? BusinessDate::format($r['expiry_date']) : '',
                'expiry_status' => ['expired' => 'Expired', 'near_expiry' => 'Near expiry', 'ok' => 'OK', 'none' => '—'][$exp],
                'days' => $r['expiry_date'] ? (string) (int) round((strtotime($r['expiry_date']) - strtotime(BusinessDate::today())) / 86400) : '',
                'status' => BatchSerialService::BATCH_STATUSES[$r['status']] ?? $r['status'], 'supplier' => $r['supplier_name'] ?? ''];
        }
        return ['columns' => ['product' => ['label' => 'Product'], 'batch' => ['label' => 'Batch'], 'warehouse' => ['label' => 'Warehouse'], 'state' => ['label' => 'Condition'],
            'qty' => ['label' => 'Quantity', 'type' => 'number'], 'unit' => ['label' => 'Unit'], 'mfg' => ['label' => 'Mfg date', 'type' => 'date'], 'expiry' => ['label' => 'Expiry', 'type' => 'date'],
            'expiry_status' => ['label' => 'Expiry status'], 'days' => ['label' => 'Days to expiry', 'type' => 'number'], 'status' => ['label' => 'Batch status'], 'supplier' => ['label' => 'Supplier']],
            'rows' => $rows, 'notice' => $expiryOnly ? 'Batches expiring within ' . BatchSerialService::warningDays() . ' days (inventory setting) or already expired. Expired stock cannot be dispatched while expired-stock blocking is on.' : null];
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f @return array<string, mixed> */
    private function serials(array $user, array $f): array
    {
        $rows = [];
        foreach ((new BatchSerialService($this->db))->serials($user, $f + ['limit' => 5000]) as $s) {
            $rows[] = ['serial' => $s['serial_no'], 'product' => $s['product_name'] . (($s['variant_name'] ?? null) ? ' — ' . $s['variant_name'] : ''), 'status' => BatchSerialService::SERIAL_STATUSES[$s['status']] ?? $s['status'],
                'warehouse' => $s['warehouse_code'] ?? '', 'location' => $s['location_code'] ?? '', 'batch' => $s['batch_no'] ?? '', 'state' => $s['status'] === 'in_stock' ? (InventoryLedger::STATES[$s['stock_state']] ?? '') : '',
                'unit_cost' => $s['unit_cost'] !== null ? Qty::toInput(Qty::fromDb($s['unit_cost'])) : '', 'updated' => format_datetime($s['updated_at'])];
        }
        return ['columns' => ['serial' => ['label' => 'Serial number'], 'product' => ['label' => 'Product'], 'status' => ['label' => 'Status'], 'warehouse' => ['label' => 'Warehouse'],
            'location' => ['label' => 'Location'], 'batch' => ['label' => 'Batch'], 'state' => ['label' => 'Condition'], 'unit_cost' => ['label' => 'Unit cost', 'type' => 'number', 'field' => ['inventory', 'unit_cost']],
            'updated' => ['label' => 'Last change']], 'rows' => $rows];
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f @return array<string, mixed> */
    private function reserved(array $user, array $f): array
    {
        [$w, $p] = InventoryScope::sql($user, 'r.warehouse_id');
        $sql = "SELECT r.reservation_no, r.reference_type, r.reference_no, r.reservation_date, r.expires_on, wh.code AS warehouse_code, i.*, p.name AS product_name, p.unit, v.name AS variant_name
            FROM inventory_reservation_items i JOIN inventory_reservations r ON r.id = i.reservation_id JOIN warehouses wh ON wh.id = r.warehouse_id JOIN products p ON p.id = i.product_id
            LEFT JOIN product_variants v ON v.id = i.variant_id WHERE {$w} AND i.status IN ('active', 'partially_fulfilled')";
        $sql .= $this->lineFilters($f, $p, 'r.warehouse_id', 'i');
        $rows = [];
        foreach ($this->db->select($sql . ' ORDER BY r.reservation_date DESC, r.id DESC', $p) as $r) {
            $q = Qty::fromDb($r['quantity']);
            $ful = Qty::fromDb($r['qty_fulfilled']);
            $rel = Qty::fromDb($r['qty_released']);
            $rows[] = ['no' => $r['reservation_no'], 'reference' => (ReservationService::REFERENCE_TYPES[$r['reference_type']] ?? $r['reference_type']) . ' ' . $r['reference_no'],
                'date' => BusinessDate::format($r['reservation_date']), 'expires' => $r['expires_on'] ? BusinessDate::format($r['expires_on']) : '', 'warehouse' => $r['warehouse_code'],
                'product' => $r['product_name'] . ($r['variant_name'] ? ' — ' . $r['variant_name'] : ''), 'qty' => self::q($q), 'fulfilled' => self::q($ful), 'released' => self::q($rel),
                'outstanding' => self::q($q - $ful - $rel), 'unit' => $r['unit'], 'status' => ReservationService::STATUSES[$r['status']] ?? $r['status']];
        }
        return ['columns' => ['no' => ['label' => 'Reservation'], 'reference' => ['label' => 'Reference'], 'date' => ['label' => 'Date', 'type' => 'date'], 'expires' => ['label' => 'Expires', 'type' => 'date'],
            'warehouse' => ['label' => 'Warehouse'], 'product' => ['label' => 'Product'], 'qty' => ['label' => 'Reserved', 'type' => 'number'], 'fulfilled' => ['label' => 'Fulfilled', 'type' => 'number'],
            'released' => ['label' => 'Released', 'type' => 'number'], 'outstanding' => ['label' => 'Outstanding', 'type' => 'number'], 'unit' => ['label' => 'Unit'], 'status' => ['label' => 'Status']], 'rows' => $rows];
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f @return array<string, mixed> */
    private function low(array $user, array $f): array
    {
        $rows = [];
        foreach ((new StockQueryService($this->db))->reorderSuggestions($user, $f) as $i) {
            if ($i['reorder_level'] === null && $i['min_qty'] === null && $i['safety_stock'] === null && $i['stock_status'] !== 'negative') {
                continue;
            }
            $rows[] = self::itemRow($i) + ['available' => self::q($i['available']), 'reorder_level' => $i['reorder_level'] !== null ? self::q($i['reorder_level']) : '',
                'min' => $i['min_qty'] !== null ? self::q($i['min_qty']) : '', 'safety' => $i['safety_stock'] !== null ? self::q($i['safety_stock']) : '',
                'max' => $i['max_qty'] !== null ? self::q($i['max_qty']) : '', 'suggested' => self::q($i['suggested_qty']), 'supplier' => $i['preferred_supplier'] ?? '',
                'status' => StockQueryService::STOCK_STATUSES[$i['stock_status']]];
        }
        return ['columns' => self::itemCols() + ['available' => ['label' => 'Available', 'type' => 'number'], 'reorder_level' => ['label' => 'Reorder level', 'type' => 'number'],
            'min' => ['label' => 'Minimum', 'type' => 'number'], 'safety' => ['label' => 'Safety stock', 'type' => 'number'], 'max' => ['label' => 'Maximum', 'type' => 'number'],
            'suggested' => ['label' => 'Suggested order', 'type' => 'number'], 'supplier' => ['label' => 'Preferred supplier (Phase 6)'], 'status' => ['label' => 'Status']],
            'rows' => $rows, 'notice' => 'Only items with reorder levels, a minimum or safety stock are listed. Suggested quantity: up to the maximum (or reorder level + reorder quantity) minus free stock. This is a suggestion only — no purchase order is created.'];
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f @return array<string, mixed> */
    private function out(array $user, array $f): array
    {
        $rows = [];
        foreach ((new StockQueryService($this->db))->products($user, $f) as $r) {
            if (!in_array($r['stock_status'], ['out', 'negative'], true)) {
                continue;
            }
            $rows[] = ['product' => $r['product_name'], 'code' => $r['product_code'], 'variant' => $r['variant_name'] ?? '', 'unit' => $r['unit'], 'on_hand' => self::q($r['on_hand']),
                'reserved' => self::q($r['reserved']), 'available' => self::q($r['available']), 'reorder_level' => $r['reorder_level'] !== null ? self::q($r['reorder_level']) : '',
                'status' => StockQueryService::STOCK_STATUSES[$r['stock_status']]];
        }
        return ['columns' => ['product' => ['label' => 'Product'], 'code' => ['label' => 'Code'], 'variant' => ['label' => 'Variant'], 'unit' => ['label' => 'Unit'], 'on_hand' => ['label' => 'On hand', 'type' => 'number'],
            'reserved' => ['label' => 'Reserved', 'type' => 'number'], 'available' => ['label' => 'Available', 'type' => 'number'], 'reorder_level' => ['label' => 'Reorder level', 'type' => 'number'],
            'status' => ['label' => 'Status']], 'rows' => $rows, 'notice' => 'Products and variants that track inventory and have no free stock across your warehouses (or the chosen warehouse).'];
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f @return array<string, mixed> */
    private function valuation(array $user, array $f): array
    {
        $rows = [];
        $value = 0;
        foreach ((new StockQueryService($this->db))->items($user, $f) as $i) {
            if ($i['on_hand'] === 0 && $i['value'] === 0) {
                continue;
            }
            $value += $i['value'];
            $rows[] = self::itemRow($i) + ['method' => InventoryLedger::METHODS[InventoryLedger::method($this->db, (int) $i['product_id'])] ?? '', 'qty' => self::q($i['on_hand']),
                'avg_cost' => Qty::toInput($i['avg_cost']), 'last_cost' => Qty::toInput(Qty::fromDb($i['last_unit_cost'])), 'value' => $i['value']];
        }
        return ['columns' => self::itemCols() + ['method' => ['label' => 'Method'], 'qty' => ['label' => 'On hand', 'type' => 'number'],
            'avg_cost' => ['label' => 'Average unit cost', 'type' => 'number', 'field' => ['inventory', 'unit_cost']], 'last_cost' => ['label' => 'Last unit cost', 'type' => 'number', 'field' => ['inventory', 'unit_cost']],
            'value' => ['label' => 'Value', 'type' => 'money', 'field' => ['inventory', 'stock_value']]],
            'rows' => $rows, 'totals' => $rows !== [] ? ['warehouse' => 'Total', 'value' => $value] : null,
            'notice' => 'Values come from the stock ledger (FIFO cost layers or weighted average). No accounting entries are created in this phase.'];
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f @return array<string, mixed> */
    private function movementSummary(array $user, array $f): array
    {
        $q = new StockQueryService($this->db);
        $base = array_diff_key($f, ['from' => 1, 'to' => 1, 'type' => 1]);
        [$wb, $pb] = $q->movementWhere($user, $base + ['to' => BusinessDate::addDays((string) $f['from'], -1)]);
        [$wr, $pr] = $q->movementWhere($user, $base + ['from' => $f['from'], 'to' => $f['to']]);
        $agg = [];
        $key = static fn (array $r): string => $r['warehouse_id'] . ':' . $r['product_id'] . ':' . ($r['variant_id'] ?? 0);
        foreach ($this->db->select("SELECT m.warehouse_id, m.product_id, m.variant_id, SUM(m.qty_in) - SUM(m.qty_out) AS bal, SUM(m.total_cost) AS val FROM inventory_stock_movements m
            WHERE {$wb} AND m.stock_state <> 'in_transit' GROUP BY m.warehouse_id, m.product_id, m.variant_id", $pb) as $r) {
            $agg[$key($r)] = ['ids' => $r, 'open' => Qty::fromDb($r['bal']), 'in' => 0, 'out' => 0, 'internal' => 0, 'close_val' => Money::fromDb($r['val'])];
        }
        $internal = InventoryLedger::INTERNAL_TYPES;
        foreach ($this->db->select("SELECT m.warehouse_id, m.product_id, m.variant_id, m.movement_type, SUM(m.qty_in) AS qi, SUM(m.qty_out) AS qo, SUM(m.total_cost) AS val FROM inventory_stock_movements m
            WHERE {$wr} AND m.stock_state <> 'in_transit' GROUP BY m.warehouse_id, m.product_id, m.variant_id, m.movement_type", $pr) as $r) {
            $k = $key($r);
            $agg[$k] ??= ['ids' => $r, 'open' => 0, 'in' => 0, 'out' => 0, 'internal' => 0, 'close_val' => 0];
            $in = Qty::fromDb($r['qi']);
            $out = Qty::fromDb($r['qo']);
            if (in_array($r['movement_type'], $internal, true)) {
                $agg[$k]['internal'] += $in - $out;
            } else {
                $agg[$k]['in'] += $in;
                $agg[$k]['out'] += $out;
            }
            $agg[$k]['close_val'] += Money::fromDb($r['val']);
        }
        if ($agg === []) {
            return ['columns' => ['warehouse' => ['label' => 'Warehouse']], 'rows' => [], 'notice' => 'No stock movements before or within this period.'];
        }
        $names = [];
        foreach ($this->db->select('SELECT p.id, p.name, p.unit FROM products p WHERE p.id IN (' . implode(',', array_unique(array_map(static fn (array $a): int => (int) $a['ids']['product_id'], $agg))) . ')') as $p) {
            $names[(int) $p['id']] = $p;
        }
        $vars = [];
        foreach ($this->db->select('SELECT id, name FROM product_variants WHERE product_id IN (' . implode(',', array_unique(array_map(static fn (array $a): int => (int) $a['ids']['product_id'], $agg))) . ')') as $v) {
            $vars[(int) $v['id']] = $v['name'];
        }
        $whs = [];
        foreach ($this->db->select('SELECT id, code FROM warehouses') as $w) {
            $whs[(int) $w['id']] = $w['code'];
        }
        $rows = [];
        $value = 0;
        foreach ($agg as $a) {
            $close = $a['open'] + $a['in'] - $a['out'] + $a['internal'];
            if ($a['open'] === 0 && $a['in'] === 0 && $a['out'] === 0 && $a['internal'] === 0) {
                continue;
            }
            $value += $a['close_val'];
            $pid = (int) $a['ids']['product_id'];
            $rows[] = ['warehouse' => $whs[(int) $a['ids']['warehouse_id']] ?? '', 'product' => ($names[$pid]['name'] ?? '') . ($a['ids']['variant_id'] ? ' — ' . ($vars[(int) $a['ids']['variant_id']] ?? '') : ''),
                'unit' => $names[$pid]['unit'] ?? '', 'opening' => self::q($a['open']), 'in' => self::q($a['in']), 'out' => self::q($a['out']), 'internal' => $a['internal'] !== 0 ? self::q($a['internal']) : '',
                'closing' => self::q($close), 'value' => $a['close_val']];
        }
        usort($rows, static fn (array $x, array $y): int => [$x['product'], $x['warehouse']] <=> [$y['product'], $y['warehouse']]);
        return ['columns' => ['warehouse' => ['label' => 'Warehouse'], 'product' => ['label' => 'Product'], 'unit' => ['label' => 'Unit'], 'opening' => ['label' => 'Opening', 'type' => 'number'],
            'in' => ['label' => 'Received', 'type' => 'number'], 'out' => ['label' => 'Issued', 'type' => 'number'], 'internal' => ['label' => 'Transfers (net, received / sent)', 'type' => 'number'],
            'closing' => ['label' => 'Closing', 'type' => 'number'], 'value' => ['label' => 'Closing value', 'type' => 'money', 'field' => ['inventory', 'stock_value']]],
            'rows' => $rows, 'totals' => $rows !== [] ? ['warehouse' => 'Total', 'value' => $value] : null,
            'notice' => 'Received and issued exclude internal moves (transfers in transit, location moves and condition changes); stock in transit is left out until it is received.'];
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f @return array<string, mixed> */
    private function damaged(array $user, array $f): array
    {
        [$w, $p] = InventoryScope::sql($user, 's.warehouse_id');
        $sql = "SELECT s.*, i.stock_value, i.qty_on_hand, p.name AS product_name, p.unit, v.name AS variant_name, wh.code AS warehouse_code, l.code AS location_code, b.batch_no
            FROM inventory_stock_balances s JOIN inventory_stock_items i ON i.id = s.stock_item_id JOIN products p ON p.id = s.product_id LEFT JOIN product_variants v ON v.id = s.variant_id
            JOIN warehouses wh ON wh.id = s.warehouse_id LEFT JOIN warehouse_locations l ON l.id = s.location_id LEFT JOIN inventory_batches b ON b.id = s.batch_id
            WHERE {$w} AND s.stock_state IN ('damaged', 'quarantine') AND s.quantity <> 0";
        $sql .= $this->lineFilters($f, $p, 's.warehouse_id', 's');
        $rows = [];
        $value = 0;
        foreach ($this->db->select($sql . ' ORDER BY p.name, wh.code', $p) as $r) {
            $q = Qty::fromDb($r['quantity']);
            $onHand = Qty::fromDb($r['qty_on_hand']);
            $val = $onHand > 0 ? Qty::value($q, Qty::unitCost(Money::fromDb($r['stock_value']), $onHand)) : 0;
            $value += $val;
            $rows[] = ['warehouse' => $r['warehouse_code'], 'location' => $r['location_code'] ?? '', 'product' => $r['product_name'] . ($r['variant_name'] ? ' — ' . $r['variant_name'] : ''),
                'batch' => $r['batch_no'] ?? '', 'state' => InventoryLedger::STATES[$r['stock_state']], 'qty' => self::q($q), 'unit' => $r['unit'], 'value' => $val];
        }
        return ['columns' => ['warehouse' => ['label' => 'Warehouse'], 'location' => ['label' => 'Location'], 'product' => ['label' => 'Product'], 'batch' => ['label' => 'Batch'],
            'state' => ['label' => 'Condition'], 'qty' => ['label' => 'Quantity', 'type' => 'number'], 'unit' => ['label' => 'Unit'],
            'value' => ['label' => 'Value at average cost', 'type' => 'money', 'field' => ['inventory', 'stock_value']]],
            'rows' => $rows, 'totals' => $rows !== [] ? ['warehouse' => 'Total', 'value' => $value] : null,
            'notice' => 'Damaged and inspection stock is not available for sale. Write it off with a damaged-stock outward, or move it back to available with a condition adjustment.'];
    }
}
