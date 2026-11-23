<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\App;

/**
 * Read-only queries that feed the Control Center screens (matrix, grids,
 * preview). No authorization decisions are made here — see Gate/DataAccess/FieldPolicy.
 */
final class Catalog
{
    /** Standard action columns of the permission matrix, in display order. */
    public const COLUMNS = [
        'view' => 'View', 'view_all' => 'All', 'view_own' => 'Own', 'view_assigned' => 'Assigned',
        'create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete', 'approve' => 'Approve', 'reject' => 'Reject',
        'export' => 'Export', 'download' => 'Download', 'print' => 'Print', 'import' => 'Import', 'manage_settings' => 'Settings',
    ];

    /** High-impact actions that trigger an extra confirmation when granted. */
    public const HIGH_IMPACT = ['delete', 'approve', 'manage_settings', 'import', 'assign_roles', 'reset_password', 'force_logout', 'restore', 'close_period', 'transfer'];

    /** @return list<array<string, mixed>> */
    public static function modules(): array
    {
        return App::db()->select('SELECT * FROM modules ORDER BY sort_order, id');
    }

    /**
     * Modules with their permissions split into standard columns and module-specific extras.
     * @return list<array{module: array<string, mixed>, columns: array<string, array<string, mixed>>, extras: list<array<string, mixed>>}>
     */
    public static function matrix(): array
    {
        $byModule = [];
        foreach (App::db()->select('SELECT * FROM permissions WHERE module_id IS NOT NULL ORDER BY sort_order, id') as $p) {
            $byModule[(int) $p['module_id']][] = $p;
        }
        $out = [];
        foreach (self::modules() as $m) {
            $columns = [];
            $extras = [];
            foreach ($byModule[(int) $m['id']] ?? [] as $p) {
                if (isset(self::COLUMNS[$p['action']])) {
                    $columns[$p['action']] = $p;
                } else {
                    $extras[] = $p;
                }
            }
            $out[] = ['module' => $m, 'columns' => $columns, 'extras' => $extras];
        }
        return $out;
    }

