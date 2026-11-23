<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\App;

final class Role
{
    /** @return list<array<string, mixed>> */
    public static function allActive(): array
    {
        return App::db()->select(
            'SELECT r.id, r.name, r.slug, r.description, r.is_super_admin, r.is_system,
                    (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS user_count
             FROM roles r WHERE r.is_active = 1 ORDER BY r.is_super_admin DESC, r.name'
        );
    }

    /** @param list<int> $ids @return list<array<string, mixed>> */
    public static function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return App::db()->select("SELECT id, name, slug, is_super_admin, is_active FROM roles WHERE id IN ({$placeholders})", array_values($ids));
    }

    /** @return array<string, mixed>|null */
    public static function findBySlug(string $slug): ?array
    {
        return App::db()->first('SELECT * FROM roles WHERE slug = ?', [$slug]);
    }

    /** @return list<array{name: string, permissions: list<string>}> */
    public static function grantsSummary(): array
    {
        $rows = App::db()->select(
            'SELECT r.name, r.is_super_admin, p.slug FROM roles r
             LEFT JOIN role_permissions rp ON rp.role_id = r.id LEFT JOIN permissions p ON p.id = rp.permission_id
             WHERE r.is_active = 1 ORDER BY r.is_super_admin DESC, r.name, p.slug'
        );
        $out = [];
        foreach ($rows as $row) {
            $out[$row['name']] ??= ['name' => $row['name'], 'super' => (bool) $row['is_super_admin'], 'permissions' => []];
            if ($row['slug'] !== null) {
                $out[$row['name']]['permissions'][] = $row['slug'];
            }
        }
        return array_values($out);
    }
}
