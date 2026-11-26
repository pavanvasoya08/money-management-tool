<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Support\Qty;

/**
 * Inventory configuration of Phase 6 products. Tracking on/off and the stock unit stay on the
 * product itself (products.tracks_inventory / products.unit — one source); this service adds the
 * stock level (product or variant), default warehouse, reorder levels, negative stock, batch /
 * serial / expiry tracking, valuation method and inventory status. Settings that would change
 * the meaning of stock already on hand are locked while stock exists.
 */
final class ProductInventoryService
{
    public const LEVELS = ['product' => 'Product level', 'variant' => 'Variant level'];
    public const INVENTORY_STATUSES = ['active' => 'Active', 'on_hold' => 'On hold (no issues)', 'discontinued' => 'Discontinued (no receipts)'];
    private const QTY_FIELDS = ['reorder_level', 'min_qty', 'max_qty', 'safety_stock', 'reorder_qty'];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Effective inventory configuration of a product (defaults when never saved).
     * @return array<string, mixed>
     */
    public function config(int $productId): array
    {
        $p = $this->db->first('SELECT p.id, p.uuid, p.product_code, p.name, p.sku, p.unit, p.status, p.tracks_inventory, p.category_id, p.brand_id, t.is_service, t.has_components
            FROM products p JOIN product_types t ON t.id = p.product_type_id WHERE p.id = ?', [$productId]) ?? throw new DomainRuleException('Product not found.');
        $s = $this->db->first('SELECT * FROM product_inventory_settings WHERE product_id = ?', [$productId]);
        $hasVariants = (bool) $this->db->scalar("SELECT 1 FROM product_variants WHERE product_id = ? AND status <> 'archived'", [$productId]);
        $units = new UnitService($this->db);
        return [
            'product' => $p, 'saved' => $s !== null, 'tracks' => (int) $p['tracks_inventory'] === 1 && (int) $p['is_service'] === 0,
            'unit_code' => (string) $p['unit'], 'unit_name' => (string) ($units->byCode((string) $p['unit'])['name'] ?? $p['unit']), 'decimals' => $units->decimals((string) $p['unit']),
            'track_level' => $s['track_level'] ?? ($hasVariants ? 'variant' : 'product'), 'has_variants' => $hasVariants,
            'default_warehouse_id' => $s['default_warehouse_id'] ?? null,
            'reorder_level' => $s['reorder_level'] ?? null, 'min_qty' => $s['min_qty'] ?? null, 'max_qty' => $s['max_qty'] ?? null,
            'safety_stock' => $s['safety_stock'] ?? null, 'reorder_qty' => $s['reorder_qty'] ?? null,
            'allow_negative' => (int) ($s['allow_negative'] ?? 0) === 1, 'batch_tracking' => (int) ($s['batch_tracking'] ?? 0) === 1,
            'serial_tracking' => (int) ($s['serial_tracking'] ?? 0) === 1, 'expiry_tracking' => (int) ($s['expiry_tracking'] ?? 0) === 1,
            'valuation_method' => InventoryLedger::method($this->db, $productId), 'own_method' => $s['valuation_method'] ?? null,
            'inventory_status' => $s['inventory_status'] ?? 'active', 'notes' => $s['notes'] ?? null,
        ];
    }

    /**
     * Validate that a product / variant can move stock and return its configuration.
     * @return array<string, mixed>
     */
    public function assertStockable(int $productId, ?int $variantId, string $direction): array
    {
        $c = $this->config($productId);
        $p = $c['product'];
        if (!$c['tracks']) {
            throw ValidationException::withMessage('product_id', "{$p['name']} does not track inventory. Turn on inventory tracking on the product first.");
        }
        if ($direction === 'in' && $c['inventory_status'] === 'discontinued') {
            throw ValidationException::withMessage('product_id', "{$p['name']} is discontinued for inventory and cannot be received.");
        }
        if ($direction === 'out' && $c['inventory_status'] === 'on_hold') {
            throw ValidationException::withMessage('product_id', "{$p['name']} is on hold for inventory and cannot be issued.");
        }
        $variant = null;
        if ($c['track_level'] === 'variant') {
            if ($variantId === null) {
                throw ValidationException::withMessage('variant_id', "{$p['name']} keeps stock per variant — choose a variant.");
            }
            $variant = $this->db->first('SELECT * FROM product_variants WHERE id = ? AND product_id = ?', [$variantId, $productId]);
            if ($variant === null) {
                throw ValidationException::withMessage('variant_id', 'Choose a variant of this product.');
            }
        } elseif ($variantId !== null) {
            throw ValidationException::withMessage('variant_id', "{$p['name']} keeps stock at product level — leave the variant empty.");
        }
        $full = $this->db->first('SELECT * FROM products WHERE id = ?', [$productId]);
        try {
            \App\Services\Product\ProductService::assertUsable($full, $variant);
        } catch (DomainRuleException $e) {
            // Archived / discontinued products can still have their remaining stock issued or written off.
            if ($direction !== 'out' || !in_array($full['status'], ['discontinued', 'inactive', 'archived'], true)) {
                throw ValidationException::withMessage('product_id', $e->getMessage());
            }
        }
        return $c + ['variant' => $variant];
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function save(int $productId, array $data, array $actor): void
    {
        $c = $this->config($productId);
        if (!$c['tracks']) {
            throw new DomainRuleException('Turn on inventory tracking on the product first.');
        }
        $hasStock = $this->hasStockOnHand($productId);
        $hasMovements = (bool) $this->db->scalar('SELECT 1 FROM inventory_stock_movements WHERE product_id = ? LIMIT 1', [$productId]);
        $level = (string) ($data['track_level'] ?? $c['track_level']);
        if (!isset(self::LEVELS[$level])) {
            throw ValidationException::withMessage('track_level', 'Choose the stock level.');
        }
        if ($level === 'variant' && !$c['has_variants']) {
            throw ValidationException::withMessage('track_level', 'This product has no variants. Add variants before tracking stock per variant.');
        }
        if ($level !== $c['track_level'] && $hasMovements) {
            throw ValidationException::withMessage('track_level', 'Stock has already moved for this product, so the stock level can no longer change.');
        }
        $row = ['track_level' => $level];
        foreach (self::QTY_FIELDS as $k) {
            $raw = trim((string) ($data[$k] ?? ''));
            if ($raw === '') {
                $row[$k] = null;
                continue;
            }
            $q = Qty::parse($raw, $c['decimals']);
            if ($q === null) {
                throw ValidationException::withMessage($k, 'Enter a quantity of 0 or more' . ($c['decimals'] === 0 ? ' (whole units).' : ' (up to ' . $c['decimals'] . ' decimals).'));
            }
            $row[$k] = Qty::toDb($q);
        }
        if ($row['min_qty'] !== null && $row['max_qty'] !== null && Qty::fromDb($row['max_qty']) < Qty::fromDb($row['min_qty'])) {
            throw ValidationException::withMessage('max_qty', 'Maximum stock cannot be below minimum stock.');
        }
        $wh = ($data['default_warehouse_id'] ?? '') !== '' ? (int) $data['default_warehouse_id'] : null;
        if ($wh !== null && $this->db->scalar('SELECT status FROM warehouses WHERE id = ?', [$wh]) !== 'active') {
            throw ValidationException::withMessage('default_warehouse_id', 'Choose an active warehouse.');
        }
        $row['default_warehouse_id'] = $wh;
        $flags = [];
        foreach (['allow_negative', 'batch_tracking', 'serial_tracking', 'expiry_tracking'] as $k) {
            $flags[$k] = !empty($data[$k]) ? 1 : 0;
        }
        if ($flags['expiry_tracking'] && !$flags['batch_tracking']) {
            throw ValidationException::withMessage('expiry_tracking', 'Expiry dates are kept per batch — turn on batch tracking too.');
        }
        if ($flags['serial_tracking'] && $c['decimals'] > 0) {
            throw ValidationException::withMessage('serial_tracking', 'Serial numbers need a whole-number unit (e.g. pieces).');
        }
        if ($flags['serial_tracking'] && $flags['allow_negative']) {
            throw ValidationException::withMessage('allow_negative', 'Serial-tracked products cannot go negative: every unit is a registered serial number.');
        }
        foreach (['batch_tracking', 'serial_tracking'] as $k) {
            if ($flags[$k] !== (int) $c[$k] && $hasStock) {
                throw ValidationException::withMessage($k, 'Batch and serial tracking can only be switched while the product has no stock on hand.');
            }
        }
        $method = (string) ($data['valuation_method'] ?? '');
        $method = isset(InventoryLedger::METHODS[$method]) ? $method : null;
        if ($method !== $c['own_method'] && ($method ?? InventoryLedger::method($this->db, $productId)) !== $c['valuation_method'] && $hasStock) {
            throw ValidationException::withMessage('valuation_method', 'The valuation method can only change while the product has no stock on hand, so earlier costs stay consistent.');
        }
        $status = (string) ($data['inventory_status'] ?? 'active');
        if (!isset(self::INVENTORY_STATUSES[$status])) {
            throw ValidationException::withMessage('inventory_status', 'Choose the inventory status.');
        }
        $row += $flags + ['valuation_method' => $method, 'inventory_status' => $status, 'notes' => mb_substr(trim((string) ($data['notes'] ?? '')), 0, 500) ?: null];
        $old = $this->db->first('SELECT * FROM product_inventory_settings WHERE product_id = ?', [$productId]);
        $changes = [];
        foreach ($row as $k => $v) {
            if ((string) ($old[$k] ?? '') !== (string) ($v ?? '') && !($old === null && ($v === null || $v === 0 || $v === 'active'))) {
                $changes[$k] = [$old[$k] ?? null, $v];
            }
        }
        if ($old !== null && $changes === []) {
            return;
        }
        $this->db->transaction(function () use ($productId, $row, $old, $changes, $actor, $c, $method): void {
            if ($old === null) {
                $this->db->insert('product_inventory_settings', $row + ['product_id' => $productId, 'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $this->db->update('product_inventory_settings', $row + ['updated_by' => (int) $actor['id'], 'updated_at' => now()], ['product_id' => $productId]);
            }
            if (isset($changes['valuation_method'])) {
                $this->db->insert('inventory_valuation_history', ['event' => 'product_method', 'product_id' => $productId, 'old_method' => $c['valuation_method'],
                    'new_method' => $method ?? InventoryLedger::method($this->db, $productId), 'reason' => 'Product inventory settings', 'created_by' => (int) $actor['id'], 'created_at' => now()]);
            }
            InventoryHistory::write($this->db, 'settings', $productId, 'settings_saved', array_map(static fn (array $x): mixed => $x[0], $changes), array_map(static fn (array $x): mixed => $x[1], $changes),
                null, $actor, productId: $productId);
        });
        App::activity()->log('inventory.settings_saved', "Saved inventory settings of {$c['product']['product_code']}", 'product', $productId, module: 'inventory',
            oldValues: array_map(static fn (array $x): mixed => $x[0], $changes), newValues: array_map(static fn (array $x): mixed => $x[1], $changes));
    }

    public function hasStockOnHand(int $productId): bool
    {
        foreach ($this->db->select('SELECT qty_on_hand FROM inventory_stock_items WHERE product_id = ?', [$productId]) as $r) {
            if (Qty::fromDb($r['qty_on_hand']) !== 0) {
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------- reorder rules (per variant and / or warehouse)

    /** @return list<array<string, mixed>> */
    public function rules(int $productId): array
    {
        return $this->db->select('SELECT r.*, v.name AS variant_name, w.code AS warehouse_code, w.name AS warehouse_name FROM inventory_reorder_rules r
            LEFT JOIN product_variants v ON v.id = r.variant_id LEFT JOIN warehouses w ON w.id = r.warehouse_id WHERE r.product_id = ? AND r.is_active = 1
            ORDER BY r.warehouse_id, r.variant_id', [$productId]);
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function saveRule(int $productId, array $data, array $actor): int
    {
        $c = $this->config($productId);
        $variant = ($data['variant_id'] ?? '') !== '' ? (int) $data['variant_id'] : null;
        $wh = ($data['warehouse_id'] ?? '') !== '' ? (int) $data['warehouse_id'] : null;
        if ($variant === null && $wh === null) {
            throw ValidationException::withMessage('warehouse_id', 'Choose a warehouse and/or variant — product-wide levels are set in the inventory settings above.');
        }
        if ($variant !== null && !$this->db->scalar('SELECT 1 FROM product_variants WHERE id = ? AND product_id = ?', [$variant, $productId])) {
            throw ValidationException::withMessage('variant_id', 'Choose a variant of this product.');
        }
        if ($wh !== null && !$this->db->scalar("SELECT 1 FROM warehouses WHERE id = ? AND status <> 'archived'", [$wh])) {
            throw ValidationException::withMessage('warehouse_id', 'Choose a warehouse.');
        }
        $row = [];
        foreach (self::QTY_FIELDS as $k) {
            $raw = trim((string) ($data[$k] ?? ''));
            $q = $raw === '' ? null : Qty::parse($raw, $c['decimals']);
            if ($raw !== '' && $q === null) {
                throw ValidationException::withMessage($k, 'Enter a quantity of 0 or more.');
            }
            $row[$k] = $q !== null ? Qty::toDb($q) : null;
        }
        if ($row['min_qty'] !== null && $row['max_qty'] !== null && Qty::fromDb($row['max_qty']) < Qty::fromDb($row['min_qty'])) {
            throw ValidationException::withMessage('max_qty', 'Maximum stock cannot be below minimum stock.');
        }
        $key = $productId . ':' . ($variant ?? 0) . ':' . ($wh ?? 0);
        $existing = $this->db->first('SELECT * FROM inventory_reorder_rules WHERE scope_key = ?', [$key]);
        if ($existing !== null) {
            $this->db->update('inventory_reorder_rules', $row + ['is_active' => 1, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $id = $this->db->insert('inventory_reorder_rules', $row + ['product_id' => $productId, 'variant_id' => $variant, 'warehouse_id' => $wh, 'scope_key' => $key, 'is_active' => 1,
                'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()]);
        }
        InventoryHistory::write($this->db, 'settings', $productId, 'reorder_rule_saved', $existing !== null ? array_intersect_key($existing, $row) : null, $row + ['variant_id' => $variant, 'warehouse_id' => $wh],
            null, $actor, $wh, productId: $productId);
        App::activity()->log('inventory.reorder_rule_saved', "Saved a reorder rule for {$c['product']['product_code']}", 'product', $productId, module: 'inventory', newValues: $row + ['variant_id' => $variant, 'warehouse_id' => $wh]);
        return $id;
    }

    /** @param array<string, mixed> $actor */
    public function endRule(int $productId, int $ruleId, array $actor): void
    {
        $r = $this->db->first('SELECT * FROM inventory_reorder_rules WHERE id = ? AND product_id = ? AND is_active = 1', [$ruleId, $productId]) ?? throw new DomainRuleException('Rule not found.');
        $this->db->update('inventory_reorder_rules', ['is_active' => 0, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $ruleId]);
        InventoryHistory::write($this->db, 'settings', $productId, 'reorder_rule_removed', ['variant_id' => $r['variant_id'], 'warehouse_id' => $r['warehouse_id']], null, null, $actor,
            $r['warehouse_id'] !== null ? (int) $r['warehouse_id'] : null, productId: $productId);
    }

    /**
     * Reorder levels that apply to an item in a warehouse: most specific rule first
     * (variant + warehouse, warehouse, variant), then the product settings.
     * @return array<string, int|null> scaled quantities
     */
    public function levels(int $productId, ?int $variantId, ?int $warehouseId): array
    {
        $candidates = [];
        foreach ([[$variantId, $warehouseId], [null, $warehouseId], [$variantId, null]] as [$v, $w]) {
            if (($v !== null || $w !== null) && ($v === $variantId || $v === null) && ($w === $warehouseId || $w === null)) {
                $candidates[] = $productId . ':' . ($v ?? 0) . ':' . ($w ?? 0);
            }
        }
        $row = null;
        foreach (array_unique($candidates) as $key) {
            $row = $this->db->first('SELECT * FROM inventory_reorder_rules WHERE scope_key = ? AND is_active = 1', [$key]);
            if ($row !== null) {
                break;
            }
        }
        $row ??= $this->db->first('SELECT * FROM product_inventory_settings WHERE product_id = ?', [$productId]) ?? [];
        $out = [];
        foreach (self::QTY_FIELDS as $k) {
            $out[$k] = isset($row[$k]) && $row[$k] !== null ? Qty::fromDb($row[$k]) : null;
        }
        return $out;
    }
}
