<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Barcode format checks. EAN-13, EAN-8 and UPC-A carry a GS1 check digit, which is
 * verified; "other" accepts 4–50 letters, digits and - . / characters. A valid format
 * does not mean the code is registered with GS1.
 */
final class Barcode
{
    public const TYPES = ['ean13' => 'EAN-13', 'ean8' => 'EAN-8', 'upc' => 'UPC-A', 'other' => 'Other'];

    /** Guess the type from the digits (used when none is chosen). */
    public static function detect(string $code): string
    {
        return match (true) {
            preg_match('/^\d{13}$/', $code) === 1 => 'ean13',
            preg_match('/^\d{12}$/', $code) === 1 => 'upc',
            preg_match('/^\d{8}$/', $code) === 1 => 'ean8',
            default => 'other',
        };
    }

    /** @return string|null error message, null when valid */
    public static function error(string $code, string $type): ?string
    {
        $lengths = ['ean13' => 13, 'ean8' => 8, 'upc' => 12];
        if (isset($lengths[$type])) {
            if (preg_match('/^\d{' . $lengths[$type] . '}$/', $code) !== 1) {
                return self::TYPES[$type] . " barcodes have exactly {$lengths[$type]} digits.";
            }
            if (self::checkDigit(substr($code, 0, -1)) !== (int) substr($code, -1)) {
                return 'The last digit of this ' . self::TYPES[$type] . ' barcode does not match its check digit — the code has probably been mistyped.';
            }
            return null;
        }
        return preg_match('#^[A-Za-z0-9][A-Za-z0-9\-./]{3,49}$#', $code) === 1 ? null : 'Use 4–50 letters, digits, hyphens, dots or slashes.';
    }

    /** GS1 mod-10 check digit for the digits before it. */
    public static function checkDigit(string $digits): int
    {
        $sum = 0;
        $weight = 3;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $sum += (int) $digits[$i] * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }
        return (10 - $sum % 10) % 10;
    }
}
