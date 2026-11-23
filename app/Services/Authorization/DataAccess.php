<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Exceptions\HttpException;

/**
 * Centralised RECORD-LEVEL access. Every module query must call constrain()
 * so unauthorized rows are never fetched (filtering happens in SQL, never in
 * the browser). Single records are checked with assertCanAccess().
 *
 * Effective scope for a user and module (deterministic):
 *   1. Super Admin                                    → All records
 *   2. No effective "<module>.view" permission        → No access
 *   3. User data-scope override (Data Visibility)     → exactly that scope (explicit
 *      user-level restrictions always win over roles)
 *   4. Otherwise, for EACH active role that grants "<module>.view":
 *        role data-scope rule for the module, if one exists; else
 *        derived from that role's actions: view_all → All, view_assigned → Assigned,
 *        view_own → Own (explicit user Deny on those actions removes them).
 *      Plus explicit user Allow overrides on view_all / view_assigned / view_own.
 *      The results of all roles are UNIONED (OR). Any "All" → All records.
 *   5. Nothing applies                                → No access (default deny)
 *
 * "Selected partners / GST / bank / platform" scopes use the IDs stored on the
 * rule; when none are stored they use the user's resource assignments of that
 * type — so a role can be a reusable template ("assigned GST registrations").
 */
final class DataAccess
{
    /** @var array<string, ResolvedScope> */
    private array $cache = [];

    public function __construct(private readonly Database $db, private readonly Gate $gate)
    {
    }

    /** @return array{table: ?string, owner_column: ?string, resource: ?string, columns: array<string, string>} */
    public static function mapping(string $module): array
    {
        $data = (array) Config::get("modules.{$module}.data", []);
        return [
            'table' => $data['table'] ?? null,
            'owner_column' => $data['owner_column'] ?? null,
            'resource' => $data['resource'] ?? null,
            'columns' => (array) ($data['columns'] ?? []),
        ];
    }

    /** @param array<string, mixed> $user */
    public function resolve(array $user, string $module): ResolvedScope
    {
        $key = $user['id'] . '|' . $module;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $userId = (int) $user['id'];

        if ($this->gate->isSuperAdmin($user) && AccountState::usable($user)) {
            return $this->cache[$key] = ResolvedScope::all('super_admin');
        }
        if (!$this->gate->allows($user, "{$module}.view")) {
            return $this->cache[$key] = ResolvedScope::none('no_view_permission');
        }
        $moduleId = $this->db->scalar('SELECT id FROM modules WHERE module_key = ?', [$module]);

        $override = $moduleId === null ? null : $this->db->first(
            'SELECT scope, resource_ids FROM user_data_scope_overrides WHERE user_id = ? AND module_id = ?',
            [$userId, $moduleId]
        );
        if ($override !== null) {
            $scope = DataScope::from($override['scope']);
            return $this->cache[$key] = match ($scope) {
                DataScope::All => ResolvedScope::all('user_override'),
                DataScope::None => ResolvedScope::none('user_override'),
                default => new ResolvedScope(false, [['scope' => $scope, 'ids' => self::ids($override['resource_ids']), 'source' => 'user override']], 'user_override'),
            };
        }

        $rules = [];
        $denied = $this->overrideEffects($userId, $module, 'deny');
        $roles = $this->db->select(
            "SELECT r.id, r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id AND r.is_active = 1
             JOIN role_permissions rp ON rp.role_id = r.id JOIN permissions p ON p.id = rp.permission_id AND p.slug = ?
             WHERE ur.user_id = ?",
            ["{$module}.view", $userId]
        );
        foreach ($roles as $role) {
            $row = $moduleId === null ? null : $this->db->first('SELECT scope, resource_ids FROM role_data_scopes WHERE role_id = ? AND module_id = ?', [$role['id'], $moduleId]);
            if ($row !== null) {
                $scope = DataScope::from($row['scope']);
                if ($scope === DataScope::All) {
                    return $this->cache[$key] = ResolvedScope::all('role: ' . $role['name']);
                }
                if ($scope !== DataScope::None) {
                    $rules[] = ['scope' => $scope, 'ids' => self::ids($row['resource_ids']), 'source' => 'role ' . $role['name']];
                }
                continue;
            }
            $actions = array_column($this->db->select(
                "SELECT p.action FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
                 WHERE rp.role_id = ? AND p.module = ? AND p.action IN ('view_all', 'view_assigned', 'view_own')",
                [$role['id'], $module]
            ), 'action');
            $actions = array_diff($actions, $denied);
            if (in_array('view_all', $actions, true)) {
                return $this->cache[$key] = ResolvedScope::all('role: ' . $role['name']);
            }
            foreach (['view_assigned' => DataScope::Assigned, 'view_own' => DataScope::Own] as $action => $scope) {
                if (in_array($action, $actions, true)) {
                    $rules[] = ['scope' => $scope, 'ids' => [], 'source' => 'role ' . $role['name']];
                }
            }
        }

        if ((bool) App::settings()->get('allow_user_level_grants', true)) {
            $allowed = $this->overrideEffects($userId, $module, 'allow');
            if (in_array('view_all', $allowed, true)) {
                return $this->cache[$key] = ResolvedScope::all('user allow');
            }
            foreach (['view_assigned' => DataScope::Assigned, 'view_own' => DataScope::Own] as $action => $scope) {
                if (in_array($action, $allowed, true)) {
                    $rules[] = ['scope' => $scope, 'ids' => [], 'source' => 'user allow'];
                }
            }
        }

        return $this->cache[$key] = $rules === [] ? ResolvedScope::none('default_deny') : new ResolvedScope(false, $rules, 'roles');
    }

