<?php

declare(strict_types=1);

/*
 * Business resource types that can be assigned to users
 * (user_resource_assignments) and used by "Assigned" / "Selected …" data scopes.
 *
 * A type becomes selectable in the UI as soon as its table exists — later
 * phases only need to create the table. Nothing here is hardcoded to a fixed
 * number of partners, GST registrations, accounts or platforms.
 */
return [
    'partner' => ['label' => 'Partners', 'table' => 'partners', 'label_column' => 'name', 'phase' => 3],
    'gst_registration' => ['label' => 'GST registrations', 'table' => 'gst_registrations', 'label_column' => 'gstin', 'phase' => 4],
    'bank_account' => ['label' => 'Bank accounts', 'table' => 'bank_accounts', 'label_column' => 'display_name', 'phase' => 5],
    'product' => ['label' => 'Products', 'table' => 'products', 'label_column' => 'name', 'phase' => 6],
    'supplier' => ['label' => 'Suppliers', 'table' => 'suppliers', 'label_column' => 'name', 'phase' => 6],
    'warehouse' => ['label' => 'Warehouses', 'table' => 'warehouses', 'label_column' => 'name', 'phase' => 7],
    'platform' => ['label' => 'E-commerce platforms', 'table' => 'ecommerce_platforms', 'label_column' => 'name', 'phase' => 8],
    'seller_account' => ['label' => 'Seller accounts', 'table' => 'seller_accounts', 'label_column' => 'name', 'phase' => 8],
    'business_unit' => ['label' => 'Business units', 'table' => 'business_units', 'label_column' => 'name', 'phase' => 3],
    'user' => ['label' => 'Users (managed team members)', 'table' => 'users', 'label_column' => 'name', 'phase' => 1],
];
