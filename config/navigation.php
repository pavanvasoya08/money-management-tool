<?php

declare(strict_types=1);

/*
 * Sidebar navigation. Items with 'route' => null are future modules and are
 * rendered as disabled "Coming soon" entries (no fake pages behind them).
 * When a phase ships, set its route and permission here.
 */
return [
    ['section' => 'Overview'],
    ['label' => 'Dashboard', 'icon' => 'layout-dashboard', 'route' => 'dashboard', 'permission' => null, 'active' => 'dashboard'],

    ['section' => 'Partners & Compliance'],
    ['label' => 'Partners', 'icon' => 'users-round', 'route' => null, 'phase' => 3],
    ['label' => 'Capital Contributions', 'icon' => 'piggy-bank', 'route' => null, 'phase' => 3],
    ['label' => 'GST Registrations', 'icon' => 'file-badge', 'route' => null, 'phase' => 4],
    ['label' => 'Bank Accounts', 'icon' => 'landmark', 'route' => null, 'phase' => 5],

    ['section' => 'Operations'],
    ['label' => 'Products', 'icon' => 'package', 'route' => null, 'phase' => 6],
    ['label' => 'Suppliers', 'icon' => 'truck', 'route' => null, 'phase' => 6],
    ['label' => 'Purchases', 'icon' => 'shopping-cart', 'route' => null, 'phase' => 11],
    ['label' => 'Inventory', 'icon' => 'boxes', 'route' => null, 'phase' => 7],
    ['label' => 'E-commerce Platforms', 'icon' => 'store', 'route' => null, 'phase' => 8],
    ['label' => 'Sales & Orders', 'icon' => 'receipt', 'route' => null, 'phase' => 9],
    ['label' => 'Returns & Refunds', 'icon' => 'undo-2', 'route' => null, 'phase' => 10],
    ['label' => 'Settlements', 'icon' => 'handshake', 'route' => null, 'phase' => 10],
    ['label' => 'Expenses', 'icon' => 'wallet', 'route' => null, 'phase' => 11],

    ['section' => 'Finance & Reports'],
    ['label' => 'Accounting', 'icon' => 'book-open', 'route' => null, 'phase' => 12],
    ['label' => 'GST Reports', 'icon' => 'percent', 'route' => null, 'phase' => 13],
    ['label' => 'Financial Reports', 'icon' => 'chart-line', 'route' => null, 'phase' => 14],

    ['section' => 'Administration'],
    ['label' => 'User Management', 'icon' => 'user-cog', 'route' => 'users.index', 'permission' => 'users.view', 'active' => 'users'],
    ['label' => 'Activity Log', 'icon' => 'scroll-text', 'route' => 'activity.index', 'permission' => 'activity_logs.view', 'active' => 'activity'],
    ['label' => 'Settings', 'icon' => 'settings', 'route' => 'settings.index', 'permission' => 'settings.view', 'active' => 'settings'],
];
