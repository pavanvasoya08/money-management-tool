<?php

declare(strict_types=1);

/*
 * ERP module registry — the single source for modules, their actions
 * (permissions), protectable fields and record-level data mapping.
 * `php bin/console db:seed` syncs it into the modules / permissions /
 * module_fields tables (idempotent; never removes administrator choices).
 *
 * actions : action key => label. Modules have different action sets.
 * fields  : field key => [label, sensitive, mask, default access]
 *           mask: full | last4 | email | phone | amount
 *           access: visible | hidden | masked | read_only | editable
 * data    : how record-level visibility maps onto the module's table
 *           table        – main table (null = no records, e.g. reports)
 *           owner_column – column used by the "Own records" scope
 *           resource     – resource type whose assignment = "Assigned records"
 *           columns      – resource type => column, used by "Selected partners/GST/bank/platform" scopes
 */

$std = [
    'view' => 'View', 'view_all' => 'View all records', 'view_own' => 'View own records', 'view_assigned' => 'View assigned records',
    'create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete', 'export' => 'Export', 'download' => 'Download',
    'print' => 'Print', 'import' => 'Import', 'manage_settings' => 'Manage settings',
];
$approvals = ['approve' => 'Approve', 'reject' => 'Reject'];
$report = [
    'view' => 'View', 'view_all' => 'View all records', 'view_own' => 'View own records', 'view_assigned' => 'View assigned records',
    'export' => 'Export', 'download' => 'Download', 'print' => 'Print',
];
$f = static fn (string $label, bool $sensitive = false, string $mask = 'full', string $default = 'editable'): array => [$label, $sensitive, $mask, $default];

