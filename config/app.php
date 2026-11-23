<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'name' => (string) Env::get('APP_NAME', 'Business ERP'),
    'env' => (string) Env::get('APP_ENV', 'production'),
    'debug' => Env::get('APP_DEBUG', false) === true,
    'url' => rtrim((string) Env::get('APP_URL', 'http://localhost'), '/'),
    // Default display timezone (overridable in Settings). Storage is always UTC.
    'timezone' => (string) Env::get('APP_TIMEZONE', 'Asia/Kolkata'),
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('TRUSTED_PROXIES', ''))))),
    'version' => '1.0.0-phase1',
    'min_php' => '8.5.0',
];
