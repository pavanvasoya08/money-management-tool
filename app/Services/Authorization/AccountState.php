<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use DateTimeImmutable;
use Throwable;

/**
 * Computes whether an account may be used right now, combining status,
 * soft deletion, suspension, expiry and access restrictions (IP / hours).
 */
final class AccountState
{
    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';
    public const SUSPENDED = 'suspended';
    public const EXPIRED = 'expired';
    public const DELETED = 'deleted';

    /** @param array<string, mixed> $user */
    public static function of(array $user): string
    {
        $now = now();
        if (!empty($user['deleted_at'])) {
            return self::DELETED;
        }
        if (!empty($user['suspended_at']) && (empty($user['suspended_until']) || $user['suspended_until'] > $now)) {
            return self::SUSPENDED;
        }
        if (!empty($user['account_expires_at']) && $user['account_expires_at'] <= $now) {
            return self::EXPIRED;
        }
        return ($user['status'] ?? '') === 'active' ? self::ACTIVE : self::INACTIVE;
    }

    /** @param array<string, mixed> $user */
    public static function usable(array $user): bool
    {
        return self::of($user) === self::ACTIVE;
    }

    /** Human message for a non-usable state (shown only after a correct password). @param array<string, mixed> $user */
    public static function message(array $user): string
    {
        return match (self::of($user)) {
            self::SUSPENDED => 'Your account is suspended' . (!empty($user['suspended_until']) ? ' until ' . format_datetime((string) $user['suspended_until']) : '') . '. Please contact your administrator.',
            self::EXPIRED => 'Your account access has expired. Please contact your administrator.',
            self::INACTIVE => 'Your account is inactive. Please contact your administrator.',
            default => 'Your account cannot be used.',
        };
    }

    /**
     * Access restrictions configured per user. Returns a refusal message or null when allowed.
     * @param array<string, mixed> $user
     */
    public static function restrictionFor(array $user, string $ip): ?string
    {
        $allowed = trim((string) ($user['allowed_ips'] ?? ''));
        if ($allowed !== '' && !self::ipAllowed($ip, $allowed)) {
            return 'Sign-in is not allowed from your current network. Please contact your administrator.';
        }
        $start = (string) ($user['access_start_time'] ?? '');
        $end = (string) ($user['access_end_time'] ?? '');
        if ($start !== '' && $end !== '' && !self::withinHours($start, $end)) {
            return "Access to your account is allowed only between {$start} and {$end}.";
        }
        return null;
    }

    public static function withinHours(string $start, string $end, ?DateTimeImmutable $at = null): bool
    {
        $now = ($at ?? new DateTimeImmutable('now', display_tz()))->format('H:i');
        return $start <= $end ? ($now >= $start && $now < $end) : ($now >= $start || $now < $end); // supports overnight windows
    }

    /** Comma/newline-separated IPs or CIDR ranges (IPv4 and IPv6). */
    public static function ipAllowed(string $ip, string $list): bool
    {
        foreach (preg_split('/[\s,]+/', $list) ?: [] as $entry) {
            if ($entry !== '' && self::matches($ip, $entry)) {
                return true;
            }
        }
        return false;
    }

    public static function matches(string $ip, string $entry): bool
    {
        if (!str_contains($entry, '/')) {
            return strcasecmp($ip, $entry) === 0;
        }
        [$subnet, $bits] = explode('/', $entry, 2);
        try {
            $ipBin = @inet_pton($ip);
            $subnetBin = @inet_pton($subnet);
        } catch (Throwable) {
            return false;
        }
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin) || !ctype_digit($bits)) {
            return false;
        }
        $bits = (int) $bits;
        if ($bits > strlen($ipBin) * 8) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    /** Validate an allow-list string; returns the invalid entries. @return list<string> */
    public static function invalidEntries(string $list): array
    {
        $bad = [];
        foreach (preg_split('/[\s,]+/', trim($list)) ?: [] as $entry) {
            if ($entry === '') {
                continue;
            }
            [$addr, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            $ok = filter_var($addr, FILTER_VALIDATE_IP) !== false
                && ($bits === null || (ctype_digit($bits) && (int) $bits <= (str_contains((string) $addr, ':') ? 128 : 32)));
            if (!$ok) {
                $bad[] = $entry;
            }
        }
        return $bad;
    }

    public static function label(string $state): string
    {
        return ucfirst($state);
    }
}
