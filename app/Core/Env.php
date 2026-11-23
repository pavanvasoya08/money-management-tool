<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env loader. Real environment variables always take precedence
 * over values in the .env file, so servers can inject secrets safely.
 */
final class Env
{
    public static function load(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }
            $value = self::parseValue($value);
            if (getenv($key) !== false || array_key_exists($key, $_ENV)) {
                continue; // never override the real environment
            }
            $_ENV[$key] = $value;
        }
    }

    private static function parseValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $first = $value[0];
        if (($first === '"' || $first === "'") && str_ends_with($value, $first) && strlen($value) >= 2) {
            return substr($value, 1, -1);
        }
        // strip trailing inline comment: VALUE # comment
        $hash = strpos($value, ' #');
        return $hash === false ? $value : rtrim(substr($value, 0, $hash));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? null;
        }
        if ($value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            'empty' => '',
            default => $value,
        };
    }
}
