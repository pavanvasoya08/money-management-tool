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

    /**
     * Module data mapping from config/modules.php.
     * owner_sql   – "Own records" through a many-to-many link: `id IN (<owner_sql>)`; every `?` is bound to the user id
     *               (e.g. GST registrations mapped to the partner linked to this login, or created by this user).
     * columns_via – "Selected partners/…" through a link table: [type => sql with %s for the id placeholders],
     *               matched against the module's `id`.
     * Both are fixed SQL from configuration; only ids are bound as parameters.
     *
     * @return array{table: ?string, owner_column: ?string, owner_via: ?array{column: string, table: string, owner_column: string}, owner_sql: ?string, resource: ?string, resource_column: string, columns: array<string, string>, columns_via: array<string, string>}
     */
    public static function mapping(string $module): array
    {
        $data = (array) Config::get("modules.{$module}.data", []);
        return [
            'table' => $data['table'] ?? null,
            'owner_column' => $data['owner_column'] ?? null,
            'owner_via' => isset($data['owner_via']) ? (array) $data['owner_via'] : null,
            'owner_sql' => isset($data['owner_sql']) ? (string) $data['owner_sql'] : null,
            'resource' => $data['resource'] ?? null,
            'resource_column' => (string) ($data['resource_column'] ?? 'id'),
            'columns' => (array) ($data['columns'] ?? []),
            'columns_via' => (array) ($data['columns_via'] ?? []),
        ];
    }

    /** Whether a scope can be applied to a module's records (drives the scope pickers in the Control Center). */
    public static function supports(string $module, DataScope $scope): bool
    {
        $map = self::mapping($module);
        return match ($scope) {
            DataScope::Own => $map['owner_column'] !== null || $map['owner_via'] !== null || $map['owner_sql'] !== null,
            DataScope::Assigned => $map['resource'] !== null,
            DataScope::Selected => $map['table'] !== null,
            DataScope::All, DataScope::None => true,
            default => isset($map['columns'][(string) $scope->resourceType()]) || isset($map['columns_via'][(string) $scope->resourceType()]),
        };
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
        return $this->constrainAs($user, $module, $module, $alias);
    }

    /**
     * Apply the record scope a user has in $scopeModule to the table of
     * $tableModule. Used for partner-level reports of capital modules:
     * constrainAs($user, 'capital_ledger', 'partners', 'p') lists the partners
     * whose capital the user may see ("own" = partner linked to the user, etc.).
     * @param array<string, mixed> $user
     * @return array{0: string, 1: list<mixed>}
     */
    public function constrainAs(array $user, string $scopeModule, string $tableModule, string $alias = ''): array
    {
        $scope = $this->resolve($user, $scopeModule);
        if ($scope->all) {
            return ['1 = 1', []];
        }
        $map = self::mapping($tableModule);
        $col = fn (string $c): string => ($alias !== '' ? $alias . '.' : '') . $this->db->identifier($c);
        $parts = [];
        $params = [];
        foreach ($scope->rules as $rule) {
            $s = $rule['scope'];
            if ($s === DataScope::Own) {
                if ($map['owner_column'] !== null) {
                    $parts[] = $col($map['owner_column']) . ' = ?';
                    $params[] = (int) $user['id'];
                } elseif ($map['owner_via'] !== null) {
                    // e.g. capital transactions belong to the partner whose linked login is this user
                    $v = $map['owner_via'];
                    $parts[] = $col($v['column']) . ' IN (SELECT id FROM ' . $this->db->identifier($v['table']) . ' WHERE ' . $this->db->identifier($v['owner_column']) . ' = ?)';
                    $params[] = (int) $user['id'];
                } elseif ($map['owner_sql'] !== null) {
                    $parts[] = $col('id') . ' IN (' . $map['owner_sql'] . ')';
                    array_push($params, ...array_fill(0, substr_count($map['owner_sql'], '?'), (int) $user['id']));
                }
            } elseif ($s === DataScope::Assigned) {
                if ($map['resource'] !== null) {
                    $parts[] = $col($map['resource_column']) . ' IN (SELECT resource_id FROM user_resource_assignments WHERE user_id = ? AND resource_type = ?)';
                    array_push($params, (int) $user['id'], $map['resource']);
                }
            } elseif ($s === DataScope::Selected) {
                // Specific record IDs only make sense on the module's own table.
                if ($rule['ids'] !== [] && $scopeModule === $tableModule) {
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
                } elseif (isset($map['columns_via'][$type]) && $ids !== []) {
                    $parts[] = $col('id') . ' IN (' . sprintf($map['columns_via'][$type], implode(',', array_fill(0, count($ids), '?'))) . ')';
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
