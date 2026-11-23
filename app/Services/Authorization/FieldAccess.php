<?php

declare(strict_types=1);

namespace App\Services\Authorization;

/**
 * Field-level access, from most to least restrictive:
 *   hidden    — never sent to the browser, never exported
 *   masked    — partially shown (e.g. XXXXXX1234), never editable
 *   visible   — shown in lists and detail pages, not editable
 *   read_only — shown everywhere including forms (locked), not editable
 *   editable  — shown and editable (the module's "edit" permission is still required)
 */
enum FieldAccess: string
{
    case Hidden = 'hidden';
    case Masked = 'masked';
    case Visible = 'visible';
    case ReadOnly = 'read_only';
    case Editable = 'editable';

    public function rank(): int
    {
        return match ($this) {
            self::Hidden => 0,
            self::Masked => 1,
            self::Visible => 2,
            self::ReadOnly => 3,
            self::Editable => 4,
        };
    }

    public function canView(): bool
    {
        return $this !== self::Hidden;
    }

    public function showsRealValue(): bool
    {
        return $this->rank() >= 2;
    }

    public function canEdit(): bool
    {
        return $this === self::Editable;
    }

    public function label(): string
    {
        return match ($this) {
            self::Hidden => 'Hidden',
            self::Masked => 'Masked',
            self::Visible => 'Visible',
            self::ReadOnly => 'Read only',
            self::Editable => 'Editable',
        };
    }

    public static function widest(self $a, self $b): self
    {
        return $a->rank() >= $b->rank() ? $a : $b;
    }
}
