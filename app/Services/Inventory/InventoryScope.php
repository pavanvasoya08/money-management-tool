<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Core\App;
use App\Exceptions\HttpException;

/**
 * Which warehouses a user may see. Inventory records are warehouses (Phase 2 record scope:
 * all, own, assigned warehouses, GST registration); every stock figure, document and report
 * is limited to them. null = all warehouses.
 */
final class InventoryScope
{
    /** @var array<int, list<int>|null> */
    private static array $cache = [];

    /** @param array<string, mixed> $user @return list<int>|null */
    public static function warehouseIds(array $user): ?array
    {
        $uid = (int) ($user['id'] ?? 0);
        if (array_key_exists($uid, self::$cache)) {
            return self::$cache[$uid];
        }
        if (!App::gate()->allows($user, 'inventory.view')) {
            return self::$cache[$uid] = [];
        }
        [$w, $p] = App::dataAccess()->constrain($user, 'inventory', 'w');
        if ($w === '1 = 1') {
            return self::$cache[$uid] = null;
        }
        return self::$cache[$uid] = array_map('intval', array_column(App::db()->select("SELECT w.id FROM warehouses w WHERE {$w}", $p), 'id'));
    }

    /** SQL condition limiting $column to the user's warehouses. @param array<string, mixed> $user @return array{0: string, 1: list<int>} */
    public static function sql(array $user, string $column): array
    {
        $ids = self::warehouseIds($user);
        if ($ids === null) {
            return ['1 = 1', []];
        }
        return $ids === [] ? ['1 = 0', []] : [$column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }

    /** @param array<string, mixed> $user */
    public static function allows(array $user, int $warehouseId): bool
    {
        $ids = self::warehouseIds($user);
        return $ids === null || in_array($warehouseId, $ids, true);
    }

    /** 404 so warehouse existence is not revealed. @param array<string, mixed> $user */
    public static function assert(array $user, int $warehouseId): void
    {
        if (!self::allows($user, $warehouseId)) {
            throw new HttpException(404, 'Record not found.');
        }
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
