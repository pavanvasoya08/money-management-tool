<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'default' => (string) Env::get('DB_CONNECTION', 'mysql'),

    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => (string) Env::get('DB_HOST', '127.0.0.1'),
            'port' => (int) Env::get('DB_PORT', 3306),
            'database' => (string) Env::get('DB_DATABASE', 'business_erp'),
            'username' => (string) Env::get('DB_USERNAME', 'root'),
            'password' => (string) Env::get('DB_PASSWORD', ''),
        ],
        // Used only by the automated test suite (no MySQL server required).
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => (string) Env::get('DB_DATABASE', ':memory:'),
        ],
    ],
];
