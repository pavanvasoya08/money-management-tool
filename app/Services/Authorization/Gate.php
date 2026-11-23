<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Exceptions\AuthorizationException;
use Closure;

/**
 * Centralised authorization — the ONLY place that decides whether a user may
 * perform a module action. Controllers, middleware, views, exports and APIs
 * all ask the Gate; nothing compares role names.
 *
 * Effective permission resolution (deterministic):
 *   1. Account not usable (inactive, suspended, expired, deleted) → DENY everything.
 *   2. Super Admin role                                      → ALLOW everything.
 *   3. Module disabled by the Super Admin, or reserved for Super Admins → DENY.
 *   4. Explicit user DENY override                           → DENY (beats every role).
 *   5. Explicit user ALLOW override (when the Super Admin has enabled
 *      "Allow explicit per-user grants")                     → ALLOW.
 *   6. Any active role grants the permission (union of roles) → ALLOW.
 *   7. Otherwise                                             → DENY (default deny).
 *   Expired overrides are ignored ("Inherit").
 *   An optional resource policy can further restrict a specific record.
 *
 * Record visibility: DataAccess. Field visibility: FieldPolicy.
 */
final class Gate
{
    /** @var array<int, array{super: bool, grants: array<string, string>, roles: array<string, list<string>>, overrides: array<string, string>}> */
    private array $cache = [];
    /** @var array<string, Closure(array<string, mixed>, mixed): bool> */
    private array $policies = [];
    /** @var array<string, array{active: bool, super_only: bool}>|null */
    private ?array $modules = null;

    public function __construct(private readonly Database $db)
    {
        $this->registerCorePolicies();
    }

    /** @param Closure(array<string, mixed> $user, mixed $resource): bool $policy */
    public function define(string $permission, Closure $policy): void
    {
        $this->policies[$permission] = $policy;
    }

    /** @param array<string, mixed> $user */
    public function allows(array $user, string $permission, mixed $resource = null): bool
    {
        if (!AccountState::usable($user)) {
            return false;
        }
        if ($this->isSuperAdmin($user)) {
            return true;
        }
        $module = strtok($permission, '.') ?: '';
        $state = $this->moduleState($module);
        if ($state !== null && (!$state['active'] || $state['super_only'])) {
            return false;
        }
        if (!isset($this->resolve((int) $user['id'])['grants'][$permission])) {
            return false;
        }
        if ($resource !== null && isset($this->policies[$permission])) {
            return ($this->policies[$permission])($user, $resource);
        }
        return true;
    }

    /** @param array<string, mixed> $user */
    public function denies(array $user, string $permission, mixed $resource = null): bool
    {
        return !$this->allows($user, $permission, $resource);
    }

    /** @param array<string, mixed>|null $user */
    public function authorize(?array $user, string $permission, mixed $resource = null): void
    {
        if ($user === null || !$this->allows($user, $permission, $resource)) {
            throw new AuthorizationException($permission);
        }
    }

