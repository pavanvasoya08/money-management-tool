<?php

declare(strict_types=1);

use App\Middleware\Authenticate;
use App\Middleware\Authorize;
use App\Middleware\RedirectIfAuthenticated;
use App\Middleware\SecurityHeaders;
use App\Middleware\StartSession;
use App\Middleware\SuperAdminOnly;
use App\Middleware\VerifyCsrfToken;

return [
    // Run on every request, in this order.
    'global' => ['headers', 'session', 'csrf'],

    'aliases' => [
        'headers' => SecurityHeaders::class,
        'session' => StartSession::class,
        'csrf' => VerifyCsrfToken::class,
        'auth' => Authenticate::class,
        'guest' => RedirectIfAuthenticated::class,
        'can' => Authorize::class,           // usage: 'can:users.view'
        'super-admin' => SuperAdminOnly::class,
    ],
];
