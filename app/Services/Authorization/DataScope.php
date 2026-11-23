<?php

declare(strict_types=1);

namespace App\Services\Authorization;

/**
 * Record-level visibility for a permission grant (Phase 2 applies it to queries).
 *  all      — every record
 *  selected — only records explicitly listed in the grant's constraints
 *  assigned — records assigned to the user
 *  own      — records the user created/owns
 */
enum DataScope: string
{
    case All = 'all';
    case Selected = 'selected';
    case Assigned = 'assigned';
    case Own = 'own';

    /** Higher rank = wider access. Used when several roles grant the same permission. */
    public function rank(): int
    {
        return match ($this) {
            self::All => 4,
            self::Selected => 3,
            self::Assigned => 2,
            self::Own => 1,
        };
    }

    public static function widest(self $a, self $b): self
    {
        return $a->rank() >= $b->rank() ? $a : $b;
    }
}
