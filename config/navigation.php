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

    ['section' => 'Upcoming modules'],
    ['label' => 'GST Registrations', 'group' => ['key' => 'compliance', 'label' => 'Compliance & Banking', 'icon' => 'landmark'], 'module' => 'gst_registrations', 'icon' => 'file-badge', 'route' => null, 'phase' => 4],
    ['label' => 'Bank Accounts', 'group' => ['key' => 'compliance', 'label' => 'Compliance & Banking', 'icon' => 'landmark'], 'module' => 'bank_accounts', 'icon' => 'landmark', 'route' => null, 'phase' => 5],
    ['label' => 'Products', 'group' => ['key' => 'operations', 'label' => 'Operations', 'icon' => 'package'], 'module' => 'products', 'icon' => 'package', 'route' => null, 'phase' => 6],
    ['label' => 'Suppliers', 'group' => ['key' => 'operations', 'label' => 'Operations', 'icon' => 'package'], 'module' => 'suppliers', 'icon' => 'truck', 'route' => null, 'phase' => 6],
    ['label' => 'Purchases', 'group' => ['key' => 'operations', 'label' => 'Operations', 'icon' => 'package'], 'module' => 'purchases', 'icon' => 'shopping-cart', 'route' => null, 'phase' => 11],
    ['label' => 'Inventory', 'group' => ['key' => 'operations', 'label' => 'Operations', 'icon' => 'package'], 'module' => 'inventory', 'icon' => 'boxes', 'route' => null, 'phase' => 7],
    ['label' => 'E-commerce Platforms', 'group' => ['key' => 'operations', 'label' => 'Operations', 'icon' => 'package'], 'module' => 'platforms', 'icon' => 'store', 'route' => null, 'phase' => 8],
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
