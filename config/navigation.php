<?php

declare(strict_types=1);

/*
 * Sidebar navigation. Items with 'route' => null are future modules and are
 * rendered as disabled "Coming soon" entries (no fake pages behind them).
 * When a phase ships, set its route and permission here.
 *   'module'      — module key (Preview User Access shows whether the user will see it)
 *   'super_admin' — only real Super Admins see the item
 *   'approver'    — shown to users who can approve or reject in any module
 */
return [
    ['section' => 'Overview'],
    ['label' => 'Dashboard', 'icon' => 'layout-dashboard', 'route' => 'dashboard', 'permission' => null, 'active' => 'dashboard'],

    ['section' => 'Partners & Compliance'],
    ['label' => 'Partners', 'module' => 'partners', 'icon' => 'users-round', 'route' => null, 'phase' => 3],
    ['label' => 'Capital Contributions', 'module' => 'capital_contributions', 'icon' => 'piggy-bank', 'route' => null, 'phase' => 3],
    ['label' => 'GST Registrations', 'module' => 'gst_registrations', 'icon' => 'file-badge', 'route' => null, 'phase' => 4],
    ['label' => 'Bank Accounts', 'module' => 'bank_accounts', 'icon' => 'landmark', 'route' => null, 'phase' => 5],

    ['section' => 'Operations'],
    ['label' => 'Products', 'module' => 'products', 'icon' => 'package', 'route' => null, 'phase' => 6],
    ['label' => 'Suppliers', 'module' => 'suppliers', 'icon' => 'truck', 'route' => null, 'phase' => 6],
    ['label' => 'Purchases', 'module' => 'purchases', 'icon' => 'shopping-cart', 'route' => null, 'phase' => 11],
    ['label' => 'Inventory', 'module' => 'inventory', 'icon' => 'boxes', 'route' => null, 'phase' => 7],
    ['label' => 'E-commerce Platforms', 'module' => 'platforms', 'icon' => 'store', 'route' => null, 'phase' => 8],
    ['label' => 'Sales & Orders', 'module' => 'sales_orders', 'icon' => 'receipt', 'route' => null, 'phase' => 9],
    ['label' => 'Returns & Refunds', 'module' => 'returns_refunds', 'icon' => 'undo-2', 'route' => null, 'phase' => 10],
    ['label' => 'Settlements', 'module' => 'settlements', 'icon' => 'handshake', 'route' => null, 'phase' => 10],
    ['label' => 'Expenses', 'module' => 'expenses', 'icon' => 'wallet', 'route' => null, 'phase' => 11],

    ['section' => 'Finance & Reports'],
    ['label' => 'Accounting', 'module' => 'accounting', 'icon' => 'book-open', 'route' => null, 'phase' => 12],
    ['label' => 'GST Reports', 'module' => 'gst_reports', 'icon' => 'percent', 'route' => null, 'phase' => 13],
    ['label' => 'Financial Reports', 'module' => 'financial_reports', 'icon' => 'chart-line', 'route' => null, 'phase' => 14],

    ['section' => 'Administration'],
    ['label' => 'Approvals', 'icon' => 'stamp', 'route' => 'approvals.index', 'permission' => null, 'active' => 'approvals', 'approver' => true],
    ['label' => 'Control Center', 'icon' => 'shield-check', 'route' => 'admin.overview', 'permission' => null, 'active' => 'admin', 'super_admin' => true],
    ['label' => 'User Management', 'icon' => 'user-cog', 'route' => 'users.index', 'permission' => 'users.view', 'active' => 'users'],
    ['label' => 'Activity Log', 'icon' => 'scroll-text', 'route' => 'activity.index', 'permission' => 'activity_logs.view', 'active' => 'activity'],
    ['label' => 'Settings', 'icon' => 'settings', 'route' => 'settings.index', 'permission' => 'settings.view', 'active' => 'settings'],
];
