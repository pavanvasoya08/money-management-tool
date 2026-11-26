<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact stock-quantity and unit-cost arithmetic on scaled integers (4 decimals).
 *
 * Quantities are DECIMAL(18,4) in the database and handled here as integers in units of
 * 1/10 000 (1 piece = 10 000). Unit costs use the same scale (1 rupee = 10 000), values
 * are paise (see Money). Nothing is ever a float. mulDiv() multiplies and divides exactly
 * with half-up rounding, falling back to decimal-string arithmetic when the intermediate
 * product would not fit in 64 bits (no bcmath / gmp needed).
 */
final class Qty
{
    public const SCALE = 10_000;
    public const MAX = 99_999_999_999 * self::SCALE; // 99,999,999,999 units keeps every intermediate step exact

    /** Parse user input: digits, optional "." and up to $decimals (≤ 4) decimals. Null when invalid. */
    public static function parse(string $value, int $decimals = 4): ?int
    {
        $value = str_replace(',', '', trim($value));
        $decimals = max(0, min(4, $decimals));
        $pattern = $decimals === 0 ? '/^(\d{1,11})$/' : '/^(\d{1,11})(?:\.(\d{1,' . $decimals . '}))?$/';
        if (preg_match($pattern, $value, $m) !== 1) {
            return null;
        }
        return (int) $m[1] * self::SCALE + (int) str_pad($m[2] ?? '0', 4, '0');
    }

    /** Like parse() for a signed value (adjustment differences). */
    public static function parseSigned(string $value, int $decimals = 4): ?int
    {
        $value = trim($value);
        $neg = str_starts_with($value, '-');
        $q = self::parse(ltrim($value, '-+'), $decimals);
        return $q === null ? null : ($neg ? -$q : $q);
    }

    /** DECIMAL column value (string or number) → scaled integer. */
    public static function fromDb(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_int($value)) {
            return $value * self::SCALE;
        }
        $s = is_float($value) ? sprintf('%.4F', $value) : trim((string) $value);
        $neg = str_starts_with($s, '-');
        $s = ltrim($s, '-+');
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $s, $m) !== 1) {
            throw new InvalidArgumentException("Invalid stored quantity [{$value}].");
        }
        $frac = $m[2] ?? '0';
        $q = (int) $m[1] * self::SCALE + (int) substr(str_pad($frac, 4, '0'), 0, 4);
        if (strlen($frac) > 4 && (int) $frac[4] >= 5) {
            $q++;
        }
        return $neg ? -$q : $q;
    }

    /** Scaled integer → "1234.5000" for DECIMAL(18,4) columns. */
    public static function toDb(int $q): string
    {
        $neg = $q < 0;
        $a = abs($q);
        return ($neg ? '-' : '') . intdiv($a, self::SCALE) . '.' . str_pad((string) ($a % self::SCALE), 4, '0', STR_PAD_LEFT);
    }

    /** Plain value for form inputs and re-validation ("10", "10.5") — no grouping, no trailing zeros. */
    public static function toInput(int $q): string
    {
        $s = self::toDb($q);
        return rtrim(rtrim($s, '0'), '.');
    }

    /** For display: trailing zeros removed, Indian digit grouping ("1,20,000.5"). */
    public static function format(int $q, ?int $decimals = null): string
    {
        $neg = $q < 0;
        $a = abs($q);
        $int = (string) intdiv($a, self::SCALE);
        $frac = str_pad((string) ($a % self::SCALE), 4, '0', STR_PAD_LEFT);
        $frac = $decimals !== null ? substr($frac, 0, max(0, $decimals)) : rtrim($frac, '0');
        if (strlen($int) > 3) {
            $last = substr($int, -3);
            $rest = substr($int, 0, -3);
            $int = ltrim(strrev(implode(',', str_split(strrev($rest), 2))), ',') . ',' . $last;
        }
        return ($neg ? '-' : '') . $int . ($frac !== '' && trim($frac, '0') !== '' || ($decimals ?? 0) > 0 ? '.' . $frac : '');
    }

    /** Whether a quantity is whole (no fractional units). */
    public static function isWhole(int $q): bool
    {
        return $q % self::SCALE === 0;
    }

    /** Value in paise of $qty units at $unitCost (both scaled). */
    public static function value(int $qty, int $unitCost): int
    {
        return self::mulDiv($qty, $unitCost, 1_000_000);
    }

    /** Unit cost (scaled) of $qty units worth $paise. */
    public static function unitCost(int $paise, int $qty): int
    {
        return $qty === 0 ? 0 : self::mulDiv($paise, 1_000_000, $qty);
    }

    /** round(a × b ÷ d), half away from zero, exact for any 64-bit inputs whose result fits. */
    public static function mulDiv(int $a, int $b, int $d): int
    {
        if ($d === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }
        $neg = (($a < 0) xor ($b < 0)) xor ($d < 0);
        $a = abs($a);
        $b = abs($b);
        $d = abs($d);
        if ($a === 0 || $b === 0) {
            return 0;
        }
        if ($a <= intdiv(PHP_INT_MAX, $b)) {
            $n = $a * $b;
            $q = intdiv($n, $d);
            $r = $n % $d;
            if ($r >= $d - $r) {
                $q++;
            }
        } else {
            $q = self::bigMulDiv($a, $b, $d);
        }
        return $neg ? -$q : $q;
    }

    /** Decimal-string schoolbook multiply, then long division by $d (≤ 10^17 keeps every remainder in range). */
    private static function bigMulDiv(int $a, int $b, int $d): int
    {
        if ($d > 100_000_000_000_000_000) {
            throw new InvalidArgumentException('Quantity or value is too large to calculate exactly.');
        }
        $x = array_reverse(array_map('intval', str_split((string) $a)));
        $y = array_reverse(array_map('intval', str_split((string) $b)));
        $prod = array_fill(0, count($x) + count($y), 0);
        foreach ($x as $i => $dx) {
            $carry = 0;
            foreach ($y as $j => $dy) {
                $cur = $prod[$i + $j] + $dx * $dy + $carry;
                $prod[$i + $j] = $cur % 10;
                $carry = intdiv($cur, 10);
            }
            $k = $i + count($y);
            while ($carry > 0) {
                $cur = $prod[$k] + $carry;
                $prod[$k] = $cur % 10;
                $carry = intdiv($cur, 10);
                $k++;
            }
        }
        $digits = ltrim(implode('', array_reverse($prod)), '0');
        $q = '';
        $rem = 0;
        foreach (str_split($digits === '' ? '0' : $digits) as $ch) {
            $rem = $rem * 10 + (int) $ch;
            $q .= (string) intdiv($rem, $d);
            $rem %= $d;
        }
        $q = ltrim($q, '0');
        if (strlen($q) > 18 && (strlen($q) > 19 || strcmp($q, (string) PHP_INT_MAX) > 0)) {
            throw new InvalidArgumentException('Quantity or value is too large to calculate exactly.');
        }
        $result = (int) ($q === '' ? '0' : $q);
        if ($rem >= $d - $rem) {
            $result++;
        }
        return $result;
    }
}
