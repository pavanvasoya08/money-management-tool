<?php

declare(strict_types=1);

/*
 * Permission catalogue.
 * Each permission is "<module>.<action>". Phase 1 registers only the modules
 * that exist today; each future phase appends its own module here and re-runs
 * the PermissionSeeder (idempotent).
 *
 * Standard actions for future modules: view, create, edit, delete, approve, export.
 */
return [
    'modules' => [
        'dashboard' => [
            'label' => 'Dashboard',
            'actions' => [
                'view' => 'View the dashboard',
            ],
        ],
        'users' => [
            'label' => 'User Management',
            'actions' => [
                'view' => 'View users and their status',
                'create' => 'Create users',
                'edit' => 'Edit user details',
                'change_status' => 'Activate or deactivate users',
                'assign_roles' => 'Assign roles to users',
                'reset_password' => 'Reset other users\' passwords',
                'view_login_history' => 'View login history',
            ],
        ],
        'activity_logs' => [
            'label' => 'Activity Logs',
            'actions' => [
                'view' => 'View the system activity log',
            ],
        ],
        'settings' => [
            'label' => 'Settings',
            'actions' => [
                'view' => 'View system settings',
                'edit' => 'Change system settings',
            ],
        ],
    ],

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
            'grants' => ['dashboard.view'],
        ],
        'accountant' => [
            'name' => 'Accountant',
            'description' => 'Accounting staff. Finance modules are granted as they are released.',
            'is_super_admin' => false,
            'grants' => ['dashboard.view'],
        ],
        'manager' => [
            'name' => 'Manager',
            'description' => 'Operations manager with read access to users and activity.',
            'is_super_admin' => false,
            'grants' => ['dashboard.view', 'users.view', 'users.view_login_history', 'activity_logs.view'],
        ],
        'viewer' => [
            'name' => 'Viewer',
            'description' => 'Read-only access to the dashboard.',
            'is_super_admin' => false,
            'grants' => ['dashboard.view'],
        ],
    ],
];
