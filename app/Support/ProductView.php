<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Authorization\FieldAccess;
use App\Services\Authorization\FieldPolicy;

/** View helpers for product and supplier pages: prices honouring field rules, and value lists for history. */
final class ProductView
{
    /** A price in its currency, hidden or masked by the user's field rule. */
    public static function price(?int $paise, string $currency, ?FieldAccess $access): string
    {
        if ($paise === null) {
            return '<span class="text-secondary">—</span>';
        }
        if ($currency === 'INR' || $currency === '') {
            return BankView::amount($paise, $access);
        }
        if ($access !== null && !$access->canView()) {
            return '<span class="text-secondary" title="Hidden by your field permissions">Hidden</span>';
        }
        if ($access !== null && !$access->showsRealValue()) {
            return '<span class="text-secondary" title="Masked by your field permissions">' . e(FieldPolicy::mask(Money::toDb($paise), 'amount')) . '</span>';
        }
        return '<span>' . e($currency . ' ' . number_format($paise / 100, 2)) . '</span>';
    }

    /** Key: value lines for history / audit values. @param array<string, mixed>|null $values */
    public static function values(?array $values): string
    {
        if ($values === null || $values === []) {
            return '—';
        }
        $out = [];
        foreach ($values as $k => $v) {
            $out[] = e(str_replace('_', ' ', (string) $k)) . ': <b>' . e(is_scalar($v) || $v === null ? (is_bool($v) ? ($v ? 'yes' : 'no') : (string) ($v ?? '—')) : json_encode($v, JSON_UNESCAPED_UNICODE)) . '</b>';
        }
        return implode('<br>', $out);
    }

    /** Quantity without trailing zeros (12.500 → 12.5). */
    public static function qty(mixed $q): string
    {
        return $q === null || $q === '' ? '' : rtrim(rtrim(number_format((float) $q, 3, '.', ''), '0'), '.');
    }
}
