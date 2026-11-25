<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Seeder;

/**
 * Phase 6 master data: product types, supplier types, variant attributes (without values —
 * values are business data), tax categories and the GST rate master, plus — once — the new
 * default grants for existing roles and the field-rule defaults that replace the Phase 2
 * placeholders. Creates no products, suppliers, prices or HSN codes. Idempotent.
 */
final class ProductSeeder extends Seeder
{
    /** code => [name, description, is_service, tracks_inventory, has_components] */
    public const PRODUCT_TYPES = [
        'physical' => ['Physical product', 'A tangible item that is stocked and shipped', false, true, false],
        'bundle' => ['Bundle / combo', 'Several products sold together; stock comes from its components', false, false, true],
        'kit' => ['Kit', 'Components assembled into one stocked item', false, true, true],
        'service' => ['Service', 'A service; uses a SAC code instead of HSN and is not stocked', true, false, false],
        'other' => ['Other / custom', 'Any other kind of product', false, true, false],
    ];

    /** code => [name, description] */
    public const SUPPLIER_TYPES = [
        'manufacturer' => ['Manufacturer', 'Makes the products it supplies'],
        'distributor' => ['Distributor', 'Authorised distributor of one or more brands'],
        'wholesaler' => ['Wholesaler', 'Sells in bulk to businesses'],
        'retailer' => ['Retailer', 'Retail seller'],
        'importer' => ['Importer', 'Imports goods from outside India'],
        'service_provider' => ['Service provider', 'Provides services (packaging, logistics, repairs, …)'],
        'other' => ['Other / custom', 'Any other kind of supplier'],
    ];

    /** code => [name, input type, unit] — values are added by the business. */
    public const ATTRIBUTES = [
        'size' => ['Size', 'select', null],
        'color' => ['Colour', 'select', null],
        'material' => ['Material', 'select', null],
        'weight' => ['Weight', 'number', 'g'],
        'capacity' => ['Capacity', 'number', 'ml'],
        'packaging' => ['Packaging type', 'select', null],
    ];

    /** code => [name, description] */
    public const TAX_CATEGORIES = [
        'goods' => ['Goods', 'Goods taxed at a standard GST rate'],
        'services' => ['Services', 'Services (SAC codes)'],
        'precious' => ['Precious metals & stones', 'Gold, silver, jewellery, diamonds and similar special-rate goods'],
        'demerit' => ['Luxury & demerit goods', 'Goods in the special 40% slab'],
        'other' => ['Other', 'Any other tax category'],
    ];

    /**
     * code => [name, rate %, active, description]. Slabs in force since 22 September 2025 are
     * active; the retired 12% and 28% slabs are kept inactive so older configurations can be
     * recorded with their real rate. Super Admins can add, change or retire rates.
     */
    public const GST_RATES = [
        'GST0' => ['Nil (0%)', '0.00', true, 'Nil-rated supplies'],
        'GST5' => ['GST 5%', '5.00', true, 'Main slab from 22 Sep 2025'],
        'GST18' => ['GST 18%', '18.00', true, 'Main slab from 22 Sep 2025'],
        'GST40' => ['GST 40%', '40.00', true, 'Special slab for notified luxury and demerit goods from 22 Sep 2025'],
        'GST3' => ['GST 3%', '3.00', true, 'Special rate (e.g. gold, silver, jewellery)'],
        'GST1_5' => ['GST 1.5%', '1.50', true, 'Special rate (e.g. cut and polished diamonds)'],
        'GST0_25' => ['GST 0.25%', '0.25', true, 'Special rate (e.g. rough diamonds)'],
        'GST0_1' => ['GST 0.1%', '0.10', true, 'Special rate (e.g. supplies to merchant exporters)'],
        'GST12' => ['GST 12% (retired)', '12.00', false, 'Slab removed from 22 Sep 2025; kept for older configurations'],
        'GST28' => ['GST 28% (retired)', '28.00', false, 'Slab removed from 22 Sep 2025; kept for older configurations'],
    ];

