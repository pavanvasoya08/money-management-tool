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
 * Shared line handling for stock documents: validates a line against the product's inventory
 * configuration (stock level, unit and decimals, batch / serial / expiry tracking), converts the
 * entered unit to the stock unit, resolves or creates batches and serial numbers when posting,
 * and allocates issues across locations and batches (FEFO or FIFO, skipping expired and on-hold
 * batches unless an override applies).
 */
final class StockLineService
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Normalise and validate one line as entered (no stock check — that happens when posting).
     * @param array<string, mixed> $line @param array<string, mixed> $warehouse
     * @return array<string, mixed> clean line incl. base_quantity (scaled) and config
     */
    public function normalize(array $line, array $warehouse, string $direction, int $lineNo, bool $requireCost = false, bool $allowZeroCost = true): array
    {
        $field = static fn (string $f): string => "lines.{$lineNo}.{$f}";
        $productId = (int) ($line['product_id'] ?? 0);
        if ($productId === 0) {
            throw ValidationException::withMessage($field('product_id'), "Line {$lineNo}: choose a product.");
        }
        $variantId = ($line['variant_id'] ?? '') !== '' && $line['variant_id'] !== null ? (int) $line['variant_id'] : null;
        $pis = new ProductInventoryService($this->db);
        try {
            $c = $pis->assertStockable($productId, $variantId, $direction === 'in' ? 'in' : 'out');
        } catch (ValidationException $e) {
            throw ValidationException::withMessage($field('product_id'), "Line {$lineNo}: " . (array_values($e->errors)[0][0] ?? 'invalid product.'));
        }
        $unit = trim((string) ($line['unit_code'] ?? '')) ?: $c['unit_code'];
        $units = new UnitService($this->db);
        $qty = Qty::parse((string) ($line['quantity'] ?? ''), $units->decimals($unit));
        if ($qty === null || $qty <= 0) {
            throw ValidationException::withMessage($field('quantity'), "Line {$lineNo}: enter a quantity greater than 0" . ($units->decimals($unit) === 0 ? ' in whole units.' : '.'));
        }
        try {
            $base = $units->toStockUnit($productId, $unit, $c['unit_code'], $qty);
        } catch (ValidationException $e) {
            throw ValidationException::withMessage($field('unit_code'), "Line {$lineNo}: " . (array_values($e->errors)[0][0] ?? 'invalid unit.'));
        }
        if ($c['decimals'] === 0 && !Qty::isWhole($base)) {
            throw ValidationException::withMessage($field('quantity'), "Line {$lineNo}: this comes to " . Qty::format($base) . " {$c['unit_name']}; {$c['unit_name']} must be whole.");
        }
        $serials = self::serialList((string) ($line['serial_numbers'] ?? ''));
        if ($c['serial_tracking']) {
            if (!Qty::isWhole($base)) {
                throw ValidationException::withMessage($field('quantity'), "Line {$lineNo}: serial-tracked quantities must be whole.");
            }
            if (count($serials) !== intdiv($base, Qty::SCALE)) {
                throw ValidationException::withMessage($field('serial_numbers'), "Line {$lineNo}: enter exactly " . Qty::format($base) . ' serial numbers, one per unit (' . count($serials) . ' entered).');
            }
            if (count(array_unique(array_map('strtoupper', $serials))) !== count($serials)) {
                throw ValidationException::withMessage($field('serial_numbers'), "Line {$lineNo}: the same serial number is listed twice.");
            }
        } elseif ($serials !== []) {
            throw ValidationException::withMessage($field('serial_numbers'), "Line {$lineNo}: {$c['product']['name']} does not track serial numbers.");
        }
        $locations = new LocationService($this->db);
        try {
            $loc = $locations->resolve((int) $warehouse['id'], $line['location_id'] ?? null, $direction === 'in' && (int) $warehouse['uses_locations'] === 1);
        } catch (ValidationException $e) {
            throw ValidationException::withMessage($field('location_id'), "Line {$lineNo}: " . (array_values($e->errors)[0][0] ?? 'invalid location.'));
        }
        $batchId = ($line['batch_id'] ?? '') !== '' && $line['batch_id'] !== null ? (int) $line['batch_id'] : null;
        $batchNo = strtoupper(trim((string) ($line['batch_no'] ?? '')));
        $mfg = trim((string) ($line['mfg_date'] ?? '')) ?: null;
        $exp = trim((string) ($line['expiry_date'] ?? '')) ?: null;
        if ($c['batch_tracking']) {
            if ($batchId !== null) {
                $b = $this->db->first('SELECT * FROM inventory_batches WHERE id = ?', [$batchId]);
                if ($b === null || (int) $b['product_id'] !== $productId || (int) ($b['variant_id'] ?? 0) !== (int) ($variantId ?? 0)) {
                    throw ValidationException::withMessage($field('batch_id'), "Line {$lineNo}: choose a batch of this product.");
                }
                $batchNo = '';
            } elseif ($direction === 'in') {
                if ($batchNo === '' || !preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,59}$/', $batchNo)) {
                    throw ValidationException::withMessage($field('batch_no'), "Line {$lineNo}: enter the batch number (letters, digits, . _ / -).");
                }
                if ($mfg !== null && !BusinessDate::isValid($mfg)) {
                    throw ValidationException::withMessage($field('mfg_date'), "Line {$lineNo}: enter a valid manufacturing date.");
                }
                if ($exp !== null && !BusinessDate::isValid($exp)) {
                    throw ValidationException::withMessage($field('expiry_date'), "Line {$lineNo}: enter a valid expiry date.");
                }
                if ($c['expiry_tracking'] && $exp === null && !$this->db->scalar('SELECT 1 FROM inventory_batches WHERE batch_key = ?', [self::batchKey($productId, $variantId, $batchNo)])) {
                    throw ValidationException::withMessage($field('expiry_date'), "Line {$lineNo}: {$c['product']['name']} tracks expiry — enter the expiry date of the batch.");
                }
                if ($mfg !== null && $exp !== null && $exp < $mfg) {
                    throw ValidationException::withMessage($field('expiry_date'), "Line {$lineNo}: the expiry date cannot be before the manufacturing date.");
                }
            }
        } elseif ($batchId !== null || $batchNo !== '') {
            throw ValidationException::withMessage($field('batch_no'), "Line {$lineNo}: {$c['product']['name']} does not track batches.");
        }
        $cost = null;
        if (trim((string) ($line['unit_cost'] ?? '')) !== '') {
            $cost = Qty::parse((string) $line['unit_cost']);
            if ($cost === null || (!$allowZeroCost && $cost === 0)) {
                throw ValidationException::withMessage($field('unit_cost'), "Line {$lineNo}: enter the unit cost as a number (up to 4 decimals)" . ($allowZeroCost ? '.' : ' greater than 0.'));
            }
            if ($unit !== $c['unit_code'] && $qty !== $base && empty($line['cost_per_stock_unit'])) {
                // cost entered per entered unit → per stock unit
                $cost = Qty::mulDiv($cost, $qty, $base);
            }
        } elseif ($requireCost) {
            throw ValidationException::withMessage($field('unit_cost'), "Line {$lineNo}: enter the unit cost.");
        }
        return [
            'product_id' => $productId, 'variant_id' => $variantId, 'location_id' => $loc, 'batch_id' => $batchId, 'batch_no' => $batchNo !== '' ? $batchNo : null,
            'mfg_date' => $mfg, 'expiry_date' => $exp, 'serials' => $serials, 'quantity' => $qty, 'unit_code' => $unit, 'base_quantity' => $base, 'unit_cost' => $cost,
            'notes' => mb_substr(trim((string) ($line['notes'] ?? '')), 0, 255) ?: null, 'config' => $c,
        ];
    }

    /** @return list<string> */
    public static function serialList(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\r\n,;]+/', $text) ?: [] as $s) {
            $s = strtoupper(trim($s));
            if ($s !== '') {
                if (!preg_match('/^[A-Z0-9][A-Z0-9._\/:-]{0,99}$/', $s)) {
                    throw ValidationException::withMessage('serial_numbers', "Serial number “{$s}” contains characters that are not allowed.");
                }
                $out[] = $s;
            }
        }
        return $out;
    }

    public static function batchKey(int $productId, ?int $variantId, string $batchNo): string
    {
        return $productId . ':' . ($variantId ?? 0) . ':' . strtoupper($batchNo);
    }

    /**
     * Find or create the batch of an inward line (inside the posting transaction).
     * @param array<string, mixed> $l normalised line @param array<string, mixed> $ctx supplier_id, reference, actor
     */
    public function resolveInwardBatch(array $l, array $ctx): ?int
    {
        if (!$l['config']['batch_tracking']) {
            return null;
        }
        if ($l['batch_id'] !== null) {
            return (int) $l['batch_id'];
        }
        $key = self::batchKey((int) $l['product_id'], $l['variant_id'], (string) $l['batch_no']);
        $b = $this->db->first('SELECT * FROM inventory_batches WHERE batch_key = ?', [$key]);
        if ($b !== null) {
            foreach (['mfg_date', 'expiry_date'] as $k) {
                if ($l[$k] !== null && $b[$k] !== null && $l[$k] !== $b[$k]) {
                    throw new DomainRuleException("Batch {$b['batch_no']} is already recorded with " . ($k === 'mfg_date' ? 'manufacturing' : 'expiry') . ' date ' . BusinessDate::format((string) $b[$k])
                        . '. Use the same date, or correct the batch first.');
                }
            }
            if ($b['status'] === 'closed') {
                throw new DomainRuleException("Batch {$b['batch_no']} is closed.");
            }
            return (int) $b['id'];
        }
        $id = $this->db->insert('inventory_batches', [
            'product_id' => (int) $l['product_id'], 'variant_id' => $l['variant_id'], 'batch_no' => (string) $l['batch_no'], 'batch_key' => $key,
            'mfg_date' => $l['mfg_date'], 'expiry_date' => $l['expiry_date'], 'supplier_id' => $ctx['supplier_id'] ?? null,
            'supplier_reference' => isset($ctx['supplier_id']) && $ctx['supplier_id'] ? mb_substr((string) ($ctx['reference'] ?? ''), 0, 100) ?: null : null,
            'purchase_reference' => ($ctx['entry_type'] ?? '') === 'purchase_receipt' ? mb_substr((string) ($ctx['reference'] ?? ''), 0, 100) ?: null : null,
            'unit_cost' => $l['unit_cost'] !== null ? Qty::toDb((int) $l['unit_cost']) : null, 'status' => 'active',
            'created_by' => (int) $ctx['actor']['id'], 'updated_by' => (int) $ctx['actor']['id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        InventoryHistory::write($this->db, 'batch', $id, 'created', null, ['batch_no' => $l['batch_no'], 'mfg_date' => $l['mfg_date'], 'expiry_date' => $l['expiry_date']],
            null, $ctx['actor'], isset($ctx['warehouse_id']) ? (int) $ctx['warehouse_id'] : null, productId: (int) $l['product_id']);
        return $id;
    }

    /**
     * Register (or re-receive) the serial numbers of an inward line. A serial that is currently
     * in stock or in transit anywhere is a duplicate; one that left stock earlier can come back
     * (e.g. a customer return).
     * @param array<string, mixed> $l @param array<string, mixed> $ctx
     * @return list<int>
     */
    public function registerSerials(array $l, ?int $batchId, array $ctx): array
    {
        $ids = [];
        foreach ($l['serials'] as $s) {
            $key = $l['product_id'] . ':' . $s;
            $row = $this->db->first('SELECT * FROM inventory_serial_numbers WHERE serial_key = ?', [$key]);
            if ($row !== null) {
                if (in_array($row['status'], ['in_stock', 'in_transit'], true)) {
                    throw new DomainRuleException("Serial number {$s} is already in stock.");
                }
                $ids[] = (int) $row['id'];
                continue;
            }
            $id = $this->db->insert('inventory_serial_numbers', ['product_id' => (int) $l['product_id'], 'variant_id' => $l['variant_id'], 'serial_no' => $s, 'serial_key' => $key,
                'batch_id' => $batchId, 'status' => 'in_stock', 'unit_cost' => $l['unit_cost'] !== null ? Qty::toDb((int) $l['unit_cost']) : null,
                'created_by' => (int) $ctx['actor']['id'], 'updated_by' => (int) $ctx['actor']['id'], 'created_at' => now(), 'updated_at' => now()]);
            InventoryHistory::write($this->db, 'serial', $id, 'registered', null, ['serial_no' => $s], null, $ctx['actor'], isset($ctx['warehouse_id']) ? (int) $ctx['warehouse_id'] : null, productId: (int) $l['product_id']);
            $ids[] = $id;
        }
        return $ids;
    }

    /**
     * Serial numbers to issue from a warehouse: each must be in stock there, in the given condition
     * (and location / batch when the line names one). Grouped by location and batch.
     * @param list<string> $serials
     * @return list<array{location_id: int|null, batch_id: int|null, qty: int, serial_ids: list<int>}>
     */
    public function serialAllocation(int $productId, ?int $variantId, int $warehouseId, string $state, array $serials, ?int $locationId, ?int $batchId, string $status = 'in_stock'): array
    {
        $groups = [];
        foreach ($serials as $s) {
            $row = $this->db->first('SELECT * FROM inventory_serial_numbers WHERE serial_key = ?', [$productId . ':' . $s]);
            if ($row === null || (int) ($row['variant_id'] ?? 0) !== (int) ($variantId ?? 0)) {
                throw new DomainRuleException("Serial number {$s} is not registered for this item.");
            }
            if ($row['status'] !== $status || (int) $row['warehouse_id'] !== $warehouseId || (string) $row['stock_state'] !== $state) {
                throw new DomainRuleException("Serial number {$s} is not available here (" . str_replace('_', ' ', (string) $row['status']) . ').');
            }
            if ($locationId !== null && (int) $row['location_id'] !== $locationId) {
                throw new DomainRuleException("Serial number {$s} is in another location.");
            }
            if ($batchId !== null && (int) $row['batch_id'] !== $batchId) {
                throw new DomainRuleException("Serial number {$s} belongs to another batch.");
            }
            $k = ($row['location_id'] ?? 0) . ':' . ($row['batch_id'] ?? 0);
            $groups[$k] ??= ['location_id' => $row['location_id'] !== null ? (int) $row['location_id'] : null, 'batch_id' => $row['batch_id'] !== null ? (int) $row['batch_id'] : null, 'qty' => 0, 'serial_ids' => []];
            $groups[$k]['qty'] += Qty::SCALE;
            $groups[$k]['serial_ids'][] = (int) $row['id'];
        }
        return array_values($groups);
    }

    /**
     * Allocate an issue across the balance rows of an item (location × batch) in one condition.
     * Batches: FEFO or FIFO per settings; expired batches only when $allowExpired, on-hold batches never.
     * With $allowNegative the shortfall is put on the line's own location / batch.
     * @return list<array{location_id: int|null, batch_id: int|null, qty: int}>
     */
    public function allocate(int $itemId, string $state, int $qty, ?int $locationId, ?int $batchId, bool $allowExpired, bool $allowNegative): array
    {
        $order = (string) App::settings()->get('inventory_removal_method', 'fefo') === 'fifo' ? 'b.created_at, b.id' : 'CASE WHEN b.expiry_date IS NULL THEN 1 ELSE 0 END, b.expiry_date, b.created_at, b.id';
        $sql = "SELECT s.location_id, s.batch_id, s.quantity, b.expiry_date, b.status AS batch_status, b.batch_no, l.code AS location_code
                FROM inventory_stock_balances s LEFT JOIN inventory_batches b ON b.id = s.batch_id LEFT JOIN warehouse_locations l ON l.id = s.location_id
                WHERE s.stock_item_id = ? AND s.stock_state = ? AND s.quantity > 0";
        $params = [$itemId, $state];
        if ($locationId !== null) {
            $sql .= ' AND s.location_id = ?';
            $params[] = $locationId;
        }
        if ($batchId !== null) {
            $sql .= ' AND s.batch_id = ?';
            $params[] = $batchId;
        }
        $today = BusinessDate::today();
        $need = $qty;
        $out = [];
        $skippedExpired = 0;
        foreach ($this->db->select($sql . " ORDER BY {$order}, l.code", $params) as $r) {
            if ($need <= 0) {
                break;
            }
            if ($r['batch_id'] !== null && $r['batch_status'] === 'on_hold') {
                continue;
            }
            if ($r['batch_id'] !== null && $r['expiry_date'] !== null && $r['expiry_date'] < $today && !$allowExpired) {
                $skippedExpired += Qty::fromDb($r['quantity']);
                continue;
            }
            $take = min($need, Qty::fromDb($r['quantity']));
            $out[] = ['location_id' => $r['location_id'] !== null ? (int) $r['location_id'] : null, 'batch_id' => $r['batch_id'] !== null ? (int) $r['batch_id'] : null, 'qty' => $take];
            $need -= $take;
        }
        if ($need > 0) {
            if (!$allowNegative) {
                throw new DomainRuleException('Not enough stock: ' . Qty::format($qty - $need) . ' of ' . Qty::format($qty) . ' can be issued'
                    . ($skippedExpired > 0 ? ' (' . Qty::format($skippedExpired) . ' more are in expired batches)' : '') . '.');
            }
            $out[] = ['location_id' => $locationId, 'batch_id' => $batchId, 'qty' => $need];
        }
        return $out;
    }

    /** Sellable quantity that could be issued now (not expired unless allowed, not on hold). */
    public function issuable(int $itemId, bool $allowExpired = false): int
    {
        $sum = 0;
        $today = BusinessDate::today();
        foreach ($this->db->select("SELECT s.quantity, b.expiry_date, b.status FROM inventory_stock_balances s LEFT JOIN inventory_batches b ON b.id = s.batch_id
            WHERE s.stock_item_id = ? AND s.stock_state = 'sellable' AND s.quantity > 0", [$itemId]) as $r) {
            if (!$allowExpired && ($r['status'] === 'on_hold' || ($r['expiry_date'] !== null && $r['expiry_date'] < $today))) {
                continue;
            }
            $sum += Qty::fromDb($r['quantity']);
        }
        return $sum;
    }

    /** Lines as stored on a document → arrays for normalize(). @param array<string, mixed> $row @return array<string, mixed> */
    public static function storedToInput(array $row): array
    {
        return ['product_id' => $row['product_id'], 'variant_id' => $row['variant_id'], 'location_id' => $row['location_id'], 'batch_id' => $row['batch_id'],
            'batch_no' => $row['batch_no'] ?? null, 'mfg_date' => $row['mfg_date'] ?? null, 'expiry_date' => $row['expiry_date'] ?? null, 'serial_numbers' => $row['serial_numbers'] ?? '',
            'quantity' => Qty::toInput(Qty::fromDb($row['quantity'])), 'unit_code' => $row['unit_code'] ?? null,
            'unit_cost' => isset($row['unit_cost']) && $row['unit_cost'] !== null ? Qty::toInput(Qty::fromDb($row['unit_cost'])) : '', 'cost_per_stock_unit' => true, 'notes' => $row['notes'] ?? null];
    }
}
