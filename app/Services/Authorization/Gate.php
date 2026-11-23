<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Core\Config;
use App\Core\Database;
use App\Exceptions\AuthorizationException;
use Closure;

/**
 * Centralised authorization. Controllers and middleware never check role
 * names; they ask the Gate whether a user holds a permission.
 *
 * Resolution order for a user:
 *   1. Inactive users are denied everything.
 *   2. Super Admin role → allowed everything.
 *   3. Union of grants from all the user's active roles (widest data scope wins).
 *   4. Individual overrides: "allow" adds/replaces a grant, "deny" removes it.
 *      Expired overrides are ignored.
 *   5. Optional resource policy (record/ownership rules) for the permission.
 *
 * Phase 2 builds on: dataScope(), constraints() and the policy hook.
 */
final class Gate
{
    /** @var array<int, array{super: bool, grants: array<string, Grant>}> */
    private array $cache = [];
    /** @var array<string, Closure(array<string, mixed>, mixed, Grant): bool> */
    private array $policies = [];

    public function __construct(private readonly Database $db)
    {
        $this->registerCorePolicies();
    }

    /**
     * Register a resource-level rule evaluated after the permission check.
     * @param Closure(array<string, mixed> $user, mixed $resource, Grant $grant): bool $policy
     */
    public function define(string $permission, Closure $policy): void
    {
        $this->policies[$permission] = $policy;
    }

    /** @param array<string, mixed> $user */
    public function allows(array $user, string $permission, mixed $resource = null): bool
    {
        if (($user['status'] ?? null) !== 'active') {
            return false;
        }
        if ($this->isSuperAdmin($user)) {
            return true;
        }
        $grant = $this->grants($user)[$permission] ?? null;
        if ($grant === null) {
            return false;
        }
        if ($resource !== null && isset($this->policies[$permission])) {
            return ($this->policies[$permission])($user, $resource, $grant);
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

    /** @param array<string, mixed>|int $user */
    public function isSuperAdmin(array|int $user): bool
    {
        $id = is_array($user) ? (int) $user['id'] : $user;
        return $this->resolve($id)['super'];
    }

    /**
     * Effective grants keyed by permission slug.
     * @param array<string, mixed> $user
     * @return array<string, Grant>
     */
    public function grants(array $user): array
    {
        return $this->resolve((int) $user['id'])['grants'];
    }

    /** @param array<string, mixed> $user @return list<string> */
    public function permissionSlugs(array $user): array
    {
        if ($this->isSuperAdmin($user)) {
            return array_column($this->db->select('SELECT slug FROM permissions ORDER BY slug'), 'slug');
        }
        $slugs = array_keys($this->grants($user));
        sort($slugs);
        return $slugs;
    }

    /** Record-level scope for a permission, or null if not granted. @param array<string, mixed> $user */
    public function dataScope(array $user, string $permission): ?DataScope
    {
        if ($this->isSuperAdmin($user)) {
            return DataScope::All;
        }
        return ($this->grants($user)[$permission] ?? null)?->scope;
    }

    /** GST/bank/platform/partner restrictions for a permission (null = unrestricted). @param array<string, mixed> $user */
    public function constraints(array $user, string $permission): ?array
    {
        if ($this->isSuperAdmin($user)) {
            return null;
        }
        return ($this->grants($user)[$permission] ?? null)?->constraints;
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
        } else {
            unset($this->cache[$userId]);
        }
    }

    /** @return array{super: bool, grants: array<string, Grant>} */
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
        if (!$super) {
            $rows = $this->db->select(
                'SELECT p.slug, rp.data_scope, rp.constraints FROM user_roles ur
                 JOIN roles r ON r.id = ur.role_id AND r.is_active = 1
                 JOIN role_permissions rp ON rp.role_id = r.id
                 JOIN permissions p ON p.id = rp.permission_id
                 WHERE ur.user_id = ?',
                [$userId]
            );
            foreach ($rows as $row) {
                $grant = new Grant($row['slug'], DataScope::tryFrom((string) $row['data_scope']) ?? DataScope::Own, self::decode($row['constraints']));
                $grants[$row['slug']] = isset($grants[$row['slug']]) ? $grants[$row['slug']]->merge($grant) : $grant;
            }

            $overrides = $this->db->select(
                'SELECT p.slug, o.effect, o.data_scope, o.constraints FROM user_permission_overrides o
                 JOIN permissions p ON p.id = o.permission_id
                 WHERE o.user_id = ? AND (o.expires_at IS NULL OR o.expires_at > ?)',
                [$userId, now()]
            );
            foreach ($overrides as $o) {
                if ($o['effect'] === 'deny') {
                    unset($grants[$o['slug']]);
                    continue;
                }
                $grants[$o['slug']] = new Grant(
                    $o['slug'],
                    DataScope::tryFrom((string) $o['data_scope']) ?? DataScope::All,
                    self::decode($o['constraints']),
                    'override'
                );
            }
        }

        return $this->cache[$userId] = ['super' => $super, 'grants' => $grants];
    }

    private static function decode(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Resource policies for Phase 1 user management: only a Super Admin may
     * manage another Super Admin account (super admins bypass policies).
     */
    private function registerCorePolicies(): void
    {
        $protectSuperAdmins = function (array $actor, mixed $target): bool {
            if (!is_array($target) || !isset($target['id'])) {
                return true;
            }
            return !$this->isSuperAdmin((int) $target['id']);
        };
        foreach (['users.edit', 'users.change_status', 'users.assign_roles', 'users.reset_password'] as $permission) {
            $this->define($permission, $protectSuperAdmins);
        }
    }

    public static function superAdminRoleSlug(): string
    {
        return (string) Config::get('auth.super_admin_role', 'super-admin');
    }
}
