<?php

declare(strict_types=1);

namespace App\Support;

/** Reference lists for platform configuration: countries, currencies, languages, date and number formats. */
final class Locale
{
    public const COUNTRIES = [
        'IN' => 'India', 'AE' => 'United Arab Emirates', 'AU' => 'Australia', 'BD' => 'Bangladesh', 'BR' => 'Brazil', 'CA' => 'Canada', 'CN' => 'China', 'DE' => 'Germany',
        'EG' => 'Egypt', 'ES' => 'Spain', 'FR' => 'France', 'GB' => 'United Kingdom', 'HK' => 'Hong Kong', 'ID' => 'Indonesia', 'IT' => 'Italy', 'JP' => 'Japan',
        'KE' => 'Kenya', 'KR' => 'South Korea', 'LK' => 'Sri Lanka', 'MX' => 'Mexico', 'MY' => 'Malaysia', 'NG' => 'Nigeria', 'NL' => 'Netherlands', 'NP' => 'Nepal',
        'NZ' => 'New Zealand', 'OM' => 'Oman', 'PH' => 'Philippines', 'PK' => 'Pakistan', 'PL' => 'Poland', 'QA' => 'Qatar', 'SA' => 'Saudi Arabia', 'SE' => 'Sweden',
        'SG' => 'Singapore', 'TH' => 'Thailand', 'TR' => 'Türkiye', 'US' => 'United States', 'VN' => 'Vietnam', 'ZA' => 'South Africa', 'XX' => 'Global / several countries',
    ];
    public const CURRENCIES = [
        'INR' => 'Indian rupee', 'USD' => 'US dollar', 'EUR' => 'Euro', 'GBP' => 'Pound sterling', 'AED' => 'UAE dirham', 'AUD' => 'Australian dollar', 'BDT' => 'Bangladeshi taka',
        'CAD' => 'Canadian dollar', 'CHF' => 'Swiss franc', 'CNY' => 'Chinese yuan', 'HKD' => 'Hong Kong dollar', 'IDR' => 'Indonesian rupiah', 'JPY' => 'Japanese yen',
        'KRW' => 'South Korean won', 'LKR' => 'Sri Lankan rupee', 'MYR' => 'Malaysian ringgit', 'NPR' => 'Nepalese rupee', 'NZD' => 'New Zealand dollar', 'OMR' => 'Omani rial',
        'PHP' => 'Philippine peso', 'QAR' => 'Qatari riyal', 'SAR' => 'Saudi riyal', 'SEK' => 'Swedish krona', 'SGD' => 'Singapore dollar', 'THB' => 'Thai baht', 'ZAR' => 'South African rand',
    ];
    public const LANGUAGES = [
        'en' => 'English', 'hi' => 'Hindi', 'bn' => 'Bengali', 'gu' => 'Gujarati', 'kn' => 'Kannada', 'ml' => 'Malayalam', 'mr' => 'Marathi', 'or' => 'Odia', 'pa' => 'Punjabi',
        'ta' => 'Tamil', 'te' => 'Telugu', 'ur' => 'Urdu', 'ar' => 'Arabic', 'de' => 'German', 'es' => 'Spanish', 'fr' => 'French', 'it' => 'Italian', 'ja' => 'Japanese',
        'ko' => 'Korean', 'nl' => 'Dutch', 'pt' => 'Portuguese', 'zh' => 'Chinese',
    ];
    /** PHP date() pattern => example */
    public const DATE_FORMATS = ['d-m-Y' => '31-12-2026', 'd/m/Y' => '31/12/2026', 'Y-m-d' => '2026-12-31', 'm/d/Y' => '12/31/2026', 'd M Y' => '31 Dec 2026', 'M d, Y' => 'Dec 31, 2026'];
    public const NUMBER_FORMATS = ['en_IN' => '12,34,567.89 (Indian)', 'en_US' => '1,234,567.89 (international)', 'de_DE' => '1.234.567,89 (European)', 'fr_FR' => '1 234 567,89 (space-grouped)'];

    /** @return list<string> */
    public static function codes(string $csv): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $csv)), static fn (string $c): bool => $c !== '')));
    }

    /** @param array<string, string> $list */
    public static function names(string $csv, array $list): string
    {
        return implode(', ', array_map(static fn (string $c): string => $list[$c] ?? $c, self::codes($csv)));
    }
}