    /** True if the user holds at least one of the given permissions. @param array<string, mixed> $user @param list<string> $permissions */
    public function any(array $user, array $permissions): bool
    {
        foreach ($permissions as $p) {
            if ($this->allows($user, $p)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed>|int $user */
    public function isSuperAdmin(array|int $user): bool
    {
        $id = is_array($user) ? (int) $user['id'] : $user;
        return $this->resolve($id)['super'];
    }

    /**
     * Why is a permission allowed or denied? Used by the effective-permission views and preview.
     * @param array<string, mixed> $user
     * @return array{allowed: bool, source: string, detail: string}
     */
    public function explain(array $user, string $permission): array
    {
        if (!AccountState::usable($user)) {
            return ['allowed' => false, 'source' => 'account', 'detail' => 'Account is ' . AccountState::of($user)];
        }
        $r = $this->resolve((int) $user['id']);
        if ($r['super']) {
            return ['allowed' => true, 'source' => 'super_admin', 'detail' => 'Super Admin'];
        }
        $state = $this->moduleState(strtok($permission, '.') ?: '');
        if ($state !== null && $state['super_only']) {
            return ['allowed' => false, 'source' => 'reserved', 'detail' => 'Reserved for Super Admins'];
        }
        if ($state !== null && !$state['active']) {
            return ['allowed' => false, 'source' => 'module_disabled', 'detail' => 'Module disabled'];
        }
        $override = $r['overrides'][$permission] ?? null;
        $roles = $r['roles'][$permission] ?? [];
        if ($override === 'deny') {
            return ['allowed' => false, 'source' => 'deny', 'detail' => 'Explicit deny' . ($roles ? ' (overrides ' . implode(', ', $roles) . ')' : '')];
        }
        if ($override === 'allow' && isset($r['grants'][$permission]) && $roles === []) {
            return ['allowed' => true, 'source' => 'allow', 'detail' => 'Explicit allow'];
        }
        if ($override === 'allow_ignored') {
            return ['allowed' => $roles !== [], 'source' => $roles ? 'role' : 'none', 'detail' => ($roles ? 'Role: ' . implode(', ', $roles) . '; ' : '') . 'explicit allow ignored (per-user grants disabled)'];
        }
        if ($roles !== []) {
            return ['allowed' => true, 'source' => 'role', 'detail' => 'Role: ' . implode(', ', $roles)];
        }
        return ['allowed' => false, 'source' => 'none', 'detail' => 'Not granted'];
    }

    /** @param array<string, mixed> $user @return list<string> effective permission slugs */
    public function permissionSlugs(array $user): array
    {
        if (!AccountState::usable($user)) {
            return [];
        }
        if ($this->isSuperAdmin($user)) {
            return array_column($this->db->select('SELECT slug FROM permissions ORDER BY slug'), 'slug');
        }
        $slugs = array_values(array_filter(array_keys($this->resolve((int) $user['id'])['grants']), fn (string $s): bool => $this->allows($user, $s)));
        sort($slugs);
        return $slugs;
    }

    /** Ownership check reusable by every module. @param array<string, mixed> $user @param array<string, mixed> $record */
    public function owns(array $user, array $record, string $ownerColumn = 'created_by'): bool
    {
        return isset($record[$ownerColumn]) && (int) $record[$ownerColumn] === (int) $user['id'];
    }

    public function flush(?int $userId = null): void
    {
        if ($userId === null) {
            $this->cache = [];
            $this->modules = null;
        } else {
            unset($this->cache[$userId]);
        }
    }

    /** @return array{active: bool, super_only: bool}|null */
    private function moduleState(string $module): ?array
    {
        if ($this->modules === null) {
            $this->modules = [];
            foreach ($this->db->select('SELECT module_key, is_active, super_admin_only FROM modules') as $m) {
                $this->modules[$m['module_key']] = ['active' => (bool) $m['is_active'], 'super_only' => (bool) $m['super_admin_only']];
            }
        }
        return $this->modules[$module] ?? null;
    }

    /** @return array{super: bool, grants: array<string, string>, roles: array<string, list<string>>, overrides: array<string, string>} */
    private function resolve(int $userId): array
    {
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }

        $super = (bool) $this->db->scalar(
            'SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = ? AND r.is_active = 1 AND r.is_super_admin = 1 LIMIT 1',
            [$userId]
        );

        $grants = [];
        $roles = [];
        $overrides = [];
        if (!$super) {
            foreach ($this->db->select(
                'SELECT p.slug, r.name FROM user_roles ur
                 JOIN roles r ON r.id = ur.role_id AND r.is_active = 1
                 JOIN role_permissions rp ON rp.role_id = r.id
                 JOIN permissions p ON p.id = rp.permission_id AND p.is_active = 1
                 WHERE ur.user_id = ?',
                [$userId]
            ) as $row) {
                $grants[$row['slug']] = 'role';
                $roles[$row['slug']][] = $row['name'];
            }

            $allowGrants = (bool) App::settings()->get('allow_user_level_grants', true);
            foreach ($this->db->select(
                'SELECT p.slug, o.effect FROM user_permission_overrides o
                 JOIN permissions p ON p.id = o.permission_id
                 WHERE o.user_id = ? AND (o.expires_at IS NULL OR o.expires_at > ?)',
                [$userId, now()]
            ) as $o) {
                if ($o['effect'] === 'deny') {
                    unset($grants[$o['slug']]);
                    $overrides[$o['slug']] = 'deny';
                } elseif ($allowGrants) {
                    $grants[$o['slug']] = 'override';
                    $overrides[$o['slug']] = 'allow';
                } else {
                    $overrides[$o['slug']] = 'allow_ignored';
                }
            }
        }

        return $this->cache[$userId] = ['super' => $super, 'grants' => $grants, 'roles' => $roles, 'overrides' => $overrides];
    }

    /**
     * Resource policies for user management: only a Super Admin may manage
     * another Super Admin account (Super Admins bypass policies).
     */
    private function registerCorePolicies(): void
    {
        $protectSuperAdmins = function (array $actor, mixed $target): bool {
            if (!is_array($target) || !isset($target['id'])) {
                return true;
            }
            return !$this->isSuperAdmin((int) $target['id']);
        };
        foreach (['users.edit', 'users.change_status', 'users.assign_roles', 'users.reset_password', 'users.suspend', 'users.force_logout', 'users.delete', 'users.restore'] as $permission) {
            $this->define($permission, $protectSuperAdmins);
        }
    }

    public static function superAdminRoleSlug(): string
    {
        return (string) Config::get('auth.super_admin_role', 'super-admin');
    }
}
