<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Config;
use App\Core\Seeder;

/** Starter permission templates (inserted once; freely editable afterwards). */
final class TemplateSeeder extends Seeder
{
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
            'inventory-manager' => ['Inventory Manager', 'Stock, products and suppliers for assigned warehouses.', [
                'dashboard.view', 'inventory.view', 'inventory.view_assigned', 'inventory.create', 'inventory.edit', 'inventory.adjust_stock', 'inventory.transfer_stock', 'inventory.export',
                'products.view', 'products.view_all', 'products.edit', 'suppliers.view', 'suppliers.view_all', 'purchases.view', 'purchases.view_assigned', 'purchases.create',
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
