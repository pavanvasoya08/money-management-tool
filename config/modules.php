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
 *           owner_via    – "Own records" through a parent: column IN (SELECT id FROM table WHERE owner_column = me)
 *           resource_column – column matched by "Assigned records" (default id)
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
// Partner capital register (one table; module = transaction type). Own = the linked partner's records.
$capital = [
    'view' => 'View', 'view_all' => 'View all records', 'view_own' => 'View own records', 'view_assigned' => 'View assigned records',
    'create' => 'Record & submit', 'edit' => 'Edit drafts', 'cancel' => 'Cancel drafts',
    'approve' => 'Approve', 'reject' => 'Reject', 'post' => 'Post to capital ledger', 'reverse' => 'Reverse posted entries',
    'export' => 'Export', 'print' => 'Print',
];
$capitalData = [
    'table' => 'partner_capital_transactions', 'owner_column' => null,
    'owner_via' => ['column' => 'partner_id', 'table' => 'partners', 'owner_column' => 'user_id'],
    'resource' => 'partner', 'resource_column' => 'partner_id',
    'columns' => ['partner' => 'partner_id', 'bank_account' => 'bank_account_id'],
];
$f = static fn (string $label, bool $sensitive = false, string $mask = 'full', string $default = 'editable'): array => [$label, $sensitive, $mask, $default];
$capitalFields = [
    'amount' => $f('Amount', true, 'amount', 'visible'),
    'payment_reference' => $f('Payment reference', true, 'last4', 'visible'),
    'payment_source_ref' => $f('Payment source / bank reference', true, 'last4', 'visible'),
    'notes' => $f('Notes', false, 'full', 'visible'),
];

