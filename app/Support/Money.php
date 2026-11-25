<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact money arithmetic in integer paise (1 rupee = 100 paise).
 *
 * Amounts are stored as DECIMAL(15,2) and never handled as floats in
 * business logic: input strings are parsed digit by digit, database values
 * are converted once, and all sums/comparisons use integers. Rounding rule:
 * inputs with more than 2 decimals are rejected (no silent rounding).
 * PHP integers are 64-bit, so the limit (10^13 rupees) is far above DECIMAL(15,2).
 */
final class Money
{
    public const MAX_PAISE = 999_999_999_999_999; // DECIMAL(15,2) upper bound: 9,999,999,999,999.99

    /** "1,50,000.50" style input is not accepted — digits and one dot only. */
    public static function isValidInput(string $value): bool
    {
        $value = trim($value);
        if (preg_match('/^\d{1,13}(\.\d{1,2})?$/', $value) !== 1) {
            return false;
        }
        $p = self::parse($value);
        return $p > 0 && $p <= self::MAX_PAISE;
    }

    /** Parse validated user input ("1500", "1500.5", "1500.50") into paise. */
    public static function parse(string $value): int
    {
        $value = trim($value);
        if (preg_match('/^(\d{1,13})(?:\.(\d{1,2}))?$/', $value, $m) !== 1) {
            throw new InvalidArgumentException('Invalid amount.');
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /** Like parse(), but allows a leading minus (e.g. an overdrawn statement balance). */
    public static function parseSigned(string $value): int
    {
        $value = str_replace(',', '', trim($value));
        return str_starts_with($value, '-') ? -self::parse(substr($value, 1)) : self::parse($value);
    }

    /** Convert a value read from a DECIMAL column (string in MySQL, string/number in SQLite). */
    public static function fromDb(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_int($value)) {
            return $value * 100;
        }
        if (is_float($value)) {
            return (int) round($value * 100);
        }
        $s = trim((string) $value);
        $neg = str_starts_with($s, '-');
        $s = ltrim($s, '-+');
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $s, $m) !== 1) {
            throw new InvalidArgumentException("Invalid stored amount [{$value}].");
        }
        $frac = $m[2] ?? '0';
        $paise = (int) $m[1] * 100 + (int) substr(str_pad($frac, 2, '0'), 0, 2);
        if (strlen($frac) > 2 && (int) $frac[2] >= 5) {
            $paise++; // half-up for any (unexpected) extra precision
        }
        return $neg ? -$paise : $paise;
    }

    /** Paise → "12345.67" for DECIMAL columns. */
    public static function toDb(int $paise): string
    {
        $neg = $paise < 0;
        $p = abs($paise);
        return ($neg ? '-' : '') . intdiv($p, 100) . '.' . str_pad((string) ($p % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Paise → float only for display-layer libraries (charts) and approval thresholds. */
    public static function toFloat(int $paise): float
    {
        return $paise / 100;
    }

    /** Indian digit grouping: 12,34,567.89 */
    public static function format(int $paise, string $symbol = '₹'): string
    {
        $neg = $paise < 0;
        $p = abs($paise);
        $rupees = (string) intdiv($p, 100);
        if (strlen($rupees) > 3) {
            $last3 = substr($rupees, -3);
            $rest = substr($rupees, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $rupees = $rest . ',' . $last3;
        }
        return ($neg ? '-' : '') . $symbol . $rupees . '.' . str_pad((string) ($p % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Sum a list of DB values exactly. @param iterable<mixed> $values */
    public static function sum(iterable $values): int
    {
        $t = 0;
        foreach ($values as $v) {
            $t += self::fromDb($v);
        }
        return $t;
    }
}
