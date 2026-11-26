<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Core\Database;
use App\Support\BusinessDate;
use App\Support\Money;
use App\Support\Qty;

/**
 * Inventory dashboard figures, all computed from the stock ledger summaries and limited to
 * the user's warehouses and the chosen filters. Nothing here is stored or estimated.
 */
final class InventoryDashboardService
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f @return array<string, mixed> */
    public function data(array $user, array $f): array
    {
        $q = new StockQueryService($this->db);
        $items = $q->items($user, $f);
        $t = ['on_hand' => 0, 'sellable' => 0, 'available' => 0, 'reserved' => 0, 'damaged' => 0, 'quarantine' => 0, 'in_transit' => 0, 'expired' => 0, 'value' => 0,
            'low' => 0, 'out' => 0, 'negative' => 0, 'over' => 0, 'in_stock' => 0];
        $products = [];
        $variants = [];
        $byWarehouse = [];
        foreach ($items as $i) {
            foreach (['on_hand', 'sellable', 'available', 'reserved', 'damaged', 'quarantine', 'in_transit', 'expired', 'value'] as $k) {
                $t[$k] += $i[$k];
            }
            $t[$i['stock_status']] = ($t[$i['stock_status']] ?? 0) + 1;
            if ($i['on_hand'] !== 0) {
                $products[(int) $i['product_id']] = true;
                if ($i['variant_id'] !== null) {
                    $variants[(int) $i['variant_id']] = true;
                }
            }
            $w = (int) $i['warehouse_id'];
            $byWarehouse[$w] ??= ['id' => $w, 'code' => $i['warehouse_code'], 'name' => $i['warehouse_name'], 'on_hand' => 0, 'value' => 0, 'items' => 0, 'low' => 0];
            $byWarehouse[$w]['on_hand'] += $i['on_hand'];
            $byWarehouse[$w]['value'] += $i['value'];
            $byWarehouse[$w]['items'] += $i['on_hand'] !== 0 ? 1 : 0;
            $byWarehouse[$w]['low'] += in_array($i['stock_status'], ['low', 'out', 'negative'], true) && ($i['reorder_level'] !== null || $i['min_qty'] !== null || $i['on_hand'] !== 0) ? 1 : 0;
        }
        usort($byWarehouse, static fn (array $a, array $b): int => $b['value'] <=> $a['value'] ?: strcmp((string) $a['name'], (string) $b['name']));
        $low = array_values(array_filter($items, static fn (array $i): bool => in_array($i['stock_status'], ['low', 'out', 'negative'], true)
            && ($i['reorder_level'] !== null || $i['min_qty'] !== null || $i['safety_stock'] !== null || $i['stock_status'] === 'negative')));
        usort($low, static fn (array $a, array $b): int => $a['available'] <=> $b['available']);
        $top = array_values(array_filter($items, static fn (array $i): bool => $i['value'] > 0));
        usort($top, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        [$ws, $wp] = InventoryScope::sql($user, 'warehouse_id');
        if (($f['warehouse'] ?? '') !== '') {
            $ws .= ' AND warehouse_id = ?';
            $wp[] = (int) $f['warehouse'];
        }
        $docs = [];
        foreach ($this->db->select("SELECT direction, status, COUNT(*) AS n FROM inventory_entries WHERE {$ws} AND status IN ('draft', 'pending_approval', 'approved') GROUP BY direction, status", $wp) as $r) {
            $docs[$r['direction']][$r['status']] = (int) $r['n'];
        }
        foreach ($this->db->select("SELECT status, COUNT(*) AS n FROM inventory_adjustments WHERE {$ws} AND status IN ('draft', 'pending_approval', 'approved') GROUP BY status", $wp) as $r) {
            $docs['adjustment'][$r['status']] = (int) $r['n'];
        }
        [$ts, $tp] = InventoryScope::sql($user, 'from_warehouse_id');
        [$ts2, $tp2] = InventoryScope::sql($user, 'to_warehouse_id');
        $tw = "({$ts} OR {$ts2})";
        $tparams = array_merge($tp, $tp2);
        if (($f['warehouse'] ?? '') !== '') {
            $tw .= ' AND (from_warehouse_id = ? OR to_warehouse_id = ?)';
            array_push($tparams, (int) $f['warehouse'], (int) $f['warehouse']);
        }
        $transfers = [];
        foreach ($this->db->select("SELECT transfer_status, COUNT(*) AS n FROM inventory_transfers WHERE {$tw} AND transfer_status IN ('draft', 'pending_approval', 'approved', 'in_transit', 'partially_received') GROUP BY transfer_status", $tparams) as $r) {
            $transfers[$r['transfer_status']] = (int) $r['n'];
        }
        $warn = BusinessDate::addDays(BusinessDate::today(), BatchSerialService::warningDays());
        [$bs, $bp] = InventoryScope::sql($user, 's.warehouse_id');
        if (($f['warehouse'] ?? '') !== '') {
            $bs .= ' AND s.warehouse_id = ?';
            $bp[] = (int) $f['warehouse'];
        }
        $expiry = $this->db->select("SELECT b.id, b.batch_no, b.expiry_date, p.id AS product_id, p.name AS product_name, p.unit, v.name AS variant_name, SUM(s.quantity) AS qty
            FROM inventory_batches b JOIN inventory_stock_balances s ON s.batch_id = b.id JOIN products p ON p.id = b.product_id LEFT JOIN product_variants v ON v.id = b.variant_id
            WHERE {$bs} AND b.expiry_date IS NOT NULL AND b.expiry_date <= ? AND s.quantity > 0 AND s.stock_state <> 'in_transit'
            GROUP BY b.id, b.batch_no, b.expiry_date, p.id, p.name, p.unit, v.name ORDER BY b.expiry_date LIMIT 8", array_merge($bp, [$warn]));
        $reservations = (int) $this->db->scalar("SELECT COUNT(*) FROM inventory_reservations WHERE {$ws} AND status IN ('active', 'partially_fulfilled')", $wp);
        $mf = array_intersect_key($f, array_flip(['warehouse', 'product', 'variant', 'batch', 'category', 'brand', 'gst', 'activity', 'from', 'to']));
        $recent = $q->movements($user, $mf, 8);
        $today = BusinessDate::today();
        $range = ['from' => ($f['from'] ?? '') ?: BusinessDate::addDays($today, -29), 'to' => ($f['to'] ?? '') ?: $today];
        [$ms, $mp] = $q->movementWhere($user, $range + $mf);
        $tracked = $q->products($user, array_intersect_key($f, array_flip(['category', 'brand', 'product', 'variant'])));
        $trackedProducts = [];
        foreach ($tracked as $r) {
            $trackedProducts[(int) $r['product_id']] = true;
        }
        [$hs, $hp] = InventoryScope::sql($user, 'h.warehouse_id');
        $activity = InventoryHistory::read($this->db, "(h.warehouse_id IS NULL OR {$hs})", $hp, 8);
        [$wsq, $wsp] = InventoryScope::sql($user, 'id');
        $warehouseCount = (int) $this->db->scalar("SELECT COUNT(*) FROM warehouses WHERE {$wsq} AND status = 'active'", $wsp);
        $flow = $this->db->first("SELECT COALESCE(SUM(m.qty_in), 0) AS qty_in, COALESCE(SUM(m.qty_out), 0) AS qty_out, COUNT(*) AS n FROM inventory_stock_movements m
            WHERE {$ms} AND m.movement_type NOT IN ('" . implode("', '", InventoryLedger::INTERNAL_TYPES) . "')", $mp);
        return [
            'totals' => $t + ['stocked_products' => count($products), 'stocked_variants' => count($variants), 'items' => count($items), 'tracked_products' => count($trackedProducts),
                'tracked_variants' => count(array_filter($tracked, static fn (array $r): bool => $r['variant_id'] !== null)), 'warehouses' => $warehouseCount,
                'low_products' => count(array_filter($tracked, static fn (array $r): bool => $r['stock_status'] === 'low')),
                'out_products' => count(array_filter($tracked, static fn (array $r): bool => in_array($r['stock_status'], ['out', 'negative'], true)))],
            'activity' => $activity, 'range' => $range,
            'warehouses' => array_slice($byWarehouse, 0, 10), 'low' => array_slice($low, 0, 8), 'lowCount' => count($low), 'top' => array_slice($top, 0, 8),
            'docs' => $docs, 'transfers' => $transfers, 'expiry' => $expiry, 'reservations' => $reservations, 'recent' => $recent,
            'flow' => ['in' => Qty::fromDb($flow['qty_in'] ?? 0), 'out' => Qty::fromDb($flow['qty_out'] ?? 0), 'movements' => (int) ($flow['n'] ?? 0)],
            'method' => (string) \App\Core\App::settings()->get('inventory_valuation_method', 'weighted_average'),
            'valueTotal' => Money::format($t['value']),
        ];
    }
}
