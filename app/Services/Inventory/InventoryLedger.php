<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\DomainRuleException;
use App\Support\Money;
use App\Support\Qty;

/**
 * The stock movement ledger — the single source of truth for stock quantity and value.
 *
 * Rules enforced here:
 * - Rows are only inserted. A correction is reverse(): a new row pointing to the original
 *   (reversal_of_id is unique, so nothing can be reversed twice).
 * - Every row carries its source document, line and a unique posting_key; posting the same
 *   document line twice fails at the database even if two requests race.
 * - post() must run inside a database transaction after lock() on the stock items involved:
 *   the lock is an UPDATE on inventory_stock_items (a row lock on MySQL/InnoDB, the write lock
 *   on SQLite), so two requests cannot both pass the stock check and oversell.
 * - inventory_stock_balances (quantity per location / batch / condition) and
 *   inventory_stock_items (quantity and value per warehouse item) are summaries updated in the
 *   same transaction; reconcile() recomputes them from the ledger and reports differences.
 *
 * Valuation: FIFO items keep cost layers (one per receipt, consumed oldest first, batch-aware);
 * weighted-average items cost issues at the running average of the warehouse item. Moves inside
 * a warehouse (location, condition, in-transit) carry the average cost and leave the layers and
 * the item value unchanged. Values are paise, unit costs and quantities 4-decimal integers.
 */
final class InventoryLedger
{
    public const STATES = ['sellable' => 'Available', 'quarantine' => 'Inspection / restock pending', 'damaged' => 'Damaged', 'in_transit' => 'In transit'];

    public const TYPES = [
        'opening' => 'Opening stock', 'purchase_receipt' => 'Purchase receipt', 'customer_return' => 'Customer return', 'other_receipt' => 'Other receipt',
        'sales_dispatch' => 'Sales dispatch', 'supplier_return' => 'Supplier return', 'damaged' => 'Damaged stock written off', 'expired' => 'Expired stock written off',
        'consumption' => 'Internal consumption', 'other_issue' => 'Other issue',
        'transfer_out' => 'Transfer out', 'transfer_in' => 'Transfer in', 'transit_out' => 'Dispatched — left available stock', 'transit_in' => 'Dispatched — in transit',
        'transit_loss' => 'Lost in transit', 'location_move' => 'Moved between locations',
        'adjustment_in' => 'Adjustment in', 'adjustment_out' => 'Adjustment out', 'condition_out' => 'Condition change — out', 'condition_in' => 'Condition change — in',
        'revaluation' => 'Revaluation', 'reversal' => 'Reversal',
    ];

    /** Moves inside one warehouse item: average cost, no cost layers, item value unchanged. */
    public const INTERNAL_TYPES = ['transit_out', 'transit_in', 'location_move', 'condition_out', 'condition_in'];

    public const METHODS = ['fifo' => 'FIFO (first in, first out)', 'weighted_average' => 'Weighted average cost'];

    public function __construct(private readonly Database $db)
    {
    }

    /** Valuation method of a product (its own, else the company default). */
    public static function method(Database $db, int $productId): string
    {
        $m = $db->scalar('SELECT valuation_method FROM product_inventory_settings WHERE product_id = ?', [$productId]);
        return is_string($m) && isset(self::METHODS[$m]) ? $m : (string) (App::settings()->get('inventory_valuation_method', 'weighted_average') ?: 'weighted_average');
    }

    /** The warehouse item row (created on first use). @return array<string, mixed> */
    public function item(int $warehouseId, int $productId, ?int $variantId): array
    {
        $key = $warehouseId . ':' . $productId . ':' . ($variantId ?? 0);
        $row = $this->db->first('SELECT * FROM inventory_stock_items WHERE item_key = ?', [$key]);
        if ($row !== null) {
            return $row;
        }
        try {
            $this->db->insert('inventory_stock_items', ['warehouse_id' => $warehouseId, 'product_id' => $productId, 'variant_id' => $variantId, 'item_key' => $key,
                'qty_on_hand' => '0.0000', 'stock_value' => '0.00', 'lock_version' => 0, 'updated_at' => now()]);
        } catch (\PDOException) {
            // created by a concurrent request — read it below
        }
        return $this->db->first('SELECT * FROM inventory_stock_items WHERE item_key = ?', [$key]) ?? throw new DomainRuleException('Could not open the stock item.');
    }