    /** Applied once to existing roles (Phase 2 created placeholder permissions for both modules). */
    public const ROLE_DEFAULTS = [
        'accountant' => ['products.view_dashboard', 'products.view', 'products.view_all', 'products.download_documents', 'products.view_history', 'products.export', 'products.print',
            'suppliers.view_dashboard', 'suppliers.view', 'suppliers.view_assigned', 'suppliers.download_documents', 'suppliers.view_history', 'suppliers.export', 'suppliers.print'],
        'manager' => ['products.view_dashboard', 'products.view', 'products.view_all', 'products.create', 'products.edit', 'products.manage_variants', 'products.manage_supplier_mappings',
            'products.manage_documents', 'products.download_documents', 'products.view_history', 'products.export', 'products.print',
            'suppliers.view_dashboard', 'suppliers.view', 'suppliers.view_all', 'suppliers.create', 'suppliers.edit', 'suppliers.manage_addresses', 'suppliers.manage_product_mappings',
            'suppliers.manage_documents', 'suppliers.download_documents', 'suppliers.view_history', 'suppliers.export', 'suppliers.print'],
    ];

    /** Phase 2 placeholder field defaults replaced once: [module, field, new default]. */
    private const FIELD_DEFAULTS = [
        ['products', 'purchase_cost', 'editable'],
        ['products', 'selling_price', 'editable'],
        ['products', 'supplier_details', 'editable'],
        ['suppliers', 'bank_account_number', 'visible'],
    ];

    public function run(): void
    {
        $now = now();
        $order = 0;
        foreach (self::PRODUCT_TYPES as $code => [$name, $desc, $service, $tracks, $components]) {
            $order += 10;
            $this->insertOnce('product_types', $code, ['name' => $name, 'description' => $desc, 'is_service' => (int) $service, 'tracks_inventory' => (int) $tracks,
                'has_components' => (int) $components, 'is_active' => 1, 'is_system' => 1, 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now]);
        }
        $order = 0;
        foreach (self::SUPPLIER_TYPES as $code => [$name, $desc]) {
            $order += 10;
            $this->insertOnce('supplier_types', $code, ['name' => $name, 'description' => $desc, 'is_active' => 1, 'is_system' => 1, 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now]);
        }
        $order = 0;
        foreach (self::ATTRIBUTES as $code => [$name, $type, $unit]) {
            $order += 10;
            $this->insertOnce('product_attributes', $code, ['name' => $name, 'input_type' => $type, 'unit' => $unit, 'is_active' => 1, 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now]);
        }
        $order = 0;
        foreach (self::TAX_CATEGORIES as $code => [$name, $desc]) {
            $order += 10;
            $this->insertOnce('product_tax_categories', $code, ['name' => $name, 'description' => $desc, 'is_active' => 1, 'is_system' => 1, 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now]);
        }
        $order = 0;
        foreach (self::GST_RATES as $code => [$name, $rate, $active, $desc]) {
            $order += 10;
            $this->insertOnce('gst_tax_rates', $code, ['name' => $name, 'rate' => $rate, 'cess_rate' => '0.00', 'description' => $desc, 'is_active' => (int) $active, 'is_system' => 1,
                'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->releaseOnce();
    }

    /** @param array<string, mixed> $row */
    private function insertOnce(string $table, string $code, array $row): void
    {
        if (!$this->db->scalar("SELECT 1 FROM {$table} WHERE code = ?", [$code])) {
            $this->db->insert($table, ['code' => $code] + $row);
        }
    }

    private function releaseOnce(): void
    {
        if ($this->db->scalar("SELECT 1 FROM system_settings WHERE setting_key = 'product_defaults_released'")) {
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
        $this->db->insert('system_settings', ['setting_key' => 'product_defaults_released', 'value' => '1', 'type' => 'boolean', 'group_name' => 'internal', 'created_at' => $now, 'updated_at' => $now]);
    }
}
