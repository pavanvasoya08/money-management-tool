<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Seeder;

/**
 * Phase 5 master data: bank account types, extra business activities (in the
 * Phase 4 activity master) and, once, the new default grants for existing roles.
 * Creates no bank accounts or transactions. Idempotent.
 */
final class BankSeeder extends Seeder
{
    /** code => [name, description, allows overdraft by default] */
    public const TYPES = [
        'current' => ['Current account', 'Business current account', false],
        'savings' => ['Savings account', 'Savings account', false],
        'cash_credit' => ['Cash credit account', 'Working-capital limit against stock or receivables', true],
        'overdraft' => ['Overdraft account', 'Account with a sanctioned overdraft limit', true],
        'fixed_deposit' => ['Fixed deposit account', 'Term deposit', false],
        'other' => ['Other account', 'Any other account type', false],
    ];

    /** Added to the Phase 4 business-activity master (shared by GST registrations and bank accounts). */
    public const ACTIVITIES = [
        'marketplace_settlements' => ['Marketplace settlements', 'Receipts settled by marketplaces'],
        'supplier_payments' => ['Supplier payments', 'Payments to suppliers and vendors'],
        'general_operations' => ['General business operations', 'Day-to-day business payments and receipts'],
    ];

    /**
     * Grants applied once to existing roles (Phase 2 created bank_accounts placeholder
     * permissions, so normal "first introduction" defaults would not reach them).
     */
    public const ROLE_DEFAULTS = [
        'partner' => ['bank_accounts.view_own'],
        'accountant' => ['bank_accounts.view_dashboard', 'bank_accounts.view', 'bank_accounts.view_assigned', 'bank_accounts.manage_transactions', 'bank_accounts.create_deposit',
            'bank_accounts.create_withdrawal', 'bank_accounts.manage_statements', 'bank_accounts.import_statements', 'bank_accounts.manage_reconciliation',
            'bank_accounts.download_documents', 'bank_accounts.view_history', 'bank_accounts.export', 'bank_accounts.print'],
        'manager' => ['bank_accounts.view_dashboard', 'bank_accounts.view', 'bank_accounts.view_all'],
    ];

    public function run(): void
    {
        $now = now();
        $order = 0;
        foreach (self::TYPES as $code => [$name, $desc, $od]) {
            $order += 10;
            if (!$this->db->scalar('SELECT 1 FROM bank_account_types WHERE code = ?', [$code])) {
                $this->db->insert('bank_account_types', ['code' => $code, 'name' => $name, 'description' => $desc, 'allows_overdraft' => $od ? 1 : 0, 'is_active' => 1, 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $order = (int) $this->db->scalar('SELECT COALESCE(MAX(sort_order), 0) FROM gst_business_activities');
        foreach (self::ACTIVITIES as $code => [$name, $desc]) {
            if (!$this->db->scalar('SELECT 1 FROM gst_business_activities WHERE code = ?', [$code])) {
                $order += 10;
                $this->db->insert('gst_business_activities', ['code' => $code, 'name' => $name, 'description' => $desc, 'is_active' => 1, 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $this->releaseRoleDefaults();
    }

    private function releaseRoleDefaults(): void
    {
        if ($this->db->scalar("SELECT 1 FROM system_settings WHERE setting_key = 'bank_role_defaults_released'")) {
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
        $this->db->insert('system_settings', ['setting_key' => 'bank_role_defaults_released', 'value' => '1', 'type' => 'boolean', 'group_name' => 'internal', 'created_at' => $now, 'updated_at' => $now]);
    }
}
