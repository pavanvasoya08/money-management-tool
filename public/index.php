<?php

declare(strict_types=1);

/*
 * Front controller — the only PHP file exposed by the web server.
 * Point the virtual host's document root at this /public directory.
 */

$basePath = dirname(__DIR__);

if (!is_file($basePath . '/vendor/autoload.php')) {
    http_response_code(500);
    exit('Dependencies are not installed. Run "composer install" in the project directory.');
}

require $basePath . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Request;

App::boot($basePath);
App::handle(Request::capture())->send();