    /**
     * Serialise stock posting on these warehouse items for the rest of the current transaction.
     * Ids are locked in ascending order so two postings cannot deadlock.
     * @param list<int> $itemIds
     */
    public function lock(array $itemIds): void
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Stock must be locked inside a database transaction.');
        }
        $ids = array_values(array_unique(array_map('intval', $itemIds)));
        sort($ids);
        foreach ($ids as $id) {
            $this->db->execute('UPDATE inventory_stock_items SET lock_version = lock_version + 1 WHERE id = ?', [$id]);
        }
    }

    /** @return array<string, mixed> */
    public function freshItem(int $itemId): array
    {
        return $this->db->first('SELECT * FROM inventory_stock_items WHERE id = ?', [$itemId]) ?? throw new DomainRuleException('Stock item not found.');
    }

    public function balance(int $itemId, ?int $locationId, ?int $batchId, string $state): int
    {
        return Qty::fromDb($this->db->scalar('SELECT quantity FROM inventory_stock_balances WHERE balance_key = ?', [self::balanceKey($itemId, $locationId, $batchId, $state)]));
    }

    /** Quantity of an item in one condition, any location / batch. */
    public function stateQty(int $itemId, string $state): int
    {
        return Qty::fromDb($this->db->scalar('SELECT SUM(quantity) FROM inventory_stock_balances WHERE stock_item_id = ? AND stock_state = ?', [$itemId, $state]));
    }

    /** Open reserved quantity of an item (active and partially fulfilled reservations). */
    public function reserved(int $warehouseId, int $productId, ?int $variantId, ?int $exceptReservationItemId = null): int
    {
        $sum = 0;
        foreach ($this->db->select("SELECT ri.id, ri.quantity, ri.qty_fulfilled, ri.qty_released FROM inventory_reservation_items ri JOIN inventory_reservations r ON r.id = ri.reservation_id
            WHERE r.warehouse_id = ? AND ri.product_id = ? AND " . ($variantId === null ? 'ri.variant_id IS NULL' : 'ri.variant_id = ?') . " AND ri.status IN ('active', 'partially_fulfilled')",
            array_values(array_filter([$warehouseId, $productId, $variantId], static fn ($v): bool => $v !== null))) as $r) {
            if ((int) $r['id'] !== $exceptReservationItemId) {
                $sum += max(0, Qty::fromDb($r['quantity']) - Qty::fromDb($r['qty_fulfilled']) - Qty::fromDb($r['qty_released']));
            }
        }
        return $sum;
    }

    public static function balanceKey(int $itemId, ?int $locationId, ?int $batchId, string $state): string
    {
        return $itemId . ':' . ($locationId ?? 0) . ':' . ($batchId ?? 0) . ':' . $state;
    }

    /**
     * Post one movement. Keys:
     * item (stock item row), dir in|out, type, qty (scaled > 0), state, location_id, batch_id,
     * unit_cost (scaled, inbound) or value (paise, inbound) or chunks (inbound FIFO layers to recreate),
     * allow_negative, source_type, source_id, source_line_id, posting_key, reference_no, date, notes,
     * serial_ids, serial_after (serial fields to set), pair_id, actor (array), approver_id.
     * @param array<string, mixed> $m
     * @return array<string, mixed> the movement row plus 'value' (paise, positive) and 'chunks' (out: consumed cost chunks)
     */
    public function post(array $m): array
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Stock must be posted inside a database transaction.');
        }
        $item = $this->freshItem((int) $m['item']['id']);
        $qty = (int) $m['qty'];
        if ($qty <= 0) {
            throw new DomainRuleException('Quantity must be greater than zero.');
        }
        $type = (string) $m['type'];
        $state = (string) ($m['state'] ?? 'sellable');
        $loc = isset($m['location_id']) && $m['location_id'] !== null ? (int) $m['location_id'] : null;
        $batch = isset($m['batch_id']) && $m['batch_id'] !== null ? (int) $m['batch_id'] : null;
        $internal = in_array($type, self::INTERNAL_TYPES, true) || !empty($m['internal']);
        $method = self::method($this->db, (int) $item['product_id']);
        $itemQty = Qty::fromDb($item['qty_on_hand']);
        $itemVal = Money::fromDb($item['stock_value']);
        $lastCost = Qty::fromDb($item['last_unit_cost']);
        $date = (string) $m['date'];
        $out = $m['dir'] === 'out';
        $chunks = [];
        $usage = [];

        if ($out) {
            $bal = $this->balance((int) $item['id'], $loc, $batch, $state);
            if ($bal < $qty && empty($m['allow_negative'])) {
                throw new DomainRuleException('Only ' . Qty::format(max(0, $bal)) . ' ' . (string) ($m['unit_label'] ?? 'units') . ' are on hand there ('
                    . self::STATES[$state] . (isset($m['where']) ? ', ' . $m['where'] : '') . '); ' . Qty::format($qty) . ' were requested.');
            }
            if ($internal) {
                $value = $this->averageValue($itemQty, $itemVal, $lastCost, $qty);
                $chunks[] = [$qty, $value, $date, $batch];
            } elseif ($method === 'fifo') {
                [$value, $chunks, $usage] = $this->consumeLayers((int) $item['id'], $batch, $qty, $lastCost, $date);
            } else {
                $value = $this->averageValue($itemQty, $itemVal, $lastCost, $qty);
                $chunks[] = [$qty, $value, $date, $batch];
            }
            $unitCost = Qty::unitCost($value, $qty);
            $signed = -$value;
        } else {
            if (isset($m['value'])) {
                $value = (int) $m['value'];
                $unitCost = Qty::unitCost($value, $qty);
            } elseif (isset($m['chunks'])) {
                $value = array_sum(array_map(static fn (array $c): int => (int) $c[1], $m['chunks']));
                $unitCost = Qty::unitCost($value, $qty);
            } else {
                $unitCost = (int) ($m['unit_cost'] ?? 0);
                $value = Qty::value($qty, $unitCost);
            }
            if ($value < 0) {
                throw new DomainRuleException('Stock value cannot be negative.');
            }
            $signed = $value;
        }

        $id = $this->insertMovement($m, $item, $loc, $batch, $state, $type, $out ? 0 : $qty, $out ? $qty : 0, $unitCost, $signed);

        // FIFO layers (receipts only; moves inside the warehouse keep their layers).
        if (!$out && !$internal && $method === 'fifo') {
            if ($itemQty < 0) {
                $this->resetLayersAfterNegative((int) $item['id'], $id, $date, $batch, $itemQty + $qty, $itemVal + $value);
            } else {
                foreach ($m['chunks'] ?? [[$qty, $value, $date, $batch]] as [$cq, $cv, $cd, $cb]) {
                    $this->addLayer((int) $item['id'], $id, (string) $cd, $cb ?? $batch, (int) $cq, (int) $cv);
                }
            }
        }
        foreach ($usage as [$layerId, $uq, $uv]) {
            $this->db->insert('inventory_cost_layer_usage', ['layer_id' => $layerId, 'movement_id' => $id, 'quantity' => Qty::toDb($uq), 'value' => Money::toDb($uv), 'created_at' => now()]);
        }

        $this->applyBalance($item, $loc, $batch, $state, $out ? -$qty : $qty, $id);
        $newQty = $itemQty + ($out ? -$qty : $qty);
        $update = ['qty_on_hand' => Qty::toDb($newQty), 'stock_value' => Money::toDb($itemVal + $signed), 'updated_at' => now()];
        if (!$out && !$internal) {
            $update['last_unit_cost'] = Qty::toDb($unitCost);
        }
        $this->db->update('inventory_stock_items', $update, ['id' => $item['id']]);
        $this->linkSerials($id, $m['serial_ids'] ?? [], $m['serial_after'] ?? null);

        $row = $this->db->first('SELECT * FROM inventory_stock_movements WHERE id = ?', [$id]);
        return $row + ['value' => $value, 'chunks' => $chunks];
    }

    /**
     * Reverse a posted movement with an opposite movement at the original cost.
     * An inbound movement can be reversed only while its stock is still on hand (and, under
     * FIFO, its cost layer untouched); otherwise an adjustment is the right correction.
     * @param array<string, mixed> $orig @param array<string, mixed> $ctx actor, date, reason, source_type, source_id
     * @return array<string, mixed>
     */
    public function reverse(array $orig, array $ctx): array
    {
        if ($orig['reversal_of_id'] !== null || $orig['movement_type'] === 'reversal') {
            throw new DomainRuleException("Movement {$orig['movement_no']} is itself a reversal.");
        }
        if ($this->db->scalar('SELECT 1 FROM inventory_stock_movements WHERE reversal_of_id = ?', [$orig['id']])) {
            throw new DomainRuleException("Movement {$orig['movement_no']} has already been reversed.");
        }
        if ($orig['movement_type'] === 'revaluation') {
            throw new DomainRuleException('A revaluation is corrected with a new revaluation, not reversed.');
        }
        $item = $this->freshItem((int) $orig['stock_item_id']);
        $internal = in_array($orig['movement_type'], self::INTERNAL_TYPES, true);
        $method = self::method($this->db, (int) $item['product_id']);
        $qtyIn = Qty::fromDb($orig['qty_in']);
        $qtyOut = Qty::fromDb($orig['qty_out']);
        $value = abs(Money::fromDb($orig['total_cost']));
        $loc = $orig['location_id'] !== null ? (int) $orig['location_id'] : null;
        $batch = $orig['batch_id'] !== null ? (int) $orig['batch_id'] : null;
        $state = (string) $orig['stock_state'];
        $itemQty = Qty::fromDb($item['qty_on_hand']);
        $itemVal = Money::fromDb($item['stock_value']);
        $base = ['item' => $item, 'type' => 'reversal', 'source_type' => (string) ($ctx['source_type'] ?? 'reversal'), 'source_id' => (int) ($ctx['source_id'] ?? $orig['source_id']),
            'source_line_id' => $orig['source_line_id'], 'posting_key' => 'rev:' . $orig['id'], 'reference_no' => (string) $orig['reference_no'], 'date' => (string) $ctx['date'],
            'notes' => mb_substr('Reversal of ' . $orig['movement_no'] . ': ' . (string) ($ctx['reason'] ?? ''), 0, 255), 'actor' => $ctx['actor'], 'approver_id' => $ctx['approver_id'] ?? null,
            'reversal_of_id' => (int) $orig['id']];

        $usage = [];
        if ($qtyIn > 0) {
            $bal = $this->balance((int) $item['id'], $loc, $batch, $state);
            if ($bal < $qtyIn) {
                throw new DomainRuleException("Movement {$orig['movement_no']} cannot be reversed: only " . Qty::format(max(0, $bal)) . ' of the ' . Qty::format($qtyIn)
                    . ' received are still there. Record a stock adjustment instead.');
            }
            if (!$internal && $method === 'fifo') {
                $layers = $this->db->select('SELECT * FROM inventory_cost_layers WHERE movement_id = ?', [$orig['id']]);
                foreach ($layers as $l) {
                    if (Qty::fromDb($l['qty_remaining']) !== Qty::fromDb($l['qty_in'])) {
                        throw new DomainRuleException("Stock from movement {$orig['movement_no']} has already been issued, so it cannot be reversed. Record a stock adjustment instead.");
                    }
                }
                foreach ($layers as $l) {
                    $usage[] = [(int) $l['id'], Qty::fromDb($l['qty_remaining']), Money::fromDb($l['value_remaining'])];
                    $this->db->update('inventory_cost_layers', ['qty_remaining' => '0.0000', 'value_remaining' => '0.00'], ['id' => $l['id']]);
                }
            }
            $id = $this->insertMovement($base, $item, $loc, $batch, $state, 'reversal', 0, $qtyIn, Qty::fromDb($orig['unit_cost']), -$value);
            $this->applyBalance($item, $loc, $batch, $state, -$qtyIn, $id);
            $this->db->update('inventory_stock_items', ['qty_on_hand' => Qty::toDb($itemQty - $qtyIn), 'stock_value' => Money::toDb($itemVal - $value), 'updated_at' => now()], ['id' => $item['id']]);
            $serialAfter = ['status' => 'reversed', 'warehouse_id' => null, 'location_id' => null, 'stock_state' => null];
            if ($orig['transfer_pair_id'] !== null) {
                $pair = $this->db->first('SELECT warehouse_id, location_id, stock_state FROM inventory_stock_movements WHERE id = ?', [$orig['transfer_pair_id']]);
                if ($pair !== null) {
                    $serialAfter = ['status' => $pair['stock_state'] === 'in_transit' ? 'in_transit' : 'in_stock', 'warehouse_id' => (int) $pair['warehouse_id'],
                        'location_id' => $pair['location_id'], 'stock_state' => $pair['stock_state']];
                }
            }
        } else {
            if (!$internal && $method === 'fifo') {
                $restoredQty = 0;
                $restoredVal = 0;
                foreach ($this->db->select('SELECT * FROM inventory_cost_layer_usage WHERE movement_id = ?', [$orig['id']]) as $u) {
                    $l = $this->db->first('SELECT * FROM inventory_cost_layers WHERE id = ?', [$u['layer_id']]);
                    $uq = Qty::fromDb($u['quantity']);
                    $uv = Money::fromDb($u['value']);
                    $this->db->update('inventory_cost_layers', ['qty_remaining' => Qty::toDb(Qty::fromDb($l['qty_remaining']) + $uq),
                        'value_remaining' => Money::toDb(Money::fromDb($l['value_remaining']) + $uv)], ['id' => $l['id']]);
                    $restoredQty += $uq;
                    $restoredVal += $uv;
                }
            }
            $id = $this->insertMovement($base, $item, $loc, $batch, $state, 'reversal', $qtyOut, 0, Qty::fromDb($orig['unit_cost']), $value);
            if (!$internal && $method === 'fifo' && $qtyOut > $restoredQty) {
                $this->addLayer((int) $item['id'], $id, (string) $orig['movement_date'], $batch, $qtyOut - $restoredQty, $value - $restoredVal);
            }
            $this->applyBalance($item, $loc, $batch, $state, $qtyOut, $id);
            $this->db->update('inventory_stock_items', ['qty_on_hand' => Qty::toDb($itemQty + $qtyOut), 'stock_value' => Money::toDb($itemVal + $value), 'updated_at' => now()], ['id' => $item['id']]);
            $serialAfter = ['status' => $state === 'in_transit' ? 'in_transit' : 'in_stock', 'warehouse_id' => (int) $orig['warehouse_id'], 'location_id' => $loc, 'stock_state' => $state];
        }
        foreach ($usage as [$layerId, $uq, $uv]) {
            $this->db->insert('inventory_cost_layer_usage', ['layer_id' => $layerId, 'movement_id' => $id, 'quantity' => Qty::toDb($uq), 'value' => Money::toDb($uv), 'created_at' => now()]);
        }
        $serials = array_map('intval', array_column($this->db->select('SELECT serial_id FROM inventory_serial_movements WHERE movement_id = ?', [$orig['id']]), 'serial_id'));
        $this->linkSerials($id, $serials, $serialAfter);
        return $this->db->first('SELECT * FROM inventory_stock_movements WHERE id = ?', [$id]);
    }

    /**
     * Revalue the stock on hand of a warehouse item at a new unit cost. Posts a zero-quantity
     * revaluation row carrying the value change; FIFO layers are re-costed.
     * @param array<string, mixed> $ctx @return array<string, mixed>
     */
    public function revalue(int $itemId, int $newUnitCost, array $ctx): array
    {
        $item = $this->freshItem($itemId);
        $qty = Qty::fromDb($item['qty_on_hand']);
        if ($qty <= 0) {
            throw new DomainRuleException('There is no stock on hand to revalue.');
        }
        $oldVal = Money::fromDb($item['stock_value']);
        $newVal = Qty::value($qty, $newUnitCost);
        $delta = $newVal - $oldVal;
        $id = $this->insertMovement(['source_type' => 'revaluation', 'source_id' => (int) $ctx['source_id'], 'source_line_id' => null, 'posting_key' => (string) $ctx['posting_key'],
            'reference_no' => (string) $ctx['reference_no'], 'date' => (string) $ctx['date'], 'notes' => mb_substr((string) ($ctx['reason'] ?? ''), 0, 255), 'actor' => $ctx['actor'],
            'approver_id' => $ctx['approver_id'] ?? null], $item, null, null, 'sellable', 'revaluation', 0, 0, $newUnitCost, $delta);
        if (self::method($this->db, (int) $item['product_id']) === 'fifo') {
            $layers = $this->db->select('SELECT * FROM inventory_cost_layers WHERE stock_item_id = ? AND qty_remaining > 0 ORDER BY layer_date, id', [$itemId]);
            $left = $newVal;
            foreach ($layers as $i => $l) {
                $lv = $i === count($layers) - 1 ? $left : Qty::value(Qty::fromDb($l['qty_remaining']), $newUnitCost);
                $left -= $lv;
                $this->db->update('inventory_cost_layers', ['unit_cost' => Qty::toDb($newUnitCost), 'value_remaining' => Money::toDb($lv)], ['id' => $l['id']]);
            }
        }
        $this->db->update('inventory_stock_items', ['stock_value' => Money::toDb($newVal), 'last_unit_cost' => Qty::toDb($newUnitCost), 'updated_at' => now()], ['id' => $itemId]);
        return $this->db->first('SELECT * FROM inventory_stock_movements WHERE id = ?', [$id]) + ['old_value' => $oldVal, 'new_value' => $newVal, 'qty' => $qty];
    }

    /**
     * Recompute every summary from the ledger and compare. With $repair the summaries are
     * rewritten from the ledger (the ledger itself is never changed).
     * @return list<array{kind: string, key: string, expected: string, actual: string}>
     */
    public function reconcile(bool $repair = false): array
    {
        $expBal = [];
        $expItem = [];
        foreach ($this->db->select('SELECT stock_item_id, location_id, batch_id, stock_state, qty_in, qty_out, total_cost FROM inventory_stock_movements') as $r) {
            $k = self::balanceKey((int) $r['stock_item_id'], $r['location_id'] !== null ? (int) $r['location_id'] : null, $r['batch_id'] !== null ? (int) $r['batch_id'] : null, (string) $r['stock_state']);
            $d = Qty::fromDb($r['qty_in']) - Qty::fromDb($r['qty_out']);
            $expBal[$k] = ($expBal[$k] ?? 0) + $d;
            $i = (int) $r['stock_item_id'];
            $expItem[$i][0] = ($expItem[$i][0] ?? 0) + $d;
            $expItem[$i][1] = ($expItem[$i][1] ?? 0) + Money::fromDb($r['total_cost']);
        }
        $diffs = [];
        $actualBal = [];
        foreach ($this->db->select('SELECT balance_key, quantity FROM inventory_stock_balances') as $b) {
            $actualBal[(string) $b['balance_key']] = Qty::fromDb($b['quantity']);
        }
        foreach ($expBal + $actualBal as $k => $_) {
            $e = $expBal[$k] ?? 0;
            $a = $actualBal[$k] ?? 0;
            if ($e !== $a) {
                $diffs[] = ['kind' => 'balance', 'key' => (string) $k, 'expected' => Qty::format($e), 'actual' => Qty::format($a)];
            }
        }
        foreach ($this->db->select('SELECT id, item_key, product_id, qty_on_hand, stock_value FROM inventory_stock_items') as $it) {
            [$eq, $ev] = $expItem[(int) $it['id']] ?? [0, 0];
            if ($eq !== Qty::fromDb($it['qty_on_hand'])) {
                $diffs[] = ['kind' => 'item_quantity', 'key' => (string) $it['item_key'], 'expected' => Qty::format($eq), 'actual' => Qty::format(Qty::fromDb($it['qty_on_hand']))];
            }
            if ($ev !== Money::fromDb($it['stock_value'])) {
                $diffs[] = ['kind' => 'item_value', 'key' => (string) $it['item_key'], 'expected' => Money::toDb($ev), 'actual' => Money::toDb(Money::fromDb($it['stock_value']))];
            }
            if ($eq >= 0 && self::method($this->db, (int) $it['product_id']) === 'fifo') {
                $lq = 0;
                $lv = 0;
                foreach ($this->db->select('SELECT qty_remaining, value_remaining FROM inventory_cost_layers WHERE stock_item_id = ?', [$it['id']]) as $l) {
                    $lq += Qty::fromDb($l['qty_remaining']);
                    $lv += Money::fromDb($l['value_remaining']);
                }
                if ($lq !== $eq || $lv !== $ev) {
                    $diffs[] = ['kind' => 'fifo_layers', 'key' => (string) $it['item_key'], 'expected' => Qty::format($eq) . ' / ' . Money::toDb($ev), 'actual' => Qty::format($lq) . ' / ' . Money::toDb($lv)];
                }
            }
        }
        if ($repair && $diffs !== []) {
            $this->db->transaction(function () use ($expBal, $expItem): void {
                foreach ($this->db->select('SELECT id, balance_key FROM inventory_stock_balances') as $b) {
                    $this->db->update('inventory_stock_balances', ['quantity' => Qty::toDb($expBal[(string) $b['balance_key']] ?? 0), 'updated_at' => now()], ['id' => $b['id']]);
                }
                foreach ($expItem as $itemId => [$q, $v]) {
                    $this->db->update('inventory_stock_items', ['qty_on_hand' => Qty::toDb($q), 'stock_value' => Money::toDb($v), 'updated_at' => now()], ['id' => $itemId]);
                }
            });
        }
        return $diffs;
    }

    // ---------------------------------------------------------------- internals

    private function averageValue(int $itemQty, int $itemVal, int $lastCost, int $qty): int
    {
        if ($itemQty <= 0) {
            return Qty::value($qty, $lastCost);
        }
        if ($qty >= $itemQty) {
            return $itemVal + ($qty > $itemQty ? Qty::value($qty - $itemQty, $lastCost) : 0);
        }
        return Qty::mulDiv($itemVal, $qty, $itemQty);
    }

    /** @return array{0: int, 1: list<array{0: int, 1: int, 2: string, 3: int|null}>, 2: list<array{0: int, 1: int, 2: int}>} */
    private function consumeLayers(int $itemId, ?int $batchId, int $qty, int $lastCost, string $date): array
    {
        $need = $qty;
        $value = 0;
        $chunks = [];
        $usage = [];
        $sets = [];
        if ($batchId !== null && $this->db->scalar('SELECT 1 FROM inventory_cost_layers WHERE stock_item_id = ? AND batch_id = ? AND qty_remaining > 0', [$itemId, $batchId])) {
            $sets[] = $this->db->select('SELECT * FROM inventory_cost_layers WHERE stock_item_id = ? AND batch_id = ? AND qty_remaining > 0 ORDER BY layer_date, id', [$itemId, $batchId]);
        }
        $sets[] = $this->db->select('SELECT * FROM inventory_cost_layers WHERE stock_item_id = ? AND qty_remaining > 0 ORDER BY layer_date, id', [$itemId]);
        $used = [];
        foreach ($sets as $layers) {
            foreach ($layers as $l) {
                if ($need <= 0) {
                    break 2;
                }
                if (isset($used[$l['id']])) {
                    continue;
                }
                $lq = Qty::fromDb($l['qty_remaining']) - ($used[$l['id']] ?? 0);
                $lv = Money::fromDb($l['value_remaining']);
                $take = min($need, $lq);
                $v = $take === $lq ? $lv : Qty::mulDiv($lv, $take, $lq);
                $this->db->update('inventory_cost_layers', ['qty_remaining' => Qty::toDb($lq - $take), 'value_remaining' => Money::toDb($lv - $v)], ['id' => $l['id']]);
                $used[$l['id']] = $take;
                $usage[] = [(int) $l['id'], $take, $v];
                $chunks[] = [$take, $v, (string) $l['layer_date'], $l['batch_id'] !== null ? (int) $l['batch_id'] : null];
                $value += $v;
                $need -= $take;
            }
        }
        if ($need > 0) {
            // Issuing beyond the layers (negative stock allowed): provisional cost at the last known unit cost.
            $v = Qty::value($need, $lastCost);
            $chunks[] = [$need, $v, $date, $batchId];
            $value += $v;
        }
        return [$value, $chunks, $usage];
    }

    private function addLayer(int $itemId, int $movementId, string $date, ?int $batchId, int $qty, int $value): void
    {
        if ($qty <= 0) {
            return;
        }
        $this->db->insert('inventory_cost_layers', ['stock_item_id' => $itemId, 'batch_id' => $batchId, 'movement_id' => $movementId, 'layer_date' => $date,
            'qty_in' => Qty::toDb($qty), 'qty_remaining' => Qty::toDb($qty), 'unit_cost' => Qty::toDb(Qty::unitCost($value, $qty)), 'value_remaining' => Money::toDb($value), 'created_at' => now()]);
    }

    /** After stock was negative, the remaining quantity becomes one layer at the item's resulting value. */
    private function resetLayersAfterNegative(int $itemId, int $movementId, string $date, ?int $batchId, int $newQty, int $newValue): void
    {
        $this->db->execute("UPDATE inventory_cost_layers SET qty_remaining = '0.0000', value_remaining = '0.00' WHERE stock_item_id = ? AND qty_remaining <> 0", [$itemId]);
        if ($newQty > 0) {
            $this->addLayer($itemId, $movementId, $date, $batchId, $newQty, $newValue);
        }
    }

    /** @param array<string, mixed> $m @param array<string, mixed> $item */
    private function insertMovement(array $m, array $item, ?int $loc, ?int $batch, string $state, string $type, int $qtyIn, int $qtyOut, int $unitCost, int $signedValue): int
    {
        try {
            $id = $this->db->insert('inventory_stock_movements', [
                'movement_no' => 'TMP-' . bin2hex(random_bytes(7)), 'movement_date' => (string) $m['date'], 'stock_item_id' => (int) $item['id'],
                'warehouse_id' => (int) $item['warehouse_id'], 'product_id' => (int) $item['product_id'], 'variant_id' => $item['variant_id'] !== null ? (int) $item['variant_id'] : null,
                'location_id' => $loc, 'batch_id' => $batch, 'stock_state' => $state, 'movement_type' => $type,
                'qty_in' => Qty::toDb($qtyIn), 'qty_out' => Qty::toDb($qtyOut), 'unit_cost' => Qty::toDb($unitCost), 'total_cost' => Money::toDb($signedValue),
                'source_type' => (string) $m['source_type'], 'source_id' => (int) $m['source_id'], 'source_line_id' => isset($m['source_line_id']) && $m['source_line_id'] !== null ? (int) $m['source_line_id'] : null,
                'reference_no' => mb_substr((string) $m['reference_no'], 0, 100), 'posting_key' => (string) $m['posting_key'],
                'reversal_of_id' => $m['reversal_of_id'] ?? null, 'transfer_pair_id' => $m['pair_id'] ?? null,
                'notes' => isset($m['notes']) && $m['notes'] !== null && $m['notes'] !== '' ? mb_substr((string) $m['notes'], 0, 255) : null,
                'created_by' => isset($m['actor']['id']) ? (int) $m['actor']['id'] : null, 'approved_by' => $m['approver_id'] ?? null, 'posted_at' => now(),
            ]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'posting_key') || str_contains($e->getMessage(), 'reversal_of_id') || str_contains(strtolower($e->getMessage()), 'unique') || str_contains($e->getMessage(), 'Duplicate')) {
                throw new DomainRuleException('This stock movement has already been posted (' . $m['posting_key'] . ').');
            }
            throw $e;
        }
        $this->db->update('inventory_stock_movements', ['movement_no' => 'SM-' . str_pad((string) $id, 7, '0', STR_PAD_LEFT)], ['id' => $id]);
        return $id;
    }

    /** @param array<string, mixed> $item */
    private function applyBalance(array $item, ?int $loc, ?int $batch, string $state, int $delta, int $movementId): void
    {
        $key = self::balanceKey((int) $item['id'], $loc, $batch, $state);
        $row = $this->db->first('SELECT id, quantity FROM inventory_stock_balances WHERE balance_key = ?', [$key]);
        if ($row === null) {
            $this->db->insert('inventory_stock_balances', ['stock_item_id' => (int) $item['id'], 'warehouse_id' => (int) $item['warehouse_id'], 'product_id' => (int) $item['product_id'],
                'variant_id' => $item['variant_id'] !== null ? (int) $item['variant_id'] : null, 'location_id' => $loc, 'batch_id' => $batch, 'stock_state' => $state,
                'balance_key' => $key, 'quantity' => Qty::toDb($delta), 'last_movement_id' => $movementId, 'updated_at' => now()]);
            return;
        }
        $this->db->update('inventory_stock_balances', ['quantity' => Qty::toDb(Qty::fromDb($row['quantity']) + $delta), 'last_movement_id' => $movementId, 'updated_at' => now()], ['id' => $row['id']]);
    }

    /** @param list<int> $serialIds @param array<string, mixed>|null $after */
    private function linkSerials(int $movementId, array $serialIds, ?array $after): void
    {
        foreach ($serialIds as $sid) {
            $this->db->insert('inventory_serial_movements', ['serial_id' => (int) $sid, 'movement_id' => $movementId, 'created_at' => now()]);
            if ($after !== null) {
                $this->db->update('inventory_serial_numbers', $after + ['last_movement_id' => $movementId, 'updated_at' => now()], ['id' => (int) $sid]);
            }
        }
    }
}
