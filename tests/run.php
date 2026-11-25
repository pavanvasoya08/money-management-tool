<?php

declare(strict_types=1);

/*
 * Test runner:  php tests/run.php [filter]
 *
 * Uses a throw-away SQLite database (pdo_sqlite) so no MySQL server is needed.
 * Feature tests start PHP's built-in web server and drive the app over HTTP (ext-curl).
 */

$base = dirname(__DIR__);
$tmp = $base . '/tests/.tmp';
if (!is_dir($tmp)) {
    mkdir($tmp, 0770, true);
}

$env = [
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:8765',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $tmp . '/test.sqlite',
    'SESSION_SECURE_COOKIE' => 'false', 'MAIL_DRIVER' => 'log',
    'APP_KEY' => 'base64:' . base64_encode(str_repeat('t', 32)), // test-only key for the account-number cipher
];
foreach ($env as $k => $v) {
    putenv("{$k}={$v}");
    $_ENV[$k] = $v;
}

require $base . '/vendor/autoload.php';
require_once $base . '/tests/TestCase.php';
App\Core\App::boot($base);

$filter = $argv[1] ?? '';
$files = array_merge(glob($base . '/tests/Unit/*Test.php') ?: [], glob($base . '/tests/Integration/*Test.php') ?: [], glob($base . '/tests/Feature/*Test.php') ?: []);

$passed = $failed = $assertions = 0;
$failures = [];
$started = microtime(true);
echo 'PHP ' . PHP_VERSION . " · SQLite test database\n\n";

foreach ($files as $file) {
    require_once $file;
    $suite = basename(dirname($file));
    $class = 'Tests\\' . $suite . '\\' . basename($file, '.php');
    if ($filter !== '' && !str_contains($class, $filter)) {
        continue;
    }
    echo str_pad($suite . '/' . basename($file, '.php'), 40);
    foreach (get_class_methods($class) as $method) {
        if (!str_starts_with($method, 'test')) {
            continue;
        }
        $test = new $class();
        try {
            $test->setUp();
            $test->{$method}();
            if (method_exists($test, 'tearDown')) {
                $test->tearDown();
            }
            $passed++;
            echo '.';
        } catch (Throwable $e) {
            if (method_exists($test, 'tearDown')) {
                try { $test->tearDown(); } catch (Throwable) {}
            }
            $failed++;
            echo 'F';
            $detail = $e instanceof \App\Exceptions\ValidationException ? ' ' . json_encode($e->errors, JSON_UNESCAPED_UNICODE) : '';
            $trace = array_values(array_filter($e->getTrace(), static fn (array $f): bool => str_contains((string) ($f['file'] ?? ''), '/tests/')));
            $failures[] = sprintf("%s::%s\n    %s: %s%s\n    at %s:%d%s", $class, $method, $e::class, $e->getMessage(), $detail, $e->getFile(), $e->getLine(),
                isset($trace[0]) ? "\n    from {$trace[0]['file']}:{$trace[0]['line']}" : '');
        }
        $assertions += $test->assertions;
    }
    echo "\n";
}

printf("\n%d passed, %d failed, %d assertions in %.2fs\n", $passed, $failed, $assertions, microtime(true) - $started);
if ($failures !== []) {
    echo "\nFailures:\n\n" . implode("\n\n", $failures) . "\n";
}
exit($failed === 0 ? 0 : 1);
