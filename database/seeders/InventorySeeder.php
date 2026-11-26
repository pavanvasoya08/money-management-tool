<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Seeder;

/**
 * Phase 7 masters: warehouse types and units of measure (codes match Phase 6 product units),
 * plus the standard global unit conversions. All are editable. Creates no warehouses, stock
 * or documents. Role defaults, the Phase 2 placeholder field defaults and the Inventory
 * Manager template are updated once (later changes by a Super Admin are kept).
 */
final class InventorySeeder extends Seeder
{
    /** code => [name, description] */
    public const WAREHOUSE_TYPES = [
        'main' => ['Main warehouse', 'Primary storage location of the business'],
        'secondary' => ['Secondary warehouse', 'Additional storage'],
        'fulfilment' => ['Fulfilment warehouse', 'Picks, packs and ships orders'],
        'marketplace' => ['Marketplace warehouse', 'Stock held at a marketplace fulfilment centre (e.g. FBA, Flipkart Smart Fulfilment)'],
        'retail_store' => ['Retail store', 'Shop floor and store room'],
        'returns' => ['Return warehouse', 'Receives and inspects returned goods'],
        'damaged' => ['Damaged goods warehouse', 'Holds damaged stock awaiting repair, return or write-off'],
        'other' => ['Other', 'Any other kind of storage'],
    ];

    /** code => [name, type, decimals] — codes match ProductService::UNITS so products keep their unit. */
    public const UNITS = [
        'pcs' => ['Pieces', 'count', 0], 'set' => ['Set', 'count', 0], 'pack' => ['Pack', 'count', 0], 'box' => ['Box', 'count', 0], 'ctn' => ['Carton', 'count', 0],
        'pair' => ['Pair', 'count', 0], 'dozen' => ['Dozen', 'count', 0], 'kg' => ['Kilogram', 'weight', 3], 'g' => ['Gram', 'weight', 2], 'l' => ['Litre', 'volume', 3],
        'ml' => ['Millilitre', 'volume', 1], 'm' => ['Metre', 'length', 2], 'cm' => ['Centimetre', 'length', 1], 'sqm' => ['Square metre', 'area', 2],
        'hour' => ['Hour', 'time', 2], 'service' => ['Service unit', 'other', 2],
    ];

    /** Global conversions: [from, to, factor] meaning 1 from = factor to. */
    public const CONVERSIONS = [['dozen', 'pcs', '12'], ['pair', 'pcs', '2'], ['kg', 'g', '1000'], ['l', 'ml', '1000'], ['m', 'cm', '100']];

    public const ROLE_DEFAULTS = [
        'accountant' => ['inventory.view_dashboard', 'inventory.view', 'inventory.view_all', 'inventory.view_stock_cost', 'inventory.view_stock_ledger', 'inventory.download_documents',
            'inventory.view_history', 'inventory.export', 'inventory.print'],
        'manager' => ['inventory.view_dashboard', 'inventory.view', 'inventory.view_all', 'inventory.view_stock_ledger', 'inventory.manage_locations',
            'inventory.manage_stock_inward', 'inventory.manage_stock_outward', 'inventory.manage_stock_transfers', 'inventory.receive_stock_transfers',
            'inventory.manage_stock_adjustments', 'inventory.manage_batches', 'inventory.manage_serial_numbers', 'inventory.manage_reservations', 'inventory.release_reservations',
            'inventory.manage_documents', 'inventory.download_documents', 'inventory.view_history', 'inventory.export', 'inventory.print'],
    ];

    /** Phase 2 seeded these fields as "hidden"; costs are now guarded by inventory.view_stock_cost as well. */
    private const FIELD_DEFAULTS = [['inventory', 'stock_value', 'editable'], ['inventory', 'unit_cost', 'editable']];

    public function run(): void
    {
        $now = now();
        $order = 0;
        foreach (self::WAREHOUSE_TYPES as $code => [$name, $desc]) {
            $order += 10;
            if (!$this->db->scalar('SELECT 1 FROM warehouse_types WHERE code = ?', [$code])) {
                $this->db->insert('warehouse_types', ['code' => $code, 'name' => $name, 'description' => $desc, 'is_active' => 1, 'is_system' => 1, 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $order = 0;
        foreach (self::UNITS as $code => [$name, $type, $decimals]) {
            $order += 10;
            if (!$this->db->scalar('SELECT 1 FROM inventory_units WHERE code = ?', [$code])) {
                $this->db->insert('inventory_units', ['code' => $code, 'name' => $name, 'unit_type' => $type, 'decimals' => $decimals, 'is_active' => 1, 'is_system' => 1,
                    'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        foreach (self::CONVERSIONS as [$from, $to, $factor]) {
            $f = (int) $this->db->scalar('SELECT id FROM inventory_units WHERE code = ?', [$from]);
            $t = (int) $this->db->scalar('SELECT id FROM inventory_units WHERE code = ?', [$to]);
            $key = '*:' . min($f, $t) . ':' . max($f, $t);
            if ($f && $t && !$this->db->scalar('SELECT 1 FROM inventory_unit_conversions WHERE scope_key = ?', [$key])) {
                $this->db->insert('inventory_unit_conversions', ['product_id' => null, 'from_unit_id' => $f, 'to_unit_id' => $t, 'factor' => $factor . '.000000', 'scope_key' => $key,
                    'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $this->releaseOnce();
    }

    private function releaseOnce(): void
    {
        if ($this->db->scalar("SELECT 1 FROM system_settings WHERE setting_key = 'inventory_defaults_released'")) {
            return;
        }
        $now = now();
        foreach (self::ROLE_DEFAULTS as $slug => $permissions) {
            $role = $this->db->scalar('SELECT id FROM roles WHERE slug = ?', [$slug]);
            if ($role === null) {
                continue;
            }
            foreach ($permissions as $perm) {
                $pid = $this->db->scalar('SELECT id FROM permissions WHERE slug = ?', [$perm]);
                if ($pid !== null && !$this->db->scalar('SELECT 1 FROM role_permissions WHERE role_id = ? AND permission_id = ?', [$role, $pid])) {
                    $this->db->insert('role_permissions', ['role_id' => $role, 'permission_id' => $pid, 'data_scope' => 'all', 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
        foreach (self::FIELD_DEFAULTS as [$module, $field, $default]) {
            $this->db->execute('UPDATE module_fields SET default_access = ?, updated_at = ? WHERE field_key = ? AND module_id = (SELECT id FROM modules WHERE module_key = ?)', [$default, $now, $field, $module]);
        }
        // The Inventory Manager template was created in Phase 2 with placeholder actions that are now retired.
        $template = $this->db->scalar("SELECT id FROM permission_templates WHERE slug = 'inventory-manager'");
        if ($template !== null) {
            foreach (TemplateSeeder::INVENTORY_MANAGER as $perm) {
                $pid = $this->db->scalar('SELECT id FROM permissions WHERE slug = ?', [$perm]);
                if ($pid !== null && !$this->db->scalar('SELECT 1 FROM permission_template_items WHERE template_id = ? AND permission_id = ?', [$template, $pid])) {
                    $this->db->insert('permission_template_items', ['template_id' => $template, 'permission_id' => $pid]);
                }
            }
        }
        $this->db->insert('system_settings', ['setting_key' => 'inventory_defaults_released', 'value' => '1', 'type' => 'boolean', 'group_name' => 'internal', 'created_at' => $now, 'updated_at' => $now]);
    }
}
