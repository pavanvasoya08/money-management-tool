<?php

declare(strict_types=1);

/*
 * Router for PHP's built-in development server ONLY:
 *   php -S 127.0.0.1:8000 server.php
 * Never use the built-in server in production.
 */
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/public' . $path;
if ($path !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
    return false; // let the built-in server serve the static asset
}
require __DIR__ . '/public/index.php';