    /**
     * SQL condition restricting a module's main table to the visible records.
     * Usage: [$where, $params] = $access->constrain($user, 'users', 'u');
     *
     * @param array<string, mixed> $user
     * @return array{0: string, 1: list<mixed>}
     */
    public function constrain(array $user, string $module, string $alias = ''): array
    {
        $scope = $this->resolve($user, $module);
        if ($scope->all) {
            return ['1 = 1', []];
        }
        $map = self::mapping($module);
        $col = fn (string $c): string => ($alias !== '' ? $alias . '.' : '') . $this->db->identifier($c);
        $parts = [];
        $params = [];
        foreach ($scope->rules as $rule) {
            $s = $rule['scope'];
            if ($s === DataScope::Own) {
                if ($map['owner_column'] !== null) {
                    $parts[] = $col($map['owner_column']) . ' = ?';
                    $params[] = (int) $user['id'];
                }
            } elseif ($s === DataScope::Assigned) {
                if ($map['resource'] !== null) {
                    $parts[] = $col('id') . ' IN (SELECT resource_id FROM user_resource_assignments WHERE user_id = ? AND resource_type = ?)';
                    array_push($params, (int) $user['id'], $map['resource']);
                }
            } elseif ($s === DataScope::Selected) {
                if ($rule['ids'] !== []) {
                    $parts[] = $col('id') . ' IN (' . implode(',', array_fill(0, count($rule['ids']), '?')) . ')';
                    array_push($params, ...$rule['ids']);
                }
            } else {
                $type = (string) $s->resourceType();
                $column = $map['columns'][$type] ?? null;
                $ids = $rule['ids'] !== [] ? $rule['ids'] : $this->assignedIds((int) $user['id'], $type);
                if ($column !== null && $ids !== []) {
                    $parts[] = $col($column) . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                    array_push($params, ...$ids);
                }
            }
        }
        return $parts === [] ? ['1 = 0', []] : ['(' . implode(' OR ', $parts) . ')', $params];
    }

    /** @param array<string, mixed> $user */
    public function canAccess(array $user, string $module, int $recordId): bool
    {
        $map = self::mapping($module);
        if ($map['table'] === null) {
            return !$this->resolve($user, $module)->isNone();
        }
        [$where, $params] = $this->constrain($user, $module, 't');
        return (bool) $this->db->scalar(
            'SELECT 1 FROM ' . $this->db->identifier($map['table']) . " t WHERE t.id = ? AND {$where}",
            array_merge([$recordId], $params)
        );
    }

    /** 404 (not 403) so record existence is not revealed. @param array<string, mixed> $user */
    public function assertCanAccess(array $user, string $module, int $recordId): void
    {
        if (!$this->canAccess($user, $module, $recordId)) {
            throw new HttpException(404, 'Record not found.');
        }
    }

    /**
     * For modules without their own table (reports): which IDs of a resource
     * type may the user see? null = unrestricted, [] = none.
     *
     * @param array<string, mixed> $user
     * @return list<int>|null
     */
    public function allowedResourceIds(array $user, string $module, string $resourceType): ?array
    {
        $scope = $this->resolve($user, $module);
        if ($scope->all) {
            return null;
        }
        $ids = [];
        foreach ($scope->rules as $rule) {
            if ($rule['scope']->resourceType() === $resourceType) {
                $ids = array_merge($ids, $rule['ids'] !== [] ? $rule['ids'] : $this->assignedIds((int) $user['id'], $resourceType));
            } elseif ($rule['scope'] === DataScope::Assigned && self::mapping($module)['resource'] === $resourceType) {
                $ids = array_merge($ids, $this->assignedIds((int) $user['id'], $resourceType));
            }
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** @return list<int> */
    public function assignedIds(int $userId, string $type): array
    {
        return array_map('intval', array_column(
            $this->db->select('SELECT resource_id FROM user_resource_assignments WHERE user_id = ? AND resource_type = ?', [$userId, $type]),
            'resource_id'
        ));
    }

    /** @return list<string> actions with an active override of the given effect */
    private function overrideEffects(int $userId, string $module, string $effect): array
    {
        return array_column($this->db->select(
            "SELECT p.action FROM user_permission_overrides o JOIN permissions p ON p.id = o.permission_id
             WHERE o.user_id = ? AND p.module = ? AND o.effect = ? AND (o.expires_at IS NULL OR o.expires_at > ?)
             AND p.action IN ('view_all', 'view_assigned', 'view_own')",
            [$userId, $module, $effect, now()]
        ), 'action');
    }

    /** @return list<int> */
    private static function ids(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? array_values(array_unique(array_map('intval', array_filter($data, 'is_numeric')))) : [];
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
