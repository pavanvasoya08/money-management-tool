<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Ecommerce\PlatformService;

/** Small presentation helpers for the e-commerce pages. */
final class EcommerceView
{
    private const TONES = ['active' => 'success', 'inactive' => 'warning', 'under_configuration' => 'info', 'archived' => 'secondary',
        'not_connected' => 'secondary', 'connected' => 'success', 'disconnected' => 'warning', 'error' => 'danger',
        'operational' => 'success', 'planned' => 'info', 'not_available' => 'secondary', 'supported' => 'success', 'not_supported' => 'secondary', 'unknown' => 'secondary'];

    public static function badge(string $status, ?array $labels = null): string
    {
        $labels ??= PlatformService::STATUSES + PlatformService::CONNECTION;
        return '<span class="badge rounded-pill badge-soft-' . (self::TONES[$status] ?? 'secondary') . '">' . e($labels[$status] ?? ucfirst(str_replace('_', ' ', $status))) . '</span>';
    }

    /** Logo image or an initials avatar. @param array<string, mixed> $p */
    public static function logo(array $p, string $size = ''): string
    {
        $cls = 'avatar platform-logo' . ($size !== '' ? ' avatar-' . $size : '');
        if (!empty($p['logo_stored_name'])) {
            return '<span class="' . $cls . ' has-image"><img src="' . e(route('ecommerce.platforms.logo', ['id' => $p['id'], 'v' => substr((string) $p['logo_stored_name'], 0, 8)])) . '" alt="' . e($p['name'] . ' logo') . '" loading="lazy"></span>';
        }
        $initials = strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $p['name']) ?: 'P', 0, 2));
        return '<span class="' . $cls . ' tone-blue" aria-hidden="true">' . e($initials) . '</span>';
    }

    /** "60 % configured" progress. @param array{percent: int, complete: bool} $r */
    public static function readiness(array $r): string
    {
        $tone = $r['complete'] ? 'success' : ($r['percent'] >= 50 ? 'info' : 'warning');
        return '<span class="d-inline-flex align-items-center gap-2"><span class="progress progress-thin readiness-bar" role="presentation"><span class="progress-bar bg-' . $tone . '" data-width="' . max(2, $r['percent']) . '"></span></span><span class="small">' . $r['percent'] . '%</span></span>';
    }
}
