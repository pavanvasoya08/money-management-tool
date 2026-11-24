<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/** Business dates (Y-m-d) in the configured display timezone, not UTC. */
final class BusinessDate
{
    public static function today(): string
    {
        return (new DateTimeImmutable('now', self::tz()))->format('Y-m-d');
    }

    public static function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date, self::tz()))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /** A real calendar date in Y-m-d form (rejects 2026-02-30, 26-1-1, etc.). */
    public static function isValid(string $date): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    public static function format(?string $date): string
    {
        if ($date === null || $date === '') {
            return '—';
        }
        $fmt = (string) setting('date_format', 'd M Y');
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10), self::tz());
        return $d === false ? $date : $d->format($fmt);
    }

    private static function tz(): DateTimeZone
    {
        try {
            return new DateTimeZone((string) setting('timezone', 'Asia/Kolkata'));
        } catch (\Throwable) {
            return new DateTimeZone('Asia/Kolkata');
        }
    }
}
