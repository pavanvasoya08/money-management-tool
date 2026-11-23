<?php

declare(strict_types=1);

/*
 * Authentication defaults. Values marked (setting) can be changed by an
 * administrator in Settings → Security; these are the fallbacks.
 */
return [
    'remember_cookie' => 'erp_remember',
    'remember_days' => 30,                 // (setting) remember_me_days

    'lockout' => [
        'max_attempts' => 5,               // (setting) login_max_attempts — per account
        'minutes' => 15,                   // (setting) lockout_minutes
        'ip_max_attempts' => 20,           // failed attempts per IP inside the window
        'ip_window_minutes' => 15,
    ],

    'password' => [
        'min_length' => 10,                // (setting) password_min_length
        'max_length' => 128,
    ],

    'reset' => [
        'expiry_minutes' => 60,            // (setting) password_reset_expiry_minutes
        'max_requests_per_hour' => 3,
    ],

    'session_idle_minutes' => 30,          // (setting) session_idle_minutes

    // Role slug that bypasses all permission checks. Only Super Admins can grant it.
    'super_admin_role' => 'super-admin',
];
