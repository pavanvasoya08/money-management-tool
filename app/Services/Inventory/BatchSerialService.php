<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Support\BusinessDate;
use App\Support\Qty;

/**
 * Batch and serial-number registers: stock per batch and warehouse, expiry status (expired /
 * near expiry by the configured warning period), movement history of a batch or a serial, and
 * the details that can be maintained directly (supplier references, notes, on-hold status).
 * Batch numbers, dates and serial numbers are corrected through CorrectionService (approval).
 */
final class BatchSerialService
{
    public const BATCH_STATUSES = ['active' => 'Active', 'on_hold' => 'On hold (not issued)', 'closed' => 'Closed'];
    public const SERIAL_STATUSES = ['in_stock' => 'In stock', 'in_transit' => 'In transit', 'dispatched' => 'Dispatched', 'returned_to_supplier' => 'Returned to supplier',
        'consumed' => 'Consumed', 'written_off' => 'Written off', 'reversed' => 'Receipt reversed'];

    public function __construct(private readonly Database $db)
    {
    }

    public static function warningDays(): int
    {
        return max(1, (int) App::settings()->get('inventory_expiry_warning_days', 30));
    }

    /** expired | near_expiry | ok | none */
    public static function expiryStatus(?string $expiry): string
    {
        if ($expiry === null || $expiry === '') {
            return 'none';
        }
        $today = BusinessDate::today();
        if ($expiry < $today) {
            return 'expired';
        }
        return $expiry <= BusinessDate::addDays($today, self::warningDays()) ? 'near_expiry' : 'ok';
    }

