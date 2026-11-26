<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Config;
use App\Core\Seeder;

/** Starter permission templates (inserted once; freely editable afterwards). */
final class TemplateSeeder extends Seeder
{
    /** Phase 7 actions of the Inventory Manager template (also re-applied once to existing installs by InventorySeeder). */
    public const INVENTORY_MANAGER = [
        'dashboard.view', 'inventory.view_dashboard', 'inventory.view', 'inventory.view_assigned', 'inventory.view_stock_ledger', 'inventory.view_stock_cost',
        'inventory.manage_locations', 'inventory.manage_stock_inward', 'inventory.manage_stock_outward', 'inventory.manage_stock_transfers', 'inventory.approve_stock_transfers',
        'inventory.receive_stock_transfers', 'inventory.manage_stock_adjustments', 'inventory.approve_stock_adjustments', 'inventory.manage_batches', 'inventory.manage_serial_numbers',
        'inventory.manage_reservations', 'inventory.release_reservations', 'inventory.manage_reorder_settings', 'inventory.manage_documents', 'inventory.download_documents',
        'inventory.view_history', 'inventory.export', 'inventory.print',
        'products.view', 'products.view_all', 'suppliers.view', 'suppliers.view_all', 'purchases.view', 'purchases.view_assigned', 'purchases.create',
    ];

    public function run(): void
    {
        $modules = array_keys((array) Config::get('modules', []));
        $readOnly = [];
        foreach ((array) Config::get('modules', []) as $key => $def) {
            if (!empty($def['super_admin_only'])) {
                continue;
            }
            foreach (['view', 'view_all', 'export'] as $a) {
                if (isset($def['actions'][$a])) {
                    $readOnly[] = "{$key}.{$a}";
                }
            }
        }
        unset($modules);

        $templates = [
            'standard-accountant' => ['Standard Accountant', 'Day-to-day bookkeeping on assigned records.', [
                'dashboard.view', 'expenses.view', 'expenses.view_assigned', 'expenses.create', 'expenses.edit', 'expenses.export',
                'purchases.view', 'purchases.view_assigned', 'purchases.create', 'accounting.view', 'accounting.view_assigned', 'accounting.create',
                'gst_reports.view', 'gst_reports.view_assigned', 'gst_reports.export',
            ]],
            'senior-accountant' => ['Senior Accountant', 'Full accounting access including approvals.', [
                'dashboard.view', 'expenses.view', 'expenses.view_all', 'expenses.create', 'expenses.edit', 'expenses.approve', 'expenses.reject', 'expenses.export',
                'purchases.view', 'purchases.view_all', 'purchases.create', 'purchases.edit', 'purchases.approve', 'purchases.reject',
                'accounting.view', 'accounting.view_all', 'accounting.create', 'accounting.edit', 'accounting.approve', 'accounting.reject', 'accounting.export',
                'gst_reports.view', 'gst_reports.view_all', 'gst_reports.export', 'financial_reports.view', 'financial_reports.view_all', 'financial_reports.export',
            ]],
            'inventory-manager' => ['Inventory Manager', 'Runs assigned warehouses: stock in and out, transfers, counts, batches, serials and reservations, with approval rights for transfers and adjustments.', self::INVENTORY_MANAGER],
            'stock-operator' => ['Stock Operator', 'Records stock inward, outward and transfer requests for assigned warehouses; cannot approve, adjust or see costs.', [
                'dashboard.view', 'inventory.view_dashboard', 'inventory.view', 'inventory.view_assigned', 'inventory.manage_stock_inward', 'inventory.manage_stock_outward',
                'inventory.manage_stock_transfers', 'inventory.receive_stock_transfers', 'inventory.manage_batches', 'inventory.manage_serial_numbers', 'inventory.download_documents',
                'products.view', 'products.view_all',
            ]],
            'sales-manager' => ['Sales Manager', 'Orders, returns and settlements for assigned seller accounts.', [
                'dashboard.view', 'sales_orders.view', 'sales_orders.view_assigned', 'sales_orders.create', 'sales_orders.edit', 'sales_orders.cancel_order', 'sales_orders.export',
                'returns_refunds.view', 'returns_refunds.view_assigned', 'returns_refunds.approve', 'returns_refunds.reject',
                'platforms.view', 'platforms.view_assigned', 'settlements.view', 'settlements.view_assigned',
            ]],
            'partner-limited' => ['Partner Limited Access', 'A partner\'s own profile, capital and reports.', [
                'dashboard.view', 'partners.view', 'partners.view_own', 'capital_contributions.view', 'capital_contributions.view_own',
                'financial_reports.view', 'financial_reports.view_assigned',
            ]],
            'read-only-auditor' => ['Read-Only Auditor', 'View and export everything; change nothing.', array_merge($readOnly, ['activity_logs.view', 'activity_logs.export'])],
        ];

        $now = now();
        foreach ($templates as $slug => [$name, $description, $permissions]) {
            if ($this->db->scalar('SELECT 1 FROM permission_templates WHERE slug = ?', [$slug])) {
                continue;
            }
            $id = $this->db->insert('permission_templates', ['name' => $name, 'slug' => $slug, 'description' => $description, 'created_at' => $now, 'updated_at' => $now]);
            foreach (array_unique($permissions) as $p) {
                $pid = $this->db->scalar('SELECT id FROM permissions WHERE slug = ?', [$p]);
                if ($pid !== null) {
                    $this->db->insert('permission_template_items', ['template_id' => $id, 'permission_id' => $pid]);
                }
            }
        }
    }
}
