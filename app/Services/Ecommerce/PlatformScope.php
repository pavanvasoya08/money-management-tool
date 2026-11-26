<?php

declare(strict_types=1);

namespace App\Services\Ecommerce;

use App\Core\App;
use App\Exceptions\HttpException;

/**
 * Which platforms a user may see (Phase 2 record scope of the "platforms" module: all, own,
 * assigned platforms or GST registration). null = all platforms.
 */
final class PlatformScope
{
    /** @var array<int, list<int>|null> */
    private static array $cache = [];

    /** @param array<string, mixed> $user @return list<int>|null */
    public static function ids(array $user): ?array
    {
        $uid = (int) ($user['id'] ?? 0);
        if (array_key_exists($uid, self::$cache)) {
            return self::$cache[$uid];
        }
        if (!App::gate()->allows($user, 'platforms.view') && !App::gate()->allows($user, 'platforms.view_dashboard')) {
            return self::$cache[$uid] = [];
        }
        [$w, $p] = App::dataAccess()->constrain($user, 'platforms', 'ep');
        if ($w === '1 = 1') {
            return self::$cache[$uid] = null;
        }
        return self::$cache[$uid] = array_map('intval', array_column(App::db()->select("SELECT ep.id FROM ecommerce_platforms ep WHERE {$w}", $p), 'id'));
    }

    /** @param array<string, mixed> $user @return array{0: string, 1: list<int>} */
    public static function sql(array $user, string $column): array
    {
        $ids = self::ids($user);
        if ($ids === null) {
            return ['1 = 1', []];
        }
        return $ids === [] ? ['1 = 0', []] : [$column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }

    /** @param array<string, mixed> $user */
    public static function allows(array $user, int $platformId): bool
    {
        $ids = self::ids($user);
        return $ids === null || in_array($platformId, $ids, true);
    }

    /** 404 so a platform outside the scope is not revealed. @param array<string, mixed> $user */
    public static function assert(array $user, int $platformId): void
    {
        if (!self::allows($user, $platformId)) {
            throw new HttpException(404, 'Platform not found.');
        }
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
