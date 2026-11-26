<?php

declare(strict_types=1);

/*
 * Sidebar navigation. Items with 'route' => null are future modules and are
 * rendered as disabled "Coming soon" entries (no fake pages behind them).
 * When a phase ships, set its route and permission here.
 *   'group'       — consecutive items with the same group render as one collapsible
 *                   menu: ['key' => …, 'label' => …, 'icon' => …]. Purely visual.
 *   'module'      — module key (Preview User Access shows whether the user will see it)
 *   'super_admin' — only real Super Admins see the item
 *   'approver'    — shown to users who can approve or reject in any module
 *   'active'      — route-name prefix (or list of prefixes) that highlights the item
 */
return [
    ['section' => 'Overview'],
    ['label' => 'Dashboard', 'icon' => 'layout-dashboard', 'route' => 'dashboard', 'permission' => null, 'active' => 'dashboard'],

    ['section' => 'Partners & Capital'],
    ['label' => 'Partners', 'module' => 'partners', 'icon' => 'users-round', 'route' => 'partners.index', 'permission' => 'partners.view', 'active' => 'partners'],
    ['label' => 'Contributions', 'title' => 'Capital Contributions', 'group' => ['key' => 'capital', 'label' => 'Capital', 'icon' => 'wallet'], 'module' => 'capital_contributions', 'icon' => 'piggy-bank', 'route' => 'capital.contributions.index', 'permission' => 'capital_contributions.view', 'active' => 'capital.contributions'],
    ['label' => 'Withdrawals', 'title' => 'Capital Withdrawals', 'group' => ['key' => 'capital', 'label' => 'Capital', 'icon' => 'wallet'], 'module' => 'capital_withdrawals', 'icon' => 'hand-coins', 'route' => 'capital.withdrawals.index', 'permission' => 'capital_withdrawals.view', 'active' => 'capital.withdrawals'],
    ['label' => 'Adjustments', 'title' => 'Capital Adjustments', 'group' => ['key' => 'capital', 'label' => 'Capital', 'icon' => 'wallet'], 'module' => 'capital_adjustments', 'icon' => 'scale', 'route' => 'capital.adjustments.index', 'permission' => 'capital_adjustments.view', 'active' => 'capital.adjustments'],
    ['label' => 'Ledger & Balances', 'title' => 'Capital Ledger', 'group' => ['key' => 'capital', 'label' => 'Capital', 'icon' => 'wallet'], 'module' => 'capital_ledger', 'icon' => 'book-text', 'route' => 'capital.ledger.index', 'permission' => 'capital_ledger.view', 'active' => 'capital.ledger'],
    ['label' => 'Profit Sharing', 'module' => 'profit_sharing', 'icon' => 'pie-chart', 'route' => 'profit-sharing.index', 'permission' => 'profit_sharing.view', 'active' => 'profit-sharing'],
    ['label' => 'Partner Reports', 'module' => 'partner_reports', 'icon' => 'file-bar-chart', 'route' => 'partner-reports.index', 'permission' => 'partner_reports.view', 'active' => 'partner-reports'],

    ['section' => 'Compliance & Banking'],
    ['label' => 'GST Dashboard', 'title' => 'GST Management dashboard', 'group' => ['key' => 'gst', 'label' => 'GST Management', 'icon' => 'file-badge'], 'module' => 'gst_registrations', 'icon' => 'layout-dashboard', 'route' => 'gst.dashboard', 'permission' => 'gst_registrations.view', 'active' => 'gst.dashboard'],
    ['label' => 'Registrations', 'title' => 'GST registrations', 'group' => ['key' => 'gst', 'label' => 'GST Management', 'icon' => 'file-badge'], 'module' => 'gst_registrations', 'icon' => 'file-badge', 'route' => 'gst.index', 'permission' => 'gst_registrations.view', 'active' => ['gst.index', 'gst.show', 'gst.create', 'gst.edit']],
    ['label' => 'Registration reports', 'title' => 'GST registration reports', 'group' => ['key' => 'gst', 'label' => 'GST Management', 'icon' => 'file-badge'], 'module' => 'gst_registrations', 'icon' => 'file-bar-chart', 'route' => 'gst.reports', 'permission' => 'gst_registrations.view', 'active' => 'gst.reports'],
    ['label' => 'Masters & rules', 'title' => 'GST masters & rules', 'group' => ['key' => 'gst', 'label' => 'GST Management', 'icon' => 'file-badge'], 'module' => 'gst_registrations', 'icon' => 'sliders-horizontal', 'route' => 'gst.masters', 'permission' => 'gst_registrations.manage_masters', 'active' => 'gst.masters'],

    ['label' => 'Bank Dashboard', 'title' => 'Bank Management dashboard', 'group' => ['key' => 'bank', 'label' => 'Bank Management', 'icon' => 'landmark'], 'module' => 'bank_accounts', 'icon' => 'layout-dashboard', 'route' => 'bank.dashboard', 'permission' => 'bank_accounts.view_dashboard', 'active' => 'bank.dashboard'],
    ['label' => 'Bank Accounts', 'title' => 'All bank accounts', 'group' => ['key' => 'bank', 'label' => 'Bank Management', 'icon' => 'landmark'], 'module' => 'bank_accounts', 'icon' => 'landmark', 'route' => 'bank.index', 'permission' => 'bank_accounts.view', 'active' => ['bank.index', 'bank.show', 'bank.create', 'bank.edit']],
    ['label' => 'Transactions', 'title' => 'Bank transactions', 'group' => ['key' => 'bank', 'label' => 'Bank Management', 'icon' => 'landmark'], 'module' => 'bank_accounts', 'icon' => 'arrow-left-right', 'route' => 'bank.transactions.index', 'permission' => 'bank_accounts.view', 'active' => 'bank.transactions.'],
    ['label' => 'Internal Transfers', 'title' => 'Internal bank transfers', 'group' => ['key' => 'bank', 'label' => 'Bank Management', 'icon' => 'landmark'], 'module' => 'bank_accounts', 'icon' => 'repeat', 'route' => 'bank.transfers.index', 'permission' => 'bank_accounts.view', 'active' => 'bank.transfers.'],
    ['label' => 'Statements', 'title' => 'Bank statements', 'group' => ['key' => 'bank', 'label' => 'Bank Management', 'icon' => 'landmark'], 'module' => 'bank_accounts', 'icon' => 'file-spreadsheet', 'route' => 'bank.statements.index', 'permission' => 'bank_accounts.view', 'active' => 'bank.statements.'],
    ['label' => 'Reconciliation', 'title' => 'Bank reconciliation', 'group' => ['key' => 'bank', 'label' => 'Bank Management', 'icon' => 'landmark'], 'module' => 'bank_accounts', 'icon' => 'check-check', 'route' => 'bank.reconciliation.index', 'permission' => 'bank_accounts.view', 'active' => 'bank.reconciliation.'],
    ['label' => 'Bank Reports', 'title' => 'Bank reports', 'group' => ['key' => 'bank', 'label' => 'Bank Management', 'icon' => 'landmark'], 'module' => 'bank_accounts', 'icon' => 'file-bar-chart', 'route' => 'bank.reports', 'permission' => 'bank_accounts.view', 'active' => 'bank.reports'],
    ['label' => 'Bank Settings', 'title' => 'Bank settings', 'group' => ['key' => 'bank', 'label' => 'Bank Management', 'icon' => 'landmark'], 'module' => 'bank_accounts', 'icon' => 'settings-2', 'route' => 'bank.settings', 'permission' => 'bank_accounts.manage_account_types', 'active' => 'bank.settings'],

    ['section' => 'Products & Suppliers'],
    ['label' => 'Product Dashboard', 'title' => 'Product dashboard', 'group' => ['key' => 'products', 'label' => 'Product Management', 'icon' => 'package'], 'module' => 'products', 'icon' => 'layout-dashboard', 'route' => 'products.dashboard', 'permission' => 'products.view_dashboard', 'active' => 'products.dashboard'],
    ['label' => 'All Products', 'title' => 'All products', 'group' => ['key' => 'products', 'label' => 'Product Management', 'icon' => 'package'], 'module' => 'products', 'icon' => 'package', 'route' => 'products.index', 'permission' => 'products.view', 'active' => ['products.index', 'products.show', 'products.create', 'products.edit']],
    ['label' => 'Categories', 'title' => 'Product categories', 'group' => ['key' => 'products', 'label' => 'Product Management', 'icon' => 'package'], 'module' => 'products', 'icon' => 'folder-tree', 'route' => 'products.categories', 'permission' => 'products.view', 'active' => 'products.categories'],
    ['label' => 'Brands', 'title' => 'Product brands', 'group' => ['key' => 'products', 'label' => 'Product Management', 'icon' => 'package'], 'module' => 'products', 'icon' => 'badge-check', 'route' => 'products.brands', 'permission' => 'products.view', 'active' => 'products.brands'],
    ['label' => 'Attributes', 'title' => 'Variant attributes', 'group' => ['key' => 'products', 'label' => 'Product Management', 'icon' => 'package'], 'module' => 'products', 'icon' => 'list-tree', 'route' => 'products.attributes', 'permission' => 'products.view', 'active' => 'products.attributes'],
    ['label' => 'Variants', 'title' => 'Product variants', 'group' => ['key' => 'products', 'label' => 'Product Management', 'icon' => 'package'], 'module' => 'products', 'icon' => 'layers', 'route' => 'products.variants', 'permission' => 'products.view', 'active' => 'products.variants'],
    ['label' => 'Pricing', 'title' => 'Product pricing', 'group' => ['key' => 'products', 'label' => 'Product Management', 'icon' => 'package'], 'module' => 'products', 'icon' => 'indian-rupee', 'route' => 'products.pricing', 'permission' => 'products.view', 'active' => 'products.pricing'],
    ['label' => 'GST / HSN', 'title' => 'GST / HSN configuration', 'group' => ['key' => 'products', 'label' => 'Product Management', 'icon' => 'package'], 'module' => 'products', 'icon' => 'percent', 'route' => 'products.tax', 'permission' => 'products.view', 'active' => 'products.tax'],
    ['label' => 'Supplier Mapping', 'title' => 'Product-supplier mapping', 'group' => ['key' => 'products', 'label' => 'Product Management', 'icon' => 'package'], 'module' => 'products', 'icon' => 'link', 'route' => 'products.mappings', 'permission' => 'products.view', 'active' => 'products.mappings'],
    ['label' => 'Documents', 'title' => 'Product documents', 'group' => ['key' => 'products', 'label' => 'Product Management', 'icon' => 'package'], 'module' => 'products', 'icon' => 'file-text', 'route' => 'products.documents', 'permission' => 'products.view', 'active' => 'products.documents'],
    ['label' => 'Product History', 'title' => 'Product history', 'group' => ['key' => 'products', 'label' => 'Product Management', 'icon' => 'package'], 'module' => 'products', 'icon' => 'history', 'route' => 'products.history', 'permission' => 'products.view_history', 'active' => 'products.history'],
    ['label' => 'Product Reports', 'title' => 'Product reports', 'group' => ['key' => 'products', 'label' => 'Product Management', 'icon' => 'package'], 'module' => 'products', 'icon' => 'file-bar-chart', 'route' => 'products.reports', 'permission' => 'products.view', 'active' => 'products.reports'],
    ['label' => 'Product Settings', 'title' => 'Product & supplier settings', 'group' => ['key' => 'products', 'label' => 'Product Management', 'icon' => 'package'], 'module' => 'products', 'icon' => 'settings-2', 'route' => 'products.settings', 'permission' => 'products.manage_settings', 'active' => 'products.settings'],
    ['label' => 'Supplier Dashboard', 'title' => 'Supplier dashboard', 'group' => ['key' => 'suppliers', 'label' => 'Supplier Management', 'icon' => 'truck'], 'module' => 'suppliers', 'icon' => 'layout-dashboard', 'route' => 'suppliers.dashboard', 'permission' => 'suppliers.view_dashboard', 'active' => 'suppliers.dashboard'],
    ['label' => 'All Suppliers', 'title' => 'All suppliers', 'group' => ['key' => 'suppliers', 'label' => 'Supplier Management', 'icon' => 'truck'], 'module' => 'suppliers', 'icon' => 'truck', 'route' => 'suppliers.index', 'permission' => 'suppliers.view', 'active' => ['suppliers.index', 'suppliers.show', 'suppliers.create', 'suppliers.edit']],
    ['label' => 'Supplier Types', 'title' => 'Supplier types', 'group' => ['key' => 'suppliers', 'label' => 'Supplier Management', 'icon' => 'truck'], 'module' => 'suppliers', 'icon' => 'tags', 'route' => 'suppliers.types', 'permission' => 'suppliers.view', 'active' => 'suppliers.types'],
    ['label' => 'Addresses', 'title' => 'Supplier addresses', 'group' => ['key' => 'suppliers', 'label' => 'Supplier Management', 'icon' => 'truck'], 'module' => 'suppliers', 'icon' => 'map-pin', 'route' => 'suppliers.addresses', 'permission' => 'suppliers.view', 'active' => 'suppliers.addresses'],
    ['label' => 'Bank Details', 'title' => 'Supplier bank details', 'group' => ['key' => 'suppliers', 'label' => 'Supplier Management', 'icon' => 'truck'], 'module' => 'suppliers', 'icon' => 'landmark', 'route' => 'suppliers.bank-details', 'permission' => 'suppliers.view_bank_details', 'active' => 'suppliers.bank-details'],
    ['label' => 'Documents', 'title' => 'Supplier documents', 'group' => ['key' => 'suppliers', 'label' => 'Supplier Management', 'icon' => 'truck'], 'module' => 'suppliers', 'icon' => 'file-text', 'route' => 'suppliers.documents', 'permission' => 'suppliers.view', 'active' => 'suppliers.documents'],
    ['label' => 'Product Mapping', 'title' => 'Supplier product mapping', 'group' => ['key' => 'suppliers', 'label' => 'Supplier Management', 'icon' => 'truck'], 'module' => 'suppliers', 'icon' => 'link', 'route' => 'suppliers.mappings', 'permission' => 'suppliers.view', 'active' => 'suppliers.mappings'],
    ['label' => 'Supplier History', 'title' => 'Supplier history', 'group' => ['key' => 'suppliers', 'label' => 'Supplier Management', 'icon' => 'truck'], 'module' => 'suppliers', 'icon' => 'history', 'route' => 'suppliers.history', 'permission' => 'suppliers.view_history', 'active' => 'suppliers.history'],
    ['label' => 'Supplier Reports', 'title' => 'Supplier reports', 'group' => ['key' => 'suppliers', 'label' => 'Supplier Management', 'icon' => 'truck'], 'module' => 'suppliers', 'icon' => 'file-bar-chart', 'route' => 'suppliers.reports', 'permission' => 'suppliers.view', 'active' => 'suppliers.reports'],

    ['section' => 'Inventory'],
    ['label' => 'Inventory Dashboard', 'title' => 'Inventory dashboard', 'group' => ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'boxes'], 'module' => 'inventory', 'icon' => 'layout-dashboard', 'route' => 'inventory.dashboard', 'permission' => 'inventory.view_dashboard', 'active' => 'inventory.dashboard'],
    ['label' => 'Warehouses', 'title' => 'All warehouses', 'group' => ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'boxes'], 'module' => 'inventory', 'icon' => 'warehouse', 'route' => 'inventory.warehouses.index', 'permission' => 'inventory.view', 'active' => 'inventory.warehouses.'],
    ['label' => 'Storage Locations', 'title' => 'Warehouse storage locations', 'group' => ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'boxes'], 'module' => 'inventory', 'icon' => 'map-pinned', 'route' => 'inventory.locations', 'permission' => 'inventory.view', 'active' => 'inventory.locations'],
    ['label' => 'Warehouse Stock', 'title' => 'Stock per warehouse', 'group' => ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'boxes'], 'module' => 'inventory', 'icon' => 'boxes', 'route' => 'inventory.stock', 'permission' => 'inventory.view', 'active' => 'inventory.stock'],
    ['label' => 'Product Inventory', 'title' => 'Product inventory settings & stock', 'group' => ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'boxes'], 'module' => 'inventory', 'icon' => 'package-search', 'route' => 'inventory.products.index', 'permission' => 'inventory.view', 'active' => 'inventory.products.'],
    ['label' => 'Opening Stock', 'title' => 'Opening stock', 'group' => ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'boxes'], 'module' => 'inventory', 'icon' => 'flag', 'route' => 'inventory.opening.index', 'permission' => 'inventory.view', 'active' => 'inventory.opening.'],
    ['label' => 'Stock Inward', 'title' => 'Stock inward', 'group' => ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'boxes'], 'module' => 'inventory', 'icon' => 'package-plus', 'route' => 'inventory.inward.index', 'permission' => 'inventory.view', 'active' => 'inventory.inward.'],
    ['label' => 'Stock Outward', 'title' => 'Stock outward', 'group' => ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'boxes'], 'module' => 'inventory', 'icon' => 'package-minus', 'route' => 'inventory.outward.index', 'permission' => 'inventory.view', 'active' => 'inventory.outward.'],
    ['label' => 'Stock Transfers', 'title' => 'Stock transfers', 'group' => ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'boxes'], 'module' => 'inventory', 'icon' => 'truck', 'route' => 'inventory.transfers.index', 'permission' => 'inventory.view', 'active' => 'inventory.transfers.'],
    ['label' => 'Stock Adjustments', 'title' => 'Stock adjustments', 'group' => ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'boxes'], 'module' => 'inventory', 'icon' => 'sliders-horizontal', 'route' => 'inventory.adjustments.index', 'permission' => 'inventory.view', 'active' => 'inventory.adjustments.'],
    ['label' => 'Batches & Expiry', 'title' => 'Batch management', 'group' => ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'boxes'], 'module' => 'inventory', 'icon' => 'layers', 'route' => 'inventory.batches', 'permission' => 'inventory.view', 'active' => 'inventory.batches'],
    ['label' => 'Serial Numbers', 'title' => 'Serial number management', 'group' => ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'boxes'], 'module' => 'inventory', 'icon' => 'scan-barcode', 'route' => 'inventory.serials', 'permission' => 'inventory.view', 'active' => 'inventory.serials'],
    ['label' => 'Reservations', 'title' => 'Stock reservations', 'group' => ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'boxes'], 'module' => 'inventory', 'icon' => 'bookmark', 'route' => 'inventory.reservations', 'permission' => 'inventory.view', 'active' => 'inventory.reservations'],
    ['label' => 'Stock Ledger', 'title' => 'Stock ledger', 'group' => ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'boxes'], 'module' => 'inventory', 'icon' => 'book-text', 'route' => 'inventory.ledger', 'permission' => 'inventory.view_stock_ledger', 'active' => 'inventory.ledger'],
    ['label' => 'Valuation', 'title' => 'Inventory valuation', 'group' => ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'boxes'], 'module' => 'inventory', 'icon' => 'indian-rupee', 'route' => 'inventory.valuation', 'permission' => 'inventory.view_stock_cost', 'active' => 'inventory.valuation'],
    ['label' => 'Low Stock', 'title' => 'Low stock & reorder', 'group' => ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'boxes'], 'module' => 'inventory', 'icon' => 'triangle-alert', 'route' => 'inventory.low-stock', 'permission' => 'inventory.view', 'active' => 'inventory.low-stock'],
    ['label' => 'Inventory Reports', 'title' => 'Inventory reports', 'group' => ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'boxes'], 'module' => 'inventory', 'icon' => 'file-bar-chart', 'route' => 'inventory.reports', 'permission' => 'inventory.view', 'active' => 'inventory.reports'],
    ['label' => 'Inventory History', 'title' => 'Inventory history', 'group' => ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'boxes'], 'module' => 'inventory', 'icon' => 'history', 'route' => 'inventory.history', 'permission' => 'inventory.view_history', 'active' => 'inventory.history'],
    ['label' => 'Inventory Settings', 'title' => 'Inventory settings', 'group' => ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'boxes'], 'module' => 'inventory', 'icon' => 'settings-2', 'route' => 'inventory.settings', 'permission' => 'inventory.view', 'active' => 'inventory.settings'],

    ['section' => 'E-commerce'],
    ['label' => 'E-commerce Dashboard', 'title' => 'E-commerce dashboard', 'group' => ['key' => 'ecommerce', 'label' => 'E-commerce', 'icon' => 'store'], 'module' => 'platforms', 'icon' => 'layout-dashboard', 'route' => 'ecommerce.dashboard', 'permission' => 'platforms.view_dashboard', 'active' => 'ecommerce.dashboard'],
    ['label' => 'Platforms', 'title' => 'E-commerce platforms', 'group' => ['key' => 'ecommerce', 'label' => 'E-commerce', 'icon' => 'store'], 'module' => 'platforms', 'icon' => 'store', 'route' => 'ecommerce.platforms.index', 'permission' => 'platforms.view', 'active' => 'ecommerce.platforms.'],
    ['label' => 'Platform Categories', 'title' => 'Platform categories', 'group' => ['key' => 'ecommerce', 'label' => 'E-commerce', 'icon' => 'store'], 'module' => 'platforms', 'icon' => 'tags', 'route' => 'ecommerce.categories', 'permission' => 'platforms.view', 'active' => 'ecommerce.categories'],
    ['label' => 'Platform History', 'title' => 'Platform history', 'group' => ['key' => 'ecommerce', 'label' => 'E-commerce', 'icon' => 'store'], 'module' => 'platforms', 'icon' => 'history', 'route' => 'ecommerce.history', 'permission' => 'platforms.view_history', 'active' => 'ecommerce.history'],

    ['section' => 'Upcoming modules'],
    ['label' => 'Purchases', 'group' => ['key' => 'operations', 'label' => 'Operations', 'icon' => 'package'], 'module' => 'purchases', 'icon' => 'shopping-cart', 'route' => null, 'phase' => 11],
    ['label' => 'Sales & Orders', 'group' => ['key' => 'operations', 'label' => 'Operations', 'icon' => 'package'], 'module' => 'sales_orders', 'icon' => 'receipt', 'route' => null, 'phase' => 9],
    ['label' => 'Returns & Refunds', 'group' => ['key' => 'operations', 'label' => 'Operations', 'icon' => 'package'], 'module' => 'returns_refunds', 'icon' => 'undo-2', 'route' => null, 'phase' => 10],
    ['label' => 'Settlements', 'group' => ['key' => 'operations', 'label' => 'Operations', 'icon' => 'package'], 'module' => 'settlements', 'icon' => 'handshake', 'route' => null, 'phase' => 10],
    ['label' => 'Expenses', 'group' => ['key' => 'operations', 'label' => 'Operations', 'icon' => 'package'], 'module' => 'expenses', 'icon' => 'wallet', 'route' => null, 'phase' => 11],

    ['label' => 'Accounting', 'group' => ['key' => 'finance', 'label' => 'Finance & Reports', 'icon' => 'chart-line'], 'module' => 'accounting', 'icon' => 'book-open', 'route' => null, 'phase' => 12],
    ['label' => 'GST Reports', 'group' => ['key' => 'finance', 'label' => 'Finance & Reports', 'icon' => 'chart-line'], 'module' => 'gst_reports', 'icon' => 'percent', 'route' => null, 'phase' => 13],
    ['label' => 'Financial Reports', 'group' => ['key' => 'finance', 'label' => 'Finance & Reports', 'icon' => 'chart-line'], 'module' => 'financial_reports', 'icon' => 'chart-line', 'route' => null, 'phase' => 14],

    ['section' => 'Administration'],
    ['label' => 'Approvals', 'icon' => 'stamp', 'route' => 'approvals.index', 'permission' => null, 'active' => 'approvals', 'approver' => true],
    ['label' => 'Control Center', 'icon' => 'shield-check', 'route' => 'admin.overview', 'permission' => null, 'active' => 'admin', 'super_admin' => true],
    ['label' => 'User Management', 'icon' => 'user-cog', 'route' => 'users.index', 'permission' => 'users.view', 'active' => 'users'],
    ['label' => 'Activity Log', 'icon' => 'scroll-text', 'route' => 'activity.index', 'permission' => 'activity_logs.view', 'active' => 'activity'],
    ['label' => 'Settings', 'icon' => 'settings', 'route' => 'settings.index', 'permission' => 'settings.view', 'active' => 'settings'],

    // Open to every signed-in user (no permission): the manual is read-only for everyone.
    ['section' => 'Help & Support'],
    ['label' => 'Help & User Guide', 'icon' => 'life-buoy', 'route' => 'help.index', 'permission' => null, 'active' => 'help'],
];
