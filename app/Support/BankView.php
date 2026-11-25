<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Authorization\FieldAccess;
use App\Services\Authorization\FieldPolicy;

/** View helpers for the bank pages: amounts under the "Balances" field rule. Returns escaped HTML. */
final class BankView
{
    public static function amount(int $paise, ?FieldAccess $access, bool $signed = false, string $class = ''): string
    {
        if ($access !== null && !$access->canView()) {
            return '<span class="text-secondary" title="Hidden by your field permissions">Hidden</span>';
        }
        if ($access !== null && !$access->showsRealValue()) {
            return '<span class="text-secondary" title="Masked by your field permissions">' . e(FieldPolicy::mask(Money::toDb($paise), 'amount')) . '</span>';
        }
        $cls = trim($class . ($paise < 0 ? ' text-danger' : ''));
        return '<span' . ($cls !== '' ? ' class="' . $cls . '"' : '') . '>' . e(($signed && $paise > 0 ? '+' : '') . Money::format($paise)) . '</span>';
    }

    /** Signed ledger amount: credits +, debits −. */
    public static function entry(string $direction, mixed $amount, ?FieldAccess $access): string
    {
        $p = Money::fromDb($amount);
        if ($access !== null && !$access->showsRealValue()) {
            return self::amount($p, $access);
        }
        return '<span class="' . ($direction === 'credit' ? 'text-success' : 'text-danger') . ' text-nowrap">' . ($direction === 'credit' ? '+' : '−') . e(Money::format($p)) . '</span>';
    }
}
