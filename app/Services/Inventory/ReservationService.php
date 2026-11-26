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
 * Stock reservations against a reference (a future Phase 9 sales order, an internal requirement
 * or another authorised reference). Reserved quantity is not available to other issues. A
 * reservation can never exceed the stock that is free (on hand, not expired, not already
 * reserved). One open reservation per reference, warehouse and item (unique dedupe_key).
 * Outward documents that name the reservation fulfil it; releasing, cancelling or expiry frees it.
 *
 * Integration for Phase 9: reserve() with reference_type 'sales_order' and the order id, then
 * outward 'sales_dispatch' lines linked to the reservation items, or releaseReference() when
 * the order is cancelled — stock is never deducted twice.
 */
final class ReservationService
{
    public const REFERENCE_TYPES = ['sales_order' => 'Sales order', 'internal' => 'Internal requirement', 'other' => 'Other authorised reference'];
    public const STATUSES = ['active' => 'Active', 'partially_fulfilled' => 'Partially fulfilled', 'fulfilled' => 'Fulfilled', 'released' => 'Released', 'expired' => 'Expired', 'cancelled' => 'Cancelled'];
    private const OPEN = ['active', 'partially_fulfilled'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT r.*, w.code AS warehouse_code, w.name AS warehouse_name, cu.name AS created_by_name, xu.name AS closed_by_name FROM inventory_reservations r
            JOIN warehouses w ON w.id = r.warehouse_id LEFT JOIN users cu ON cu.id = r.created_by LEFT JOIN users xu ON xu.id = r.closed_by WHERE r.id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function items(int $reservationId): array
    {
        return $this->db->select('SELECT ri.*, p.name AS product_name, p.product_code, p.sku, p.unit, v.name AS variant_name, v.sku AS variant_sku FROM inventory_reservation_items ri
            JOIN products p ON p.id = ri.product_id LEFT JOIN product_variants v ON v.id = ri.variant_id WHERE ri.reservation_id = ? ORDER BY ri.id', [$reservationId]);
    }

    /**
     * Open reservation items of a warehouse item (for outward lines).
     * @return list<array<string, mixed>>
     */
    public function openItemsFor(int $warehouseId, int $productId, ?int $variantId): array
    {
        $this->expireDue();
        return $this->db->select("SELECT ri.*, r.reservation_no, r.reference_type, r.reference_no FROM inventory_reservation_items ri JOIN inventory_reservations r ON r.id = ri.reservation_id
            WHERE r.warehouse_id = ? AND ri.product_id = ? AND " . ($variantId === null ? 'ri.variant_id IS NULL' : 'ri.variant_id = ?') . " AND ri.status IN ('active', 'partially_fulfilled') ORDER BY r.reservation_date, r.id",
            array_values(array_filter([$warehouseId, $productId, $variantId], static fn ($v): bool => $v !== null)));
    }

    /**
     * Reserve stock. @param array<string, mixed> $data reference_type, reference_no, reference_id, warehouse_id, reservation_date, expires_on, notes, lines[product_id, variant_id, quantity]
     * @param array<string, mixed> $actor
     */
    public function reserve(array $data, array $actor): int
    {
        $this->expireDue();
        $type = (string) ($data['reference_type'] ?? '');
        $refNo = trim((string) ($data['reference_no'] ?? ''));
        if (!isset(self::REFERENCE_TYPES[$type])) {
            throw ValidationException::withMessage('reference_type', 'Choose what the stock is reserved for.');
        }
        if ($refNo === '' || mb_strlen($refNo) > 100) {
            throw ValidationException::withMessage('reference_no', 'Enter the reference (e.g. the order number).');
        }
        $refId = ($data['reference_id'] ?? '') !== '' && $data['reference_id'] !== null ? (int) $data['reference_id'] : null;
        $wh = (new WarehouseService($this->db))->find((int) ($data['warehouse_id'] ?? 0));
        if ($wh === null) {
            throw ValidationException::withMessage('warehouse_id', 'Choose a warehouse.');
        }
        WarehouseService::assertUsable($wh);
        $date = trim((string) ($data['reservation_date'] ?? '')) ?: BusinessDate::today();
        if (!BusinessDate::isValid($date)) {
            throw ValidationException::withMessage('reservation_date', 'Enter a valid date.');
        }
        $expires = trim((string) ($data['expires_on'] ?? ''));
        if ($expires === '' && ($days = (int) App::settings()->get('inventory_reservation_days', 7)) > 0) {
            $expires = BusinessDate::addDays($date, $days);
        }
        if ($expires !== '' && (!BusinessDate::isValid($expires) || $expires < $date)) {
            throw ValidationException::withMessage('expires_on', 'The expiry date must be on or after the reservation date.');
        }
        $lines = [];
        $lineNo = 0;
        foreach ((array) ($data['lines'] ?? []) as $l) {
            if (!is_array($l) || (int) ($l['product_id'] ?? 0) === 0) {
                continue;
            }
            $lineNo++;
            $variant = ($l['variant_id'] ?? '') !== '' && $l['variant_id'] !== null ? (int) $l['variant_id'] : null;
            $c = (new ProductInventoryService($this->db))->assertStockable((int) $l['product_id'], $variant, 'out');
            $q = Qty::parse((string) ($l['quantity'] ?? ''), $c['decimals']);
            if ($q === null || $q <= 0) {
                throw ValidationException::withMessage("lines.{$lineNo}.quantity", "Line {$lineNo}: enter a quantity greater than 0.");
            }
            $k = $l['product_id'] . ':' . ($variant ?? 0);
            if (isset($lines[$k])) {
                throw ValidationException::withMessage("lines.{$lineNo}.product_id", "Line {$lineNo}: this item is listed twice.");
            }
            $lines[$k] = ['product_id' => (int) $l['product_id'], 'variant_id' => $variant, 'quantity' => $q, 'name' => $c['product']['name'], 'unit' => $c['unit_name']];
        }
        if ($lines === []) {
            throw ValidationException::withMessage('lines', 'Add at least one item.');
        }
        $id = $this->db->transaction(function () use ($type, $refNo, $refId, $wh, $date, $expires, $data, $lines, $actor): int {
            $ledger = new InventoryLedger($this->db);
            $stock = new StockLineService($this->db);
            $items = [];
            foreach ($lines as $k => $l) {
                $items[$k] = $ledger->item((int) $wh['id'], $l['product_id'], $l['variant_id']);
            }
            $ledger->lock(array_map(static fn (array $i): int => (int) $i['id'], $items));
            foreach ($lines as $k => $l) {
                $free = $stock->issuable((int) $items[$k]['id']) - $ledger->reserved((int) $wh['id'], $l['product_id'], $l['variant_id']);
                if ($l['quantity'] > $free) {
                    throw new DomainRuleException("Only " . Qty::format(max(0, $free)) . " {$l['unit']} of {$l['name']} are free to reserve in {$wh['name']}; " . Qty::format($l['quantity']) . ' requested.');
                }
            }
            $id = $this->db->insert('inventory_reservations', ['reservation_no' => InventoryNumbers::next($this->db, 'reservation'), 'reference_type' => $type, 'reference_no' => $refNo,
                'reference_id' => $refId, 'warehouse_id' => (int) $wh['id'], 'reservation_date' => $date, 'expires_on' => $expires !== '' ? $expires : null, 'status' => 'active',
                'notes' => mb_substr(trim((string) ($data['notes'] ?? '')), 0, 500) ?: null, 'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()]);
            foreach ($lines as $l) {
                $dedupe = $type . ':' . mb_strtoupper($refNo) . ':' . $wh['id'] . ':' . $l['product_id'] . ':' . ($l['variant_id'] ?? 0);
                try {
                    $this->db->insert('inventory_reservation_items', ['reservation_id' => $id, 'product_id' => $l['product_id'], 'variant_id' => $l['variant_id'], 'quantity' => Qty::toDb($l['quantity']),
                        'qty_fulfilled' => '0.0000', 'qty_released' => '0.0000', 'status' => 'active', 'dedupe_key' => mb_substr($dedupe, 0, 200), 'created_at' => now(), 'updated_at' => now()]);
                } catch (\PDOException) {
                    throw new DomainRuleException("{$l['name']} is already reserved for {$refNo} in this warehouse. Change that reservation instead of adding a second one.");
                }
            }
            InventoryHistory::write($this->db, 'reservation', $id, 'created', null, ['reference' => $refNo, 'type' => $type, 'items' => count($lines)], null, $actor, (int) $wh['id'], to: 'active');
            return $id;
        });
        $r = $this->find($id);
        App::activity()->log('inventory.reservation_created', "Reserved stock {$r['reservation_no']} for {$refNo}", 'inventory_reservation', $id, module: 'inventory',
            newValues: ['reference_type' => $type, 'reference_no' => $refNo, 'warehouse' => $wh['code'], 'items' => array_values(array_map(static fn (array $l): array => ['product_id' => $l['product_id'], 'variant_id' => $l['variant_id'], 'qty' => Qty::toDb($l['quantity'])], $lines))]);
        return $id;
    }

    /** Release (no longer needed) or cancel (source cancelled) the open part of a reservation. @param array<string, mixed> $r @param array<string, mixed> $actor */
    public function close(array $r, string $to, string $reason, array $actor): void
    {
        if (!in_array($to, ['released', 'cancelled'], true)) {
            throw new DomainRuleException('Unknown action.');
        }
        if (!in_array($r['status'], self::OPEN, true)) {
            throw new DomainRuleException('This reservation is no longer open.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $this->db->transaction(function () use ($r, $to, $reason, $actor): void {
            foreach ($this->db->select("SELECT * FROM inventory_reservation_items WHERE reservation_id = ? AND status IN ('active', 'partially_fulfilled')", [$r['id']]) as $i) {
                $left = Qty::fromDb($i['quantity']) - Qty::fromDb($i['qty_fulfilled']) - Qty::fromDb($i['qty_released']);
                $this->db->update('inventory_reservation_items', ['qty_released' => Qty::toDb(Qty::fromDb($i['qty_released']) + max(0, $left)),
                    'status' => Qty::fromDb($i['qty_fulfilled']) > 0 && $to === 'released' ? 'fulfilled' : $to, 'dedupe_key' => null, 'updated_at' => now()], ['id' => $i['id']]);
            }
            $this->db->update('inventory_reservations', ['status' => $to, 'close_reason' => mb_substr($reason, 0, 255), 'closed_by' => (int) $actor['id'], 'closed_at' => now(), 'updated_at' => now()], ['id' => $r['id']]);
            InventoryHistory::write($this->db, 'reservation', (int) $r['id'], $to, null, null, $reason, $actor, (int) $r['warehouse_id'], (string) $r['status'], $to);
        });
        App::activity()->log('inventory.reservation_' . $to, ucfirst($to) . " reservation {$r['reservation_no']}", 'inventory_reservation', (int) $r['id'], module: 'inventory',
            oldValues: ['status' => $r['status']], newValues: ['status' => $to, 'reason' => $reason]);
    }

    /** Phase 9 hook: cancel every open reservation of a reference (e.g. a cancelled order). @param array<string, mixed> $actor */
    public function releaseReference(string $type, string $referenceNo, string $reason, array $actor): int
    {
        $n = 0;
        foreach ($this->db->select("SELECT * FROM inventory_reservations WHERE reference_type = ? AND reference_no = ? AND status IN ('active', 'partially_fulfilled')", [$type, $referenceNo]) as $r) {
            $this->close($r, 'cancelled', $reason, $actor);
            $n++;
        }
        return $n;
    }

    /** Called by outward posting (inside its transaction). */
    public function fulfil(int $itemId, int $qty): void
    {
        $i = $this->db->first('SELECT * FROM inventory_reservation_items WHERE id = ?', [$itemId]) ?? throw new DomainRuleException('Reservation line not found.');
        if (!in_array($i['status'], self::OPEN, true)) {
            throw new DomainRuleException('The linked reservation is no longer open.');
        }
        $left = Qty::fromDb($i['quantity']) - Qty::fromDb($i['qty_fulfilled']) - Qty::fromDb($i['qty_released']);
        if ($qty > $left) {
            throw new DomainRuleException('The line issues ' . Qty::format($qty) . ' but only ' . Qty::format($left) . ' are still reserved on the linked reservation.');
        }
        $done = $qty === $left;
        $this->db->update('inventory_reservation_items', ['qty_fulfilled' => Qty::toDb(Qty::fromDb($i['qty_fulfilled']) + $qty), 'status' => $done ? 'fulfilled' : 'partially_fulfilled',
            'dedupe_key' => $done ? null : $i['dedupe_key'], 'updated_at' => now()], ['id' => $itemId]);
        $this->refreshHeader((int) $i['reservation_id']);
    }

    /** Called when an outward document is reversed. */
    public function unfulfil(int $itemId, int $qty): void
    {
        $i = $this->db->first('SELECT i.*, r.reference_type, r.reference_no, r.warehouse_id FROM inventory_reservation_items i JOIN inventory_reservations r ON r.id = i.reservation_id WHERE i.id = ?', [$itemId]);
        if ($i === null) {
            return;
        }
        $fulfilled = max(0, Qty::fromDb($i['qty_fulfilled']) - $qty);
        $status = $i['status'];
        $dedupe = $i['dedupe_key'];
        if (in_array($status, ['fulfilled', 'partially_fulfilled', 'active'], true)) {
            $status = $fulfilled === 0 ? 'active' : 'partially_fulfilled';
            $dedupe = $i['reference_type'] . ':' . mb_strtoupper((string) $i['reference_no']) . ':' . $i['warehouse_id'] . ':' . $i['product_id'] . ':' . ($i['variant_id'] ?? 0);
            if ($this->db->scalar('SELECT 1 FROM inventory_reservation_items WHERE dedupe_key = ? AND id <> ?', [$dedupe, $itemId])) {
                $dedupe = null;
            }
        }
        $this->db->update('inventory_reservation_items', ['qty_fulfilled' => Qty::toDb($fulfilled), 'status' => $status, 'dedupe_key' => $dedupe, 'updated_at' => now()], ['id' => $itemId]);
        $this->refreshHeader((int) $i['reservation_id']);
    }

    /** Expire open reservations whose date has passed (run before every availability check and listing). */
    public function expireDue(): int
    {
        $today = BusinessDate::today();
        $due = $this->db->select("SELECT * FROM inventory_reservations WHERE status IN ('active', 'partially_fulfilled') AND expires_on IS NOT NULL AND expires_on < ?", [$today]);
        foreach ($due as $r) {
            $this->db->transaction(function () use ($r): void {
                foreach ($this->db->select("SELECT * FROM inventory_reservation_items WHERE reservation_id = ? AND status IN ('active', 'partially_fulfilled')", [$r['id']]) as $i) {
                    $left = Qty::fromDb($i['quantity']) - Qty::fromDb($i['qty_fulfilled']) - Qty::fromDb($i['qty_released']);
                    $this->db->update('inventory_reservation_items', ['qty_released' => Qty::toDb(Qty::fromDb($i['qty_released']) + max(0, $left)), 'status' => 'expired', 'dedupe_key' => null, 'updated_at' => now()], ['id' => $i['id']]);
                }
                $this->db->update('inventory_reservations', ['status' => 'expired', 'close_reason' => 'Expired on ' . BusinessDate::format((string) $r['expires_on']), 'closed_at' => now(), 'updated_at' => now()], ['id' => $r['id']]);
                InventoryHistory::write($this->db, 'reservation', (int) $r['id'], 'expired', null, null, 'Reservation date passed', [], (int) $r['warehouse_id'], from: (string) $r['status'], to: 'expired');
            });
        }
        return count($due);
    }

    /**
     * @param array<string, mixed> $user @param array<string, mixed> $f
     * @return list<array<string, mixed>>
     */
    public function list(array $user, array $f): array
    {
        $this->expireDue();
        [$w, $p] = InventoryScope::sql($user, 'r.warehouse_id');
        $sql = "SELECT r.*, w.code AS warehouse_code, w.name AS warehouse_name, u.name AS created_by_name,
                (SELECT COUNT(*) FROM inventory_reservation_items i WHERE i.reservation_id = r.id) AS item_count
                FROM inventory_reservations r JOIN warehouses w ON w.id = r.warehouse_id LEFT JOIN users u ON u.id = r.created_by WHERE {$w}";
        if (($f['status'] ?? '') === 'open' || ($f['status'] ?? '') === '') {
            $sql .= " AND r.status IN ('active', 'partially_fulfilled')";
        } elseif (isset(self::STATUSES[$f['status']])) {
            $sql .= ' AND r.status = ?';
            $p[] = $f['status'];
        }
        if (($f['warehouse'] ?? '') !== '') {
            $sql .= ' AND r.warehouse_id = ?';
            $p[] = (int) $f['warehouse'];
        }
        if (($f['type'] ?? '') !== '' && isset(self::REFERENCE_TYPES[$f['type']])) {
            $sql .= ' AND r.reference_type = ?';
            $p[] = $f['type'];
        }
        if (($f['product'] ?? '') !== '') {
            $sql .= ' AND r.id IN (SELECT reservation_id FROM inventory_reservation_items WHERE product_id = ?)';
            $p[] = (int) $f['product'];
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $sql .= ' AND (r.reservation_no LIKE ? OR r.reference_no LIKE ?)';
            array_push($p, "%{$q}%", "%{$q}%");
        }
        return $this->db->select($sql . ' ORDER BY r.reservation_date DESC, r.id DESC LIMIT 500', $p);
    }

    private function refreshHeader(int $reservationId): void
    {
        $statuses = array_column($this->db->select('SELECT status FROM inventory_reservation_items WHERE reservation_id = ?', [$reservationId]), 'status');
        $r = $this->db->first('SELECT status FROM inventory_reservations WHERE id = ?', [$reservationId]);
        if ($r === null || !in_array($r['status'], ['active', 'partially_fulfilled', 'fulfilled'], true)) {
            return;
        }
        $open = array_intersect($statuses, self::OPEN);
        $status = $open === [] ? 'fulfilled' : (in_array('partially_fulfilled', $statuses, true) || in_array('fulfilled', $statuses, true) ? 'partially_fulfilled' : 'active');
        $this->db->update('inventory_reservations', ['status' => $status, 'updated_at' => now()], ['id' => $reservationId]);
    }
}
