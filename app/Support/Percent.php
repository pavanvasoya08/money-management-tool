<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact percentages with 4 decimals, held as integer "basis units"
 * (1% = 10,000 units, 100% = 1,000,000) and stored as DECIMAL(7,4).
 */
final class Percent
{
    public const SCALE = 10_000;
    public const HUNDRED = 1_000_000;

    public static function isValidInput(string $value): bool
    {
        $value = trim($value);
        if (preg_match('/^\d{1,3}(\.\d{1,4})?$/', $value) !== 1) {
            return false;
        }
        $u = self::parse($value);
        return $u >= 0 && $u <= self::HUNDRED;
    }

    public static function parse(string $value): int
    {
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,4}))?$/', trim($value), $m) !== 1) {
            throw new InvalidArgumentException('Invalid percentage.');
        }
        return (int) $m[1] * self::SCALE + (int) str_pad($m[2] ?? '0', 4, '0');
    }

    public static function fromDb(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_float($value) || is_int($value)) {
            return (int) round($value * self::SCALE);
        }
        $s = trim((string) $value);
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $s, $m) !== 1) {
            throw new InvalidArgumentException("Invalid stored percentage [{$value}].");
        }
        return (int) $m[1] * self::SCALE + (int) substr(str_pad($m[2] ?? '0', 4, '0'), 0, 4);
    }

    public static function toDb(int $units): string
    {
        return intdiv($units, self::SCALE) . '.' . str_pad((string) ($units % self::SCALE), 4, '0', STR_PAD_LEFT);
    }

    /** 30.0000 → "30%", 12.5000 → "12.5%" */
    public static function format(int $units): string
    {
        $s = self::toDb($units);
        return rtrim(rtrim($s, '0'), '.') . '%';
    }
}