return [
    'dashboard' => [
        'name' => 'Dashboard', 'icon' => 'layout-dashboard', 'phase' => 1,
        'actions' => ['view' => 'View'],
    ],
    'partners' => [
        'name' => 'Partners', 'icon' => 'users-round', 'phase' => 3,
        'actions' => $std + [
            'change_status' => 'Activate / deactivate', 'suspend' => 'Suspend partner access', 'archive' => 'Archive / restore',
            'link_account' => 'Link & manage login accounts', 'view_financial_summary' => 'View financial summary',
            'view_documents' => 'View & download documents', 'upload_documents' => 'Upload documents', 'delete_documents' => 'Remove documents',
        ],
        'fields' => [
            'name' => $f('Partner name'),
            'email' => $f('Email', true, 'email'),
            'mobile' => $f('Mobile number', true, 'phone'),
            'address' => $f('Street address', true, 'full'),
            'pan_number' => $f('PAN', true, 'last4', 'masked'),
            'notes' => $f('Internal notes', true, 'full', 'visible'),
            'total_contributed' => $f('Total capital contributed', true, 'amount', 'visible'),
            'total_withdrawn' => $f('Total capital withdrawn', true, 'amount', 'visible'),
            'capital_balance' => $f('Capital balance', true, 'amount', 'visible'),
            'identity_documents' => $f('PAN & identity documents', true, 'full', 'visible'),
        ],
        'data' => ['table' => 'partners', 'owner_column' => 'user_id', 'resource' => 'partner', 'columns' => ['partner' => 'id']],
    ],
    'capital_contributions' => [
        'name' => 'Capital Contributions', 'icon' => 'piggy-bank', 'phase' => 3,
        'actions' => $capital,
        'fields' => $capitalFields,
        'data' => $capitalData,
    ],
    'capital_withdrawals' => [
        'name' => 'Capital Withdrawals', 'icon' => 'hand-coins', 'phase' => 3,
        'actions' => $capital + ['override_limit' => 'Exceed available capital (exception policy)'],
        'fields' => $capitalFields,
        'data' => $capitalData,
    ],
    'capital_adjustments' => [
        'name' => 'Capital Adjustments', 'icon' => 'scale', 'phase' => 3,
        'actions' => $capital,
        'fields' => $capitalFields,
        'data' => $capitalData,
    ],
    'capital_ledger' => [
        'name' => 'Capital Ledger & Balances', 'icon' => 'book-text', 'phase' => 3,
        'actions' => $report,
        'fields' => [
            'amount' => $f('Debit / credit amounts', true, 'amount', 'visible'),
            'running_balance' => $f('Running & closing balance', true, 'amount', 'visible'),
        ],
        'data' => $capitalData,
    ],
    'profit_sharing' => [
        'name' => 'Profit Sharing', 'icon' => 'pie-chart', 'phase' => 3,
        'actions' => ['view' => 'View', 'create' => 'Create arrangements & allocations', 'edit' => 'Edit future allocations',
            'change_status' => 'Activate / deactivate', 'export' => 'Export', 'print' => 'Print'],
        'fields' => [
            'percentage' => $f('Profit-sharing %', true, 'full', 'visible'),
            'notes' => $f('Agreement notes', true, 'full', 'visible'),
        ],
    ],
    'partner_reports' => [
        'name' => 'Partner Reports', 'icon' => 'file-bar-chart', 'phase' => 3,
        'actions' => ['view' => 'View', 'export' => 'Export (CSV / Excel / PDF)', 'print' => 'Print'],
    ],
    // Phase 4. Many-to-many with partners (gst_registration_partner_mappings); never stores portal passwords/OTPs.
    'gst_registrations' => [
        'name' => 'GST Registrations', 'icon' => 'file-badge', 'phase' => 4,
        'prune_actions' => true, // drop the generic placeholder actions reserved in Phase 2 (delete, download, import)
        'actions' => [
            'view' => 'View', 'view_all' => 'View all records', 'view_own' => 'View own records (mapped partner / created by me)', 'view_assigned' => 'View assigned records',
            'create' => 'Add registrations', 'edit' => 'Edit registration details',
            'activate' => 'Activate', 'deactivate' => 'Deactivate', 'suspend' => 'Suspend', 'cancel' => 'Mark as cancelled', 'archive' => 'Archive / restore',
            'approve' => 'Approve requests', 'reject' => 'Reject requests',
            'manage_partners' => 'Manage partner mappings', 'manage_business_mappings' => 'Manage business-activity mappings',
            'manage_locations' => 'Manage business locations', 'manage_documents' => 'Upload & archive documents', 'download_documents' => 'View & download documents',
            'manage_settings' => 'Manage registration settings', 'view_history' => 'View history & audit', 'manage_masters' => 'Manage GST masters & rules',
            'export' => 'Export (CSV / Excel / PDF)', 'print' => 'Print',
        ],
        'fields' => [
            'gstin' => $f('GSTIN'),
            'legal_name' => $f('Legal business name'),
            'trade_name' => $f('Trade name'),
            'pan' => $f('PAN', true, 'last4', 'visible'),
            'contact_person' => $f('Primary contact person', true, 'full', 'visible'),
            'contact_phone' => $f('Contact number', true, 'phone', 'visible'),
            'contact_email' => $f('Email address', true, 'email', 'visible'),
            'address' => $f('Business addresses', true, 'full', 'visible'),
            'portal_reference' => $f('GST portal reference (ARN, application ref.)', true, 'full', 'visible'),
            'internal_notes' => $f('Internal notes', true, 'full', 'visible'),
        ],
        'data' => [
            'table' => 'gst_registrations', 'owner_column' => null, 'resource' => 'gst_registration',
            // Own = registrations actively mapped to the partner linked to my login, or created by me.
            'owner_sql' => "SELECT m.gst_registration_id FROM gst_registration_partner_mappings m JOIN partners p ON p.id = m.partner_id WHERE p.user_id = ? AND m.status = 'active'"
                . ' UNION SELECT g.id FROM gst_registrations g WHERE g.created_by = ?',
            'columns' => ['gst_registration' => 'id'],
            'columns_via' => ['partner' => "SELECT gst_registration_id FROM gst_registration_partner_mappings WHERE status = 'active' AND partner_id IN (%s)"],
        ],
    ],
    // Phase 5. Bank accounts, ledger, transfers, statements and reconciliation. Never stores banking passwords, PINs or OTPs.
    'bank_accounts' => [
        'name' => 'Bank Accounts', 'icon' => 'landmark', 'phase' => 5,
        'prune_actions' => true, // drop the generic placeholder actions reserved in Phase 2 (delete, download, import, transfer)
        'actions' => [
            'view_dashboard' => 'View bank dashboard', 'view' => 'View bank accounts', 'view_all' => 'View all records',
            'view_own' => 'View own records (mapped partner / created by me)', 'view_assigned' => 'View assigned records',
            'create' => 'Add bank accounts', 'edit' => 'Edit account details',
            'activate' => 'Activate', 'deactivate' => 'Deactivate', 'freeze' => 'Freeze / unfreeze', 'close' => 'Close accounts', 'archive' => 'Archive / restore',
            'approve' => 'Approve account requests', 'reject' => 'Reject account requests',
            'manage_central_account' => 'Designate the central funding account',
            'manage_account_types' => 'Manage account types', 'manage_settings' => 'Manage bank settings',
            'manage_partners' => 'Manage partner mappings', 'manage_gst_mappings' => 'Manage GST mappings', 'manage_business_mappings' => 'Manage business-activity mappings',
            'view_full_account_number' => 'View full account numbers',
            'manage_transactions' => 'Record transactions (all types)', 'create_deposit' => 'Record deposits', 'create_withdrawal' => 'Record withdrawals',
            'approve_transactions' => 'Approve transactions', 'reject_transactions' => 'Reject transactions', 'reverse_transactions' => 'Reverse posted transactions',
            'manage_transfers' => 'Create internal transfers', 'approve_transfers' => 'Approve transfers', 'reject_transfers' => 'Reject transfers',
            'manage_statements' => 'Upload & archive statements', 'import_statements' => 'Import statement CSV files',
            'manage_reconciliation' => 'Reconcile', 'reopen_reconciliation' => 'Reopen completed reconciliations',
            'manage_documents' => 'Upload & archive documents', 'download_documents' => 'View & download documents and statement files',
            'view_history' => 'View history & audit', 'export' => 'Export (CSV / Excel / PDF)', 'print' => 'Print',
        ],
        'fields' => [
            // The full number additionally needs bank_accounts.view_full_account_number.
            'account_number' => $f('Account number', true, 'last4', 'visible'),
            'ifsc_code' => $f('IFSC code', true, 'full', 'visible'),
            'micr_code' => $f('MICR code', true, 'full', 'visible'),
            'account_holder_name' => $f('Account holder name', true, 'full', 'visible'),
            'branch_name' => $f('Branch name'),
            'opening_balance' => $f('Opening balance', true, 'amount', 'visible'),
            'current_balance' => $f('Balances', true, 'amount', 'visible'),
            'counterparty_name' => $f('Counterparty name', true, 'full', 'visible'),
            'internal_notes' => $f('Internal notes', true, 'full', 'visible'),
        ],
        'data' => [
            'table' => 'bank_accounts', 'owner_column' => null, 'resource' => 'bank_account',
            // Own = accounts actively mapped to the partner linked to my login, or created by me.
            'owner_sql' => "SELECT m.bank_account_id FROM bank_account_partner_mappings m JOIN partners p ON p.id = m.partner_id WHERE p.user_id = ? AND m.status = 'active'"
                . ' UNION SELECT a.id FROM bank_accounts a WHERE a.created_by = ?',
            'columns' => ['bank_account' => 'id'],
            'columns_via' => [
                'partner' => "SELECT bank_account_id FROM bank_account_partner_mappings WHERE status = 'active' AND partner_id IN (%s)",
                'gst_registration' => "SELECT bank_account_id FROM bank_account_gst_mappings WHERE status = 'active' AND gst_registration_id IN (%s)",
            ],
        ],
    ],
    // Phase 6. Products, variants, SKUs, pricing, GST/HSN configuration, categories, brands and attributes.
    'products' => [
        'name' => 'Products', 'icon' => 'package', 'phase' => 6,
        'prune_actions' => true, // drop the generic placeholder actions reserved in Phase 2 (delete, download, import)
        'actions' => [
            'view_dashboard' => 'View product dashboard', 'view' => 'View products', 'view_all' => 'View all records',
            'view_own' => 'View own records (created by me)', 'view_assigned' => 'View assigned records',
            'create' => 'Add products', 'edit' => 'Edit product details',
            'activate' => 'Activate', 'deactivate' => 'Deactivate / discontinue', 'archive' => 'Archive', 'restore' => 'Restore archived products',
            'approve' => 'Approve product requests', 'reject' => 'Reject product requests',
            'manage_categories' => 'Manage categories', 'manage_brands' => 'Manage brands', 'manage_attributes' => 'Manage attributes',
            'manage_variants' => 'Manage variants', 'manage_sku' => 'Change SKUs', 'manage_pricing' => 'Manage prices',
            'manage_hsn' => 'Set HSN / SAC codes', 'manage_gst' => 'Set GST rates & tax categories',
            'manage_supplier_mappings' => 'Manage product-supplier mappings', 'manage_gst_mappings' => 'Manage GST-registration mappings',
            'manage_business_mappings' => 'Manage business-activity mappings',
            'manage_documents' => 'Upload & archive images and documents', 'download_documents' => 'View & download documents',
            'manage_settings' => 'Manage product settings & tax masters', 'view_history' => 'View history & audit',
            'export' => 'Export (CSV / Excel / PDF)', 'print' => 'Print',
        ],
        'fields' => [
            // Kept from Phase 2 so existing field rules carry over: covers standard and supplier-specific purchase prices.
            'purchase_cost' => $f('Purchase prices (standard & supplier-specific)', true, 'amount'),
            'selling_price' => $f('Selling prices (all selling price types)', true, 'amount'),
            'supplier_details' => $f('Supplier mapping details (codes, order quantities, lead time)', true),
            'tax_configuration' => $f('GST / HSN configuration'),
            'internal_notes' => $f('Internal notes', true),
        ],
        'data' => [
            'table' => 'products', 'owner_column' => 'created_by', 'resource' => 'product',
            'columns' => ['product' => 'id'],
            'columns_via' => ['gst_registration' => "SELECT product_id FROM product_gst_mappings WHERE status = 'active' AND gst_registration_id IN (%s)"],
        ],
    ],
    // Phase 6. Suppliers are separate from partners and from the company's own bank accounts.
    'suppliers' => [
        'name' => 'Suppliers', 'icon' => 'truck', 'phase' => 6,
        'prune_actions' => true,
        'actions' => [
            'view_dashboard' => 'View supplier dashboard', 'view' => 'View suppliers', 'view_all' => 'View all records',
            'view_own' => 'View own records (created by me)', 'view_assigned' => 'View assigned records',
            'create' => 'Add suppliers', 'edit' => 'Edit supplier details',
            'activate' => 'Activate', 'deactivate' => 'Deactivate / block', 'archive' => 'Archive', 'restore' => 'Restore archived suppliers',
            'approve' => 'Approve supplier requests', 'reject' => 'Reject supplier requests',
            'manage_supplier_types' => 'Manage supplier types', 'manage_gst_details' => 'Change GSTIN & PAN', 'manage_addresses' => 'Manage addresses',
            'manage_bank_details' => 'Manage bank details', 'view_bank_details' => 'View bank details & download bank proofs',
            'view_full_account_number' => 'View full supplier account numbers',
            'manage_documents' => 'Upload & archive documents', 'download_documents' => 'View & download documents',
            'manage_product_mappings' => 'Manage supplier-product mappings', 'manage_business_mappings' => 'Manage GST & business-activity mappings',
            'link_bank_transactions' => 'Link existing bank transactions as supplier payment references',
            'view_history' => 'View history & audit', 'export' => 'Export (CSV / Excel / PDF)', 'print' => 'Print',
        ],
        'fields' => [
            'contact_email' => $f('Email', true, 'email'),
            'contact_phone' => $f('Mobile number', true, 'phone'),
            'contact_person' => $f('Contact person', true),
            'gstin' => $f('GSTIN'),
            'pan' => $f('PAN', true, 'last4'),
            'addresses' => $f('Addresses', true),
            'payment_terms' => $f('Payment terms & credit period', true),
            // The full number additionally needs suppliers.view_full_account_number.
            'bank_account_number' => $f('Bank account number', true, 'last4', 'visible'),
            'internal_notes' => $f('Internal notes', true),
        ],
        'data' => [
            'table' => 'suppliers', 'owner_column' => 'created_by', 'resource' => 'supplier',
            'columns' => ['supplier' => 'id'],
            'columns_via' => ['gst_registration' => "SELECT supplier_id FROM supplier_gst_mappings WHERE status = 'active' AND gst_registration_id IN (%s)"],
        ],
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
    // Phase 7. Records are warehouses: record scope (all / own / assigned warehouses / GST registration)
    // limits every stock figure, document and report to the warehouses a user may see.
    'inventory' => [
        'name' => 'Inventory', 'icon' => 'boxes', 'phase' => 7,
        'prune_actions' => true, // drop the Phase 2 placeholder actions (create, edit, adjust_stock, transfer_stock, …)
        'actions' => [
            'view_dashboard' => 'View inventory dashboard', 'view' => 'View stock & warehouses', 'view_all' => 'View all warehouses',
            'view_own' => 'View own warehouses (created by me)', 'view_assigned' => 'View assigned warehouses',
            'manage_warehouses' => 'Activate / deactivate warehouses & manage mappings', 'create_warehouse' => 'Add warehouses', 'edit_warehouse' => 'Edit warehouses',
            'archive_warehouse' => 'Archive / restore warehouses', 'manage_locations' => 'Manage storage locations',
            'manage_inventory_settings' => 'Manage product inventory settings & inventory settings', 'manage_units' => 'Manage units & conversions',
            'manage_opening_stock' => 'Record opening stock', 'approve_opening_stock' => 'Approve opening stock',
            'manage_stock_inward' => 'Record stock inward', 'manage_stock_outward' => 'Record stock outward',
            'manage_stock_transfers' => 'Create stock transfers', 'approve_stock_transfers' => 'Approve stock transfers', 'receive_stock_transfers' => 'Dispatch & receive transfers',
            'manage_stock_adjustments' => 'Create stock adjustments', 'approve_stock_adjustments' => 'Approve stock adjustments',
            'approve' => 'Approve other inventory requests (inward, outward, corrections, overrides)',
            'override_stock_rules' => 'Request negative-stock, reserved-stock and expired-stock overrides',
            'manage_batches' => 'Manage batches', 'manage_serial_numbers' => 'Manage serial numbers',
            'manage_reservations' => 'Create reservations', 'release_reservations' => 'Release & cancel reservations',
            'manage_stock_valuation' => 'Valuation methods & revaluation', 'view_stock_cost' => 'View stock cost & valuation',
            'view_stock_ledger' => 'View stock ledger', 'reverse_stock_movements' => 'Reverse posted stock documents',
            'manage_reorder_settings' => 'Manage reorder levels', 'manage_documents' => 'Upload & archive documents', 'download_documents' => 'View & download documents',
            'export' => 'Export (CSV / Excel / PDF)', 'print' => 'Print', 'view_history' => 'View history & audit',
        ],
        'fields' => [
            // Kept from Phase 2 so existing field rules carry over. Seeing them also needs inventory.view_stock_cost.
            'stock_value' => $f('Stock value & valuation', true, 'amount'),
            'unit_cost' => $f('Unit cost', true, 'amount'),
            'warehouse_contact' => $f('Warehouse contact person, mobile & email', true),
            'internal_notes' => $f('Internal notes', true),
        ],
        'data' => [
            'table' => 'warehouses', 'owner_column' => 'created_by', 'resource' => 'warehouse',
            'columns' => ['warehouse' => 'id'],
            'columns_via' => ['gst_registration' => "SELECT warehouse_id FROM warehouse_gst_mappings WHERE status = 'active' AND gst_registration_id IN (%s)"],
        ],
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
    // Help & User Guide. Reading published guides needs NO permission (every signed-in user);
    // these actions only cover managing the documentation and can be granted like any other.
    'documentation' => [
        'name' => 'Help & User Guide', 'icon' => 'life-buoy', 'phase' => 3,
        'actions' => [
            'manage' => 'Create & edit guides (drafts, steps, screenshots, FAQs)',
            'publish' => 'Publish & unpublish guides',
            'delete' => 'Delete guides, categories & screenshots',
        ],
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