    /** @return list<int> */
    public static function rolePermissionIds(int $roleId): array
    {
        return array_map('intval', array_column(App::db()->select('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$roleId]), 'permission_id'));
    }

    /** @return list<array<string, mixed>> */
    public static function roles(): array
    {
        return App::db()->select(
            'SELECT r.*, (SELECT COUNT(*) FROM user_roles ur JOIN users u ON u.id = ur.user_id AND u.deleted_at IS NULL WHERE ur.role_id = r.id) AS user_count,
                    (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count
             FROM roles r ORDER BY r.is_super_admin DESC, r.is_system DESC, r.name'
        );
    }

    /** permission_id => effect for a user's overrides. @return array<int, array<string, mixed>> */
    public static function userOverrides(int $userId): array
    {
        $out = [];
        foreach (App::db()->select('SELECT * FROM user_permission_overrides WHERE user_id = ?', [$userId]) as $o) {
            $out[(int) $o['permission_id']] = $o;
        }
        return $out;
    }

    /** module_key => row. @return array<string, array<string, mixed>> */
    public static function roleScopes(int $roleId): array
    {
        $out = [];
        foreach (App::db()->select('SELECT s.*, m.module_key FROM role_data_scopes s JOIN modules m ON m.id = s.module_id WHERE s.role_id = ?', [$roleId]) as $s) {
            $out[$s['module_key']] = $s;
        }
        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    public static function userScopes(int $userId): array
    {
        $out = [];
        foreach (App::db()->select('SELECT s.*, m.module_key FROM user_data_scope_overrides s JOIN modules m ON m.id = s.module_id WHERE s.user_id = ?', [$userId]) as $s) {
            $out[$s['module_key']] = $s;
        }
        return $out;
    }

    /** Modules that hold records (have a data mapping). @return list<array<string, mixed>> */
    public static function recordModules(): array
    {
        return array_values(array_filter(self::modules(), static fn (array $m): bool => (bool) \App\Core\Config::get("modules.{$m['module_key']}.data")));
    }

    /** @return list<array<string, mixed>> fields with module key/name */
    public static function fields(?string $moduleKey = null): array
    {
        $sql = 'SELECT f.*, m.module_key, m.name AS module_name FROM module_fields f JOIN modules m ON m.id = f.module_id';
        $params = [];
        if ($moduleKey !== null) {
            $sql .= ' WHERE m.module_key = ?';
            $params[] = $moduleKey;
        }
        return App::db()->select($sql . ' ORDER BY m.sort_order, f.sort_order', $params);
    }

    /** field id => access. @return array<int, string> */
    public static function roleFieldRules(int $roleId): array
    {
        $out = [];
        foreach (App::db()->select('SELECT field_id, access FROM role_field_permissions WHERE role_id = ?', [$roleId]) as $r) {
            $out[(int) $r['field_id']] = $r['access'];
        }
        return $out;
    }

    /** @return array<int, string> */
    public static function userFieldRules(int $userId): array
    {
        $out = [];
        foreach (App::db()->select('SELECT field_id, access FROM user_field_overrides WHERE user_id = ?', [$userId]) as $r) {
            $out[(int) $r['field_id']] = $r['access'];
        }
        return $out;
    }

    /** Overview widgets — all counts from real rows. @return array<string, int> */
    public static function stats(): array
    {
        $db = App::db();
        $counts = \App\Models\User::counts();
        $now = now();
        return [
            'users_total' => $counts['total'],
            'users_active' => $counts['active'],
            'users_inactive' => $counts['inactive'],
            'users_suspended' => $counts['suspended'],
            'roles_total' => (int) $db->scalar('SELECT COUNT(*) FROM roles'),
            'roles_custom' => (int) $db->scalar('SELECT COUNT(*) FROM roles WHERE is_system = 0'),
            'assignments' => (int) $db->scalar('SELECT COUNT(*) FROM role_permissions') + (int) $db->scalar('SELECT COUNT(*) FROM user_permission_overrides WHERE expires_at IS NULL OR expires_at > ?', [$now]),
            'restricted_users' => (int) $db->scalar(
                "SELECT COUNT(*) FROM users u WHERE u.deleted_at IS NULL AND (
                    EXISTS (SELECT 1 FROM user_permission_overrides o WHERE o.user_id = u.id AND o.effect = 'deny' AND (o.expires_at IS NULL OR o.expires_at > ?))
                    OR EXISTS (SELECT 1 FROM user_data_scope_overrides d WHERE d.user_id = u.id)
                    OR EXISTS (SELECT 1 FROM user_field_overrides f WHERE f.user_id = u.id)
                    OR (u.allowed_ips IS NOT NULL AND u.allowed_ips <> '') OR u.access_start_time IS NOT NULL
                    OR u.account_expires_at IS NOT NULL
                    OR (u.suspended_at IS NOT NULL AND (u.suspended_until IS NULL OR u.suspended_until > ?)))",
                [$now, $now]
            ),
            'pending_access_requests' => (int) $db->scalar("SELECT COUNT(*) FROM access_requests WHERE status = 'pending'"),
            'pending_approvals' => (int) $db->scalar("SELECT COUNT(*) FROM approval_requests WHERE status = 'pending'"),
            'active_sessions' => App::sessions()->countActive(),
            'active_workflows' => (int) $db->scalar('SELECT COUNT(*) FROM approval_workflows WHERE is_active = 1'),
        ];
    }

    /** Recent audit entries for given action prefixes. @param list<string> $prefixes @return list<array<string, mixed>> */
    public static function recentAudit(array $prefixes, int $limit = 8): array
    {
        $where = implode(' OR ', array_fill(0, count($prefixes), "a.action LIKE ? ESCAPE '!'"));
        $params = array_map(static fn (string $p): string => \App\Core\Database::likeEscape($p) . '%', $prefixes);
        return App::db()->select(
            "SELECT a.*, u.name AS user_name, au.name AS affected_user_name, r.name AS affected_role_name FROM activity_logs a
             LEFT JOIN users u ON u.id = a.user_id LEFT JOIN users au ON au.id = a.affected_user_id LEFT JOIN roles r ON r.id = a.affected_role_id
             WHERE ({$where}) ORDER BY a.created_at DESC, a.id DESC LIMIT " . max(1, min(50, $limit)),
            $params
        );
    }
}
