<?php

declare(strict_types=1);

namespace App\Core;

/**
 * File logger (JSON lines in storage/logs/app-YYYY-MM-DD.log).
 * Sensitive keys are redacted before anything is written.
 */
final class Logger
{
    private const SENSITIVE = '/pass|token|secret|cookie|authorization|hash|otp|pin/i';

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function write(string $level, string $message, array $context = []): void
    {
        $dir = App::basePath('storage/logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $line = json_encode([
            'time' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'message' => $message,
            'context' => self::redact($context),
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /** @param array<mixed> $context @return array<mixed> */
    public static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE, $key)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = self::redact($value);
            }
        }
        return $context;
    }
}