    /**
     * Batches with stock per warehouse (within the user's warehouses).
     * @param array<string, mixed> $user @param array<string, mixed> $f @return list<array<string, mixed>>
     */
    public function batches(array $user, array $f): array
    {
        [$w, $p] = InventoryScope::sql($user, 's.warehouse_id');
        $sql = "SELECT b.*, p.name AS product_name, p.product_code, p.unit, v.name AS variant_name, sp.name AS supplier_name,
                (SELECT SUM(s.quantity) FROM inventory_stock_balances s WHERE s.batch_id = b.id AND s.stock_state <> 'in_transit' AND {$w}) AS qty_on_hand,
                (SELECT SUM(m.qty_in) FROM inventory_stock_movements m WHERE m.batch_id = b.id AND m.movement_type IN ('opening', 'purchase_receipt', 'customer_return', 'other_receipt', 'adjustment_in')) AS qty_received
                FROM inventory_batches b JOIN products p ON p.id = b.product_id LEFT JOIN product_variants v ON v.id = b.variant_id LEFT JOIN suppliers sp ON sp.id = b.supplier_id WHERE 1 = 1";
        $params = array_merge($p, []);
        if (($f['product'] ?? '') !== '') {
            $sql .= ' AND b.product_id = ?';
            $params[] = (int) $f['product'];
        }
        if (($f['warehouse'] ?? '') !== '') {
            $sql .= ' AND b.id IN (SELECT batch_id FROM inventory_stock_balances WHERE warehouse_id = ? AND quantity <> 0)';
            $params[] = (int) $f['warehouse'];
        }
        $today = BusinessDate::today();
        match ((string) ($f['expiry'] ?? '')) {
            'expired' => [$sql .= ' AND b.expiry_date < ?', $params[] = $today],
            'near' => [$sql .= ' AND b.expiry_date >= ? AND b.expiry_date <= ?', $params[] = $today, $params[] = BusinessDate::addDays($today, self::warningDays())],
            default => null,
        };
        if (($f['status'] ?? '') !== '' && isset(self::BATCH_STATUSES[$f['status']])) {
            $sql .= ' AND b.status = ?';
            $params[] = $f['status'];
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $sql .= ' AND (b.batch_no LIKE ? OR p.name LIKE ? OR p.product_code LIKE ?)';
            array_push($params, "%{$q}%", "%{$q}%", "%{$q}%");
        }
        $rows = $this->db->select($sql . ' ORDER BY CASE WHEN b.expiry_date IS NULL THEN 1 ELSE 0 END, b.expiry_date, b.batch_no LIMIT 500', $params);
        if (($f['stock'] ?? '') === 'in_stock') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => Qty::fromDb($r['qty_on_hand']) > 0));
        }
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function batch(int $id): ?array
    {
        return $this->db->first('SELECT b.*, p.name AS product_name, p.product_code, p.unit, v.name AS variant_name, sp.name AS supplier_name FROM inventory_batches b JOIN products p ON p.id = b.product_id
            LEFT JOIN product_variants v ON v.id = b.variant_id LEFT JOIN suppliers sp ON sp.id = b.supplier_id WHERE b.id = ?', [$id]);
    }

    /** Stock of a batch per warehouse, location and condition. @param array<string, mixed> $user @return list<array<string, mixed>> */
    public function batchStock(array $user, int $batchId): array
    {
        [$w, $p] = InventoryScope::sql($user, 's.warehouse_id');
        return $this->db->select("SELECT s.*, wh.code AS warehouse_code, wh.name AS warehouse_name, l.code AS location_code FROM inventory_stock_balances s JOIN warehouses wh ON wh.id = s.warehouse_id
            LEFT JOIN warehouse_locations l ON l.id = s.location_id WHERE s.batch_id = ? AND s.quantity <> 0 AND {$w} ORDER BY wh.name, l.code", array_merge([$batchId], $p));
    }

    /** @param array<string, mixed> $batch @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function updateBatch(array $batch, array $data, array $actor): void
    {
        $status = (string) ($data['status'] ?? $batch['status']);
        if (!isset(self::BATCH_STATUSES[$status])) {
            throw ValidationException::withMessage('status', 'Choose the batch status.');
        }
        if ($status === 'closed' && $this->db->scalar('SELECT 1 FROM inventory_stock_balances WHERE batch_id = ? AND quantity <> 0', [$batch['id']])) {
            throw ValidationException::withMessage('status', 'A batch with stock cannot be closed. Put it on hold instead.');
        }
        $supplier = ($data['supplier_id'] ?? '') !== '' && $data['supplier_id'] !== null ? (int) $data['supplier_id'] : null;
        if ($supplier !== null && !$this->db->scalar('SELECT 1 FROM suppliers WHERE id = ?', [$supplier])) {
            throw ValidationException::withMessage('supplier_id', 'Choose a supplier.');
        }
        $row = ['status' => $status, 'supplier_id' => $supplier, 'supplier_reference' => mb_substr(trim((string) ($data['supplier_reference'] ?? '')), 0, 100) ?: null,
            'purchase_reference' => mb_substr(trim((string) ($data['purchase_reference'] ?? '')), 0, 100) ?: null, 'notes' => mb_substr(trim((string) ($data['notes'] ?? '')), 0, 500) ?: null];
        $old = array_intersect_key($batch, $row);
        if ($old == $row) {
            return;
        }
        $this->db->update('inventory_batches', $row + ['updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $batch['id']]);
        InventoryHistory::write($this->db, 'batch', (int) $batch['id'], 'updated', $old, $row, null, $actor, productId: (int) $batch['product_id'], from: (string) $batch['status'], to: $status);
        App::activity()->log('inventory.batch_updated', "Updated batch {$batch['batch_no']}", 'inventory_batch', (int) $batch['id'], module: 'inventory', oldValues: $old, newValues: $row);
    }

    /** Ledger rows of a batch (within the user's warehouses). @param array<string, mixed> $user @return list<array<string, mixed>> */
    public function batchMovements(array $user, int $batchId): array
    {
        [$w, $p] = InventoryScope::sql($user, 'm.warehouse_id');
        return $this->db->select("SELECT m.*, wh.code AS warehouse_code, l.code AS location_code FROM inventory_stock_movements m JOIN warehouses wh ON wh.id = m.warehouse_id
            LEFT JOIN warehouse_locations l ON l.id = m.location_id WHERE m.batch_id = ? AND {$w} ORDER BY m.id DESC LIMIT 500", array_merge([$batchId], $p));
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f @return list<array<string, mixed>> */
    public function serials(array $user, array $f): array
    {
        [$w, $p] = InventoryScope::sql($user, 's.warehouse_id');
        $sql = "SELECT s.*, p.name AS product_name, p.product_code, v.name AS variant_name, wh.code AS warehouse_code, wh.name AS warehouse_name, l.code AS location_code, b.batch_no
                FROM inventory_serial_numbers s JOIN products p ON p.id = s.product_id LEFT JOIN product_variants v ON v.id = s.variant_id LEFT JOIN warehouses wh ON wh.id = s.warehouse_id
                LEFT JOIN warehouse_locations l ON l.id = s.location_id LEFT JOIN inventory_batches b ON b.id = s.batch_id
                WHERE ({$w} OR (s.warehouse_id IS NULL AND s.id IN (SELECT sm.serial_id FROM inventory_serial_movements sm JOIN inventory_stock_movements m ON m.id = sm.movement_id WHERE " . str_replace('s.warehouse_id', 'm.warehouse_id', $w) . ')))';
        $params = array_merge($p, $p);
        foreach (['product' => 's.product_id', 'warehouse' => 's.warehouse_id'] as $k => $col) {
            if (($f[$k] ?? '') !== '') {
                $sql .= " AND {$col} = ?";
                $params[] = (int) $f[$k];
            }
        }
        if (($f['status'] ?? '') !== '' && isset(self::SERIAL_STATUSES[$f['status']])) {
            $sql .= ' AND s.status = ?';
            $params[] = $f['status'];
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $sql .= ' AND (s.serial_no LIKE ? OR p.name LIKE ?)';
            array_push($params, '%' . strtoupper($q) . '%', "%{$q}%");
        }
        return $this->db->select($sql . ' ORDER BY s.updated_at DESC, s.id DESC LIMIT 500', $params);
    }

    /** @return array<string, mixed>|null */
    public function serial(int $id): ?array
    {
        return $this->db->first('SELECT s.*, p.name AS product_name, p.product_code, v.name AS variant_name, wh.code AS warehouse_code, wh.name AS warehouse_name, l.code AS location_code, b.batch_no
            FROM inventory_serial_numbers s JOIN products p ON p.id = s.product_id LEFT JOIN product_variants v ON v.id = s.variant_id LEFT JOIN warehouses wh ON wh.id = s.warehouse_id
            LEFT JOIN warehouse_locations l ON l.id = s.location_id LEFT JOIN inventory_batches b ON b.id = s.batch_id WHERE s.id = ?', [$id]);
    }

    /** Whether a user may see a serial: it is (or was) in one of their warehouses. @param array<string, mixed> $user */
    public function canSeeSerial(array $user, int $serialId): bool
    {
        $ids = InventoryScope::warehouseIds($user);
        if ($ids === null) {
            return true;
        }
        if ($ids === []) {
            return false;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        return (bool) $this->db->scalar("SELECT 1 FROM inventory_serial_movements sm JOIN inventory_stock_movements m ON m.id = sm.movement_id WHERE sm.serial_id = ? AND m.warehouse_id IN ({$in}) LIMIT 1",
            array_merge([$serialId], $ids));
    }

    /** Movement history of a serial (inward, transfers, outward). @return list<array<string, mixed>> */
    public function serialHistory(int $serialId): array
    {
        return $this->db->select('SELECT m.*, wh.code AS warehouse_code, wh.name AS warehouse_name, l.code AS location_code FROM inventory_serial_movements sm
            JOIN inventory_stock_movements m ON m.id = sm.movement_id JOIN warehouses wh ON wh.id = m.warehouse_id LEFT JOIN warehouse_locations l ON l.id = m.location_id
            WHERE sm.serial_id = ? ORDER BY m.id', [$serialId]);
    }

    /** Batches of an item that can be issued from a warehouse (for pickers). @return list<array<string, mixed>> */
    public function batchesForItem(int $warehouseId, int $productId, ?int $variantId): array
    {
        return $this->db->select("SELECT b.id, b.batch_no, b.expiry_date, b.status, SUM(s.quantity) AS qty FROM inventory_stock_balances s JOIN inventory_batches b ON b.id = s.batch_id
            WHERE s.warehouse_id = ? AND s.product_id = ? AND " . ($variantId === null ? 's.variant_id IS NULL' : 's.variant_id = ?') . " AND s.stock_state = 'sellable' AND s.quantity > 0
            GROUP BY b.id, b.batch_no, b.expiry_date, b.status ORDER BY b.expiry_date, b.batch_no", array_values(array_filter([$warehouseId, $productId, $variantId], static fn ($v): bool => $v !== null)));
    }

    /** @param array<string, mixed> $batch */
    public static function assertEditable(array $batch): void
    {
        if ($batch['status'] === 'closed') {
            throw new DomainRuleException('Closed batches are read-only.');
        }
    }
}
