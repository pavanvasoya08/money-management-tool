<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\App;
use App\Core\Database;
use App\Core\Paginator;

/**
 * Data access for `users`. The password hash is only selected where it is
 * actually needed (authentication); listings use SAFE_COLUMNS.
 */
final class User
{
    public const SAFE_COLUMNS = 'u.id, u.name, u.email, u.phone, u.status, u.must_change_password, u.failed_login_count, '
        . 'u.locked_until, u.last_login_at, u.last_login_ip, u.password_changed_at, u.deactivated_at, '
        . 'u.created_by, u.updated_by, u.created_at, u.updated_at, '
        . 'u.suspended_at, u.suspended_until, u.suspension_reason, u.suspended_by, u.account_expires_at, '
        . 'u.allowed_ips, u.access_start_time, u.access_end_time, u.deleted_at, u.deleted_by';

    private static function db(): Database
    {
        return App::db();
    }

    /** @return array<string, mixed>|null (without password hash) */
    public static function find(int $id): ?array
    {
        return self::db()->first('SELECT ' . self::SAFE_COLUMNS . ' FROM users u WHERE u.id = ?', [$id]);
    }

    /** @return array<string, mixed>|null including password_hash — authentication use only */
    public static function findForAuth(string $email): ?array
    {
        return self::db()->first('SELECT u.* FROM users u WHERE u.email = ?', [mb_strtolower(trim($email))]);
    }

    public static function passwordHash(int $id): ?string
    {
        $hash = self::db()->scalar('SELECT password_hash FROM users WHERE id = ?', [$id]);
        return is_string($hash) ? $hash : null;
    }

    /** @param array<string, mixed> $data */
    public static function create(array $data): int
    {
        $now = now();
        $data['email'] = mb_strtolower(trim((string) $data['email']));
        return self::db()->insert('users', $data + ['created_at' => $now, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $data */
    public static function update(int $id, array $data): void
    {
        if (isset($data['email'])) {
            $data['email'] = mb_strtolower(trim((string) $data['email']));
        }
        self::db()->update('users', $data + ['updated_at' => now()], ['id' => $id]);
    }

    /** @return list<array<string, mixed>> active roles of the user */
    public static function roles(int $userId): array
    {
        return self::db()->select(
            'SELECT r.id, r.name, r.slug, r.is_super_admin FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = ? AND r.is_active = 1 ORDER BY r.is_super_admin DESC, r.name',
            [$userId]
        );
    }

    /** @return list<int> */
    public static function roleIds(int $userId): array
    {
        return array_map('intval', array_column(self::db()->select('SELECT role_id FROM user_roles WHERE user_id = ?', [$userId]), 'role_id'));
    }

    /**
     * @param array{q?: string, status?: string, role?: string} $filters
     *   status: active | inactive | suspended | expired | deleted ('' = all non-deleted)
     * @param array{0: string, 1: list<mixed>}|null $scope record-level condition from DataAccess::constrain()
     */
    public static function paginate(array $filters, int $page, int $perPage, ?array $scope = null): Paginator
    {
        $where = ['1 = 1'];
        $params = [];
        if ($scope !== null) {
            $where[] = $scope[0];
            array_push($params, ...$scope[1]);
        }
        $now = now();
        $status = $filters['status'] ?? '';
        if ($status === 'deleted') {
            $where[] = 'u.deleted_at IS NOT NULL';
        } else {
            $where[] = 'u.deleted_at IS NULL';
        }
        if ($status === 'suspended') {
            $where[] = 'u.suspended_at IS NOT NULL AND (u.suspended_until IS NULL OR u.suspended_until > ?)';
            $params[] = $now;
        } elseif ($status === 'expired') {
            $where[] = 'u.account_expires_at IS NOT NULL AND u.account_expires_at <= ?';
            $params[] = $now;
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = "(u.name LIKE ? ESCAPE '!' OR u.email LIKE ? ESCAPE '!' OR u.phone LIKE ? ESCAPE '!')";
            $like = Database::likeContains($filters['q']);
            array_push($params, $like, $like, $like);
        }
        if (in_array($status, ['active', 'inactive'], true)) {
            $where[] = 'u.status = ?';
            $params[] = $status;
        }
        if (($filters['role'] ?? '') !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.slug = ?)';
            $params[] = $filters['role'];
        }
        $paginator = Paginator::query(self::db(), self::SAFE_COLUMNS, 'FROM users u WHERE ' . implode(' AND ', $where), 'u.name ASC, u.id ASC', $params, $page, $perPage);
        return self::withRoles($paginator);
    }

    private static function withRoles(Paginator $p): Paginator
    {
        if ($p->items === []) {
            return $p;
        }
        $ids = array_column($p->items, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = self::db()->select(
            "SELECT ur.user_id, r.name, r.slug, r.is_super_admin FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id IN ({$placeholders}) ORDER BY r.is_super_admin DESC, r.name",
            $ids
        );
        $byUser = [];
        foreach ($rows as $row) {
            $byUser[$row['user_id']][] = $row;
        }
        $items = array_map(static fn (array $u): array => $u + ['roles' => $byUser[$u['id']] ?? []], $p->items);
        return new Paginator($items, $p->total, $p->page, $p->perPage);
    }

    /**
     * Counts of non-deleted users (plus deleted separately).
     * @param array{0: string, 1: list<mixed>}|null $scope
     * @return array{total: int, active: int, inactive: int, locked: int, suspended: int, deleted: int}
     */
    public static function counts(?array $scope = null): array
    {
        $now = now();
        [$where, $params] = $scope ?? ['1 = 1', []];
        $row = self::db()->first(
            "SELECT SUM(CASE WHEN u.deleted_at IS NULL THEN 1 ELSE 0 END) AS total,
                    SUM(CASE WHEN u.deleted_at IS NULL AND u.status = 'active' THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN u.deleted_at IS NULL AND u.status = 'inactive' THEN 1 ELSE 0 END) AS inactive,
                    SUM(CASE WHEN u.deleted_at IS NULL AND u.locked_until IS NOT NULL AND u.locked_until > ? THEN 1 ELSE 0 END) AS locked,
                    SUM(CASE WHEN u.deleted_at IS NULL AND u.suspended_at IS NOT NULL AND (u.suspended_until IS NULL OR u.suspended_until > ?) THEN 1 ELSE 0 END) AS suspended,
                    SUM(CASE WHEN u.deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS deleted
             FROM users u WHERE {$where}",
            array_merge([$now, $now], $params)
        ) ?? [];
        return array_map('intval', [
            'total' => $row['total'] ?? 0, 'active' => $row['active'] ?? 0, 'inactive' => $row['inactive'] ?? 0,
            'locked' => $row['locked'] ?? 0, 'suspended' => $row['suspended'] ?? 0, 'deleted' => $row['deleted'] ?? 0,
        ]);
    }

    public static function countActiveSuperAdmins(?int $excludeUserId = null): int
    {
        $sql = "SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id = u.id
                JOIN roles r ON r.id = ur.role_id WHERE r.is_super_admin = 1 AND r.is_active = 1 AND u.status = 'active'
                AND u.deleted_at IS NULL AND (u.suspended_at IS NULL OR (u.suspended_until IS NOT NULL AND u.suspended_until <= ?))
                AND (u.account_expires_at IS NULL OR u.account_expires_at > ?)";
        $params = [now(), now()];
        if ($excludeUserId !== null) {
            $sql .= ' AND u.id <> ?';
            $params[] = $excludeUserId;
        }
        return (int) self::db()->scalar($sql, $params);
    }
}
