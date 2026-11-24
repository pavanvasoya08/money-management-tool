<?php

declare(strict_types=1);

/*
 * Initial roles and their DEFAULT grants.
 * The module/action catalogue itself lives in config/modules.php.
 *
 * Defaults are applied only when a role is first created, or when a
 * permission is introduced for the first time (e.g. by an upgrade). Grants
 * an administrator removed later are never re-added by re-seeding.
 */
return [
    /*
     * Initial roles. Super Admin bypasses all checks (no grants needed).
     * Grants are a minimal starting point — no role gets every module by default.
     * Phase 2 makes roles and grants fully editable and allows unlimited custom roles.
     */
    'roles' => [
        'super-admin' => [
            'name' => 'Super Admin',
            'description' => 'Unrestricted access to the entire system. Can only be granted by another Super Admin.',
            'is_super_admin' => true,
            'grants' => [],
        ],
        'partner' => [
            'name' => 'Partner',
            'description' => 'Business partner. Sees only their own linked partner profile, capital and documents.',
            'is_super_admin' => false,
            'grants' => [
                'dashboard.view',
                // Phase 3: a partner sees only the partner profile linked to their login, and its capital.
                'partners.view', 'partners.view_own', 'partners.view_financial_summary', 'partners.view_documents',
                'capital_contributions.view', 'capital_contributions.view_own',
                'capital_withdrawals.view', 'capital_withdrawals.view_own',
                'capital_ledger.view', 'capital_ledger.view_own',
                // Phase 4: GST registrations mapped to the partner's own record (read-only).
                'gst_registrations.view', 'gst_registrations.view_own', 'gst_registrations.download_documents',
                'bank_accounts.view', 'bank_accounts.view_assigned',
                'financial_reports.view', 'financial_reports.view_assigned',
            ],
        ],
        'accountant' => [
            'name' => 'Accountant',
            'description' => 'Accounting staff. Sees assigned expenses and authorised accounting records.',
            'is_super_admin' => false,
            'grants' => [
                'dashboard.view',
                'expenses.view', 'expenses.view_assigned', 'expenses.create', 'expenses.edit', 'expenses.export',
                'purchases.view', 'purchases.view_assigned', 'purchases.create',
                'accounting.view', 'accounting.view_assigned', 'accounting.create', 'accounting.export',
                'gst_reports.view', 'gst_reports.view_assigned', 'gst_reports.export',
                // Phase 3: assigned partners' capital (read + record drafts); cannot change partner profiles.
                'partners.view', 'partners.view_assigned', 'partners.view_financial_summary',
                'capital_contributions.view', 'capital_contributions.view_assigned', 'capital_contributions.create', 'capital_contributions.edit',
                'capital_withdrawals.view', 'capital_withdrawals.view_assigned', 'capital_withdrawals.create', 'capital_withdrawals.edit',
                'capital_ledger.view', 'capital_ledger.view_assigned', 'capital_ledger.export',
                'partner_reports.view', 'partner_reports.export',
                // Phase 4: assigned GST registrations, read-only, with documents and export.
                'gst_registrations.view', 'gst_registrations.view_assigned', 'gst_registrations.download_documents', 'gst_registrations.view_history', 'gst_registrations.export', 'gst_registrations.print',
            ],
        ],
        'manager' => [
            'name' => 'Manager',
            'description' => 'Operations manager with read access to users and activity.',
            'is_super_admin' => false,
            'grants' => [
                'dashboard.view', 'users.view', 'users.view_all', 'users.view_login_history', 'activity_logs.view',
                'inventory.view', 'inventory.view_assigned', 'inventory.create', 'inventory.edit',
                'sales_orders.view', 'sales_orders.view_assigned', 'sales_orders.create', 'sales_orders.edit',
                // Phase 3: partner directory only — no capital figures, no profit sharing.
                'partners.view', 'partners.view_all',
                // Phase 4: GST register, read-only.
                'gst_registrations.view', 'gst_registrations.view_all',
            ],
        ],
        'viewer' => [
            'name' => 'Viewer',
            'description' => 'Read-only access to the dashboard.',
            'is_super_admin' => false,
            'grants' => ['dashboard.view'],
        ],
    ],
];