return [
    'dashboard' => [
        'name' => 'Dashboard', 'icon' => 'layout-dashboard', 'phase' => 1,
        'actions' => ['view' => 'View'],
    ],
    'partners' => [
        'name' => 'Partners', 'icon' => 'users-round', 'phase' => 3,
        'actions' => $std,
        'fields' => [
            'name' => $f('Partner name'),
            'contact_email' => $f('Email', true, 'email'),
            'contact_phone' => $f('Phone', true, 'phone'),
            'pan_number' => $f('PAN', true, 'last4', 'masked'),
            'address' => $f('Address', true, 'full'),
            'investment_amount' => $f('Investment amount', true, 'amount', 'hidden'),
            'withdrawal_amount' => $f('Withdrawal amount', true, 'amount', 'hidden'),
            'profit_share_percent' => $f('Profit-sharing %', true, 'full', 'hidden'),
        ],
        'data' => ['table' => 'partners', 'owner_column' => 'user_id', 'resource' => 'partner', 'columns' => ['partner' => 'id']],
    ],
    'capital_contributions' => [
        'name' => 'Capital Contributions', 'icon' => 'piggy-bank', 'phase' => 3,
        'actions' => $std + $approvals,
        'fields' => [
            'amount' => $f('Amount', true, 'amount', 'hidden'),
            'bank_reference' => $f('Bank reference', true, 'last4', 'masked'),
        ],
        'data' => ['table' => 'capital_contributions', 'owner_column' => 'created_by', 'resource' => null, 'columns' => ['partner' => 'partner_id', 'bank_account' => 'bank_account_id']],
    ],
    'gst_registrations' => [
        'name' => 'GST Registrations', 'icon' => 'file-badge', 'phase' => 4,
        'actions' => $std,
        'fields' => [
            'gstin' => $f('GSTIN'),
            'legal_name' => $f('Legal name'),
            'registered_address' => $f('Registered address', true),
            'portal_username' => $f('GST portal username', true, 'full', 'hidden'),
        ],
        'data' => ['table' => 'gst_registrations', 'owner_column' => 'created_by', 'resource' => 'gst_registration', 'columns' => ['gst_registration' => 'id', 'partner' => 'partner_id']],
    ],
    'bank_accounts' => [
        'name' => 'Bank Accounts', 'icon' => 'landmark', 'phase' => 5,
        'actions' => $std + $approvals + ['transfer' => 'Initiate transfer'],
        'fields' => [
            'bank_name' => $f('Bank name'),
            'account_holder' => $f('Account holder', true),
            'account_number' => $f('Account number', true, 'last4', 'masked'),
            'ifsc' => $f('IFSC', true, 'last4', 'masked'),
            'current_balance' => $f('Current balance', true, 'amount', 'hidden'),
        ],
        'data' => ['table' => 'bank_accounts', 'owner_column' => 'created_by', 'resource' => 'bank_account', 'columns' => ['bank_account' => 'id', 'gst_registration' => 'gst_registration_id', 'partner' => 'partner_id']],
    ],
    'products' => [
        'name' => 'Products', 'icon' => 'package', 'phase' => 6,
        'actions' => $std,
        'fields' => [
            'purchase_cost' => $f('Purchase cost', true, 'amount', 'hidden'),
            'selling_price' => $f('Selling price'),
            'profit_margin' => $f('Profit margin', true, 'amount', 'hidden'),
            'supplier_details' => $f('Supplier details', true),
        ],
        'data' => ['table' => 'products', 'owner_column' => 'created_by', 'resource' => 'product', 'columns' => ['product' => 'id', 'supplier' => 'supplier_id']],
    ],
    'suppliers' => [
        'name' => 'Suppliers', 'icon' => 'truck', 'phase' => 6,
        'actions' => $std,
        'fields' => [
            'contact_email' => $f('Email', true, 'email'),
            'contact_phone' => $f('Phone', true, 'phone'),
            'gstin' => $f('GSTIN'),
            'bank_account_number' => $f('Bank account number', true, 'last4', 'masked'),
        ],
        'data' => ['table' => 'suppliers', 'owner_column' => 'created_by', 'resource' => 'supplier', 'columns' => ['supplier' => 'id']],
    ],
    'purchases' => [
        'name' => 'Purchases', 'icon' => 'shopping-cart', 'phase' => 11,
        'actions' => $std + $approvals,
        'fields' => [
            'amount' => $f('Purchase amount'),
            'unit_cost' => $f('Unit cost', true, 'amount'),
            'supplier_name' => $f('Supplier'),
        ],
        'data' => ['table' => 'purchases', 'owner_column' => 'created_by', 'resource' => null, 'columns' => ['gst_registration' => 'gst_registration_id', 'bank_account' => 'bank_account_id', 'supplier' => 'supplier_id']],
    ],
    'inventory' => [
        'name' => 'Inventory', 'icon' => 'boxes', 'phase' => 7,
        'actions' => $std + ['adjust_stock' => 'Adjust stock', 'transfer_stock' => 'Transfer between warehouses'],
        'fields' => [
            'stock_value' => $f('Stock value', true, 'amount', 'hidden'),
            'unit_cost' => $f('Unit cost', true, 'amount', 'hidden'),
        ],
        'data' => ['table' => 'inventory_items', 'owner_column' => 'created_by', 'resource' => 'warehouse', 'columns' => ['warehouse' => 'warehouse_id', 'product' => 'product_id']],
    ],
    'platforms' => [
        'name' => 'E-commerce Platforms', 'icon' => 'store', 'phase' => 8,
        'actions' => $std,
        'fields' => [
            'seller_id' => $f('Seller ID', true, 'last4', 'masked'),
            'api_credentials' => $f('API credentials', true, 'full', 'hidden'),
            'commission_rate' => $f('Commission rate'),
        ],
        'data' => ['table' => 'ecommerce_platforms', 'owner_column' => 'created_by', 'resource' => 'platform', 'columns' => ['platform' => 'id', 'gst_registration' => 'gst_registration_id']],
    ],
    'sales_orders' => [
        'name' => 'Sales & Orders', 'icon' => 'receipt', 'phase' => 9,
        'actions' => $std + ['cancel_order' => 'Cancel orders'],
        'fields' => [
            'order_amount' => $f('Order amount'),
            'customer_name' => $f('Customer name', true),
            'customer_phone' => $f('Customer phone', true, 'phone', 'masked'),
            'customer_address' => $f('Customer address', true, 'full', 'masked'),
            'profit' => $f('Profit', true, 'amount', 'hidden'),
            'marketplace_charges' => $f('Marketplace charges', true, 'amount', 'hidden'),
        ],
        'data' => ['table' => 'sales_orders', 'owner_column' => 'created_by', 'resource' => 'seller_account', 'columns' => ['platform' => 'platform_id', 'seller_account' => 'seller_account_id', 'gst_registration' => 'gst_registration_id', 'warehouse' => 'warehouse_id']],
    ],
    'returns_refunds' => [
        'name' => 'Returns & Refunds', 'icon' => 'undo-2', 'phase' => 10,
        'actions' => $std + $approvals,
        'fields' => [
            'refund_amount' => $f('Refund amount'),
            'customer_name' => $f('Customer name', true),
        ],
        'data' => ['table' => 'sales_returns', 'owner_column' => 'created_by', 'resource' => null, 'columns' => ['platform' => 'platform_id', 'gst_registration' => 'gst_registration_id']],
    ],
    'settlements' => [
        'name' => 'Settlements', 'icon' => 'handshake', 'phase' => 10,
        'actions' => $std + $approvals + ['reconcile' => 'Reconcile settlements'],
        'fields' => [
            'net_amount' => $f('Net settlement'),
            'charges' => $f('Marketplace charges', true, 'amount'),
        ],
        'data' => ['table' => 'settlements', 'owner_column' => 'created_by', 'resource' => null, 'columns' => ['platform' => 'platform_id', 'bank_account' => 'bank_account_id', 'gst_registration' => 'gst_registration_id']],
    ],
    'expenses' => [
        'name' => 'Expenses', 'icon' => 'wallet', 'phase' => 11,
        'actions' => $std + $approvals,
        'fields' => [
            'amount' => $f('Amount'),
            'receipt' => $f('Receipt attachment'),
            'paid_from_account' => $f('Paid from bank account', true, 'last4'),
        ],
        'data' => ['table' => 'expenses', 'owner_column' => 'created_by', 'resource' => null, 'columns' => ['gst_registration' => 'gst_registration_id', 'bank_account' => 'bank_account_id', 'partner' => 'partner_id']],
    ],
    'accounting' => [
        'name' => 'Accounting', 'icon' => 'book-open', 'phase' => 12,
        'actions' => $std + $approvals + ['close_period' => 'Close accounting period'],
        'fields' => [
            'journal_entries' => $f('Journal entries', true, 'full', 'hidden'),
            'ledger_balances' => $f('Ledger balances', true, 'amount', 'hidden'),
            'financial_statements' => $f('Financial statements', true, 'full', 'hidden'),
        ],
        'data' => ['table' => 'journal_entries', 'owner_column' => 'created_by', 'resource' => null, 'columns' => ['gst_registration' => 'gst_registration_id']],
    ],
    'gst_reports' => [
        'name' => 'GST Reports', 'icon' => 'percent', 'phase' => 13,
        'actions' => $report,
        'fields' => [
            'tax_liability' => $f('Tax liability', true, 'amount'),
        ],
    ],
    'financial_reports' => [
        'name' => 'Financial Reports', 'icon' => 'chart-line', 'phase' => 14,
        'actions' => $report,
        'fields' => [
            'profit_loss' => $f('Profit & loss', true, 'amount', 'hidden'),
            'balance_sheet' => $f('Balance sheet', true, 'amount', 'hidden'),
            'partner_capital' => $f('Partner capital balances', true, 'amount', 'hidden'),
        ],
    ],
    'users' => [
        'name' => 'User Management', 'icon' => 'user-cog', 'phase' => 1,
        'actions' => [
            'view' => 'View', 'view_all' => 'View all records', 'view_own' => 'View own records', 'view_assigned' => 'View assigned records',
            'create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete', 'restore' => 'Restore deleted users', 'export' => 'Export',
            'change_status' => 'Activate / deactivate', 'suspend' => 'Suspend', 'assign_roles' => 'Assign roles',
            'reset_password' => 'Reset passwords', 'force_logout' => 'Force logout', 'view_login_history' => 'View login history',
        ],
        'fields' => [
            'name' => $f('Name'),
            'email' => $f('Email', true, 'email'),
            'phone' => $f('Phone', true, 'phone'),
            'last_login_ip' => $f('Last sign-in IP', true, 'full', 'visible'),
        ],
        'data' => ['table' => 'users', 'owner_column' => 'id', 'resource' => 'user', 'columns' => []],
    ],
    'activity_logs' => [
        'name' => 'Activity Logs', 'icon' => 'scroll-text', 'phase' => 1,
        'actions' => ['view' => 'View', 'export' => 'Export'],
    ],
    'settings' => [
        'name' => 'Settings', 'icon' => 'settings', 'phase' => 1,
        'actions' => ['view' => 'View', 'edit' => 'Edit'],
    ],
    'super_admin' => [
        'name' => 'Super Admin Control Center', 'icon' => 'shield-check', 'phase' => 2, 'super_admin_only' => true,
        'actions' => [
            'view' => 'Open control center', 'manage_roles' => 'Manage roles', 'manage_permissions' => 'Manage permissions',
            'manage_data_visibility' => 'Manage data visibility', 'manage_field_access' => 'Manage field access',
            'manage_resources' => 'Manage resource assignments', 'manage_workflows' => 'Manage approval workflows',
            'manage_templates' => 'Manage permission templates', 'preview_access' => 'Preview user access',
            'manage_sessions' => 'Manage sessions', 'view_audit' => 'View audit logs', 'manage_security' => 'Manage security settings',
        ],
    ],
];
