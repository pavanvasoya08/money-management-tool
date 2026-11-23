<?php

declare(strict_types=1);

namespace App\Services\Authorization;

/**
 * Record-level visibility scopes (stored in role_data_scopes / user_data_scope_overrides).
 */
enum DataScope: string
{
    case All = 'all';
    case Own = 'own';
    case Assigned = 'assigned';
    case Selected = 'selected';
    case SelectedPartners = 'selected_partners';
    case SelectedGst = 'selected_gst_registrations';
    case SelectedBankAccounts = 'selected_bank_accounts';
    case SelectedPlatforms = 'selected_platforms';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All records',
            self::Own => 'Own records',
            self::Assigned => 'Assigned records',
            self::Selected => 'Selected records',
            self::SelectedPartners => 'Selected partners',
            self::SelectedGst => 'Selected GST registrations',
            self::SelectedBankAccounts => 'Selected bank accounts',
            self::SelectedPlatforms => 'Selected e-commerce platforms',
            self::None => 'No access',
        };
    }

    /** Resource type whose IDs a "selected …" scope filters by (null for other scopes). */
    public function resourceType(): ?string
    {
        return match ($this) {
            self::SelectedPartners => 'partner',
            self::SelectedGst => 'gst_registration',
            self::SelectedBankAccounts => 'bank_account',
            self::SelectedPlatforms => 'platform',
            default => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
