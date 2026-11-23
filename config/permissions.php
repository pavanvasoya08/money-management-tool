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
            'description' => 'Business partner. Module access is granted per partner in Phase 2.',
            'is_super_admin' => false,
            'grants' => [
                'dashboard.view',
                'partners.view', 'partners.view_own',
                'capital_contributions.view', 'capital_contributions.view_own',
                'gst_registrations.view', 'gst_registrations.view_assigned',
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
