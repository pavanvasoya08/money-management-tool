<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\Role;
use App\Models\User;

/**
 * User administration business rules. Controllers call these methods after
 * the route-level permission check; the rules below are enforced here so
 * they hold no matter which controller (or future API) calls them.
 *
 *  - Users cannot change their own roles or status.
 *  - Only a Super Admin can grant/revoke the Super Admin role or manage a Super Admin.
 *  - The last active Super Admin can never be deactivated or demoted.
 *  - New users get only the roles explicitly assigned (no implicit module access).
 */
final class UserService
{
    /**
     * @param array{name: string, email: string, phone?: ?string, password: string, roles?: list<int|string>} $data
     * @param array<string, mixed> $actor
     */
    public function create(array $data, array $actor): int
    {
        $this->assertPasswordPolicy($data['password'], $data, 'password');
        $roleIds = $this->normaliseRoleIds($data['roles'] ?? []);
        $this->assertCanAssign($roleIds, $actor);

        $userId = App::db()->transaction(function () use ($data, $roleIds, $actor): int {
            $now = now();
            $id = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password_hash' => PasswordHasher::hash($data['password']),
                'status' => 'active',
                'must_change_password' => 1,
                'password_changed_at' => $now,
                'created_by' => (int) $actor['id'],
                'updated_by' => (int) $actor['id'],
            ]);
            $this->writeRoles($id, $roleIds, (int) $actor['id']);
            return $id;
        });

        App::activity()->log('user.created', "Created user {$data['name']} ({$data['email']})", 'user', $userId, [
            'roles' => array_column(Role::findMany($roleIds), 'slug'),
        ]);
        return $userId;
    }

    /**
     * @param array<string, mixed> $target
     * @param array{name: string, email: string, phone?: ?string} $data
     * @param array<string, mixed> $actor
     */
    public function update(array $target, array $data, array $actor): void
    {
        $changes = [];
        foreach (['name', 'email', 'phone'] as $field) {
            $new = $field === 'email' ? mb_strtolower(trim((string) $data[$field])) : ($data[$field] ?? null);
            if ((string) ($target[$field] ?? '') !== (string) ($new ?? '')) {
                $changes[$field] = $new;
            }
        }
        if ($changes === []) {
            return;
        }
        User::update((int) $target['id'], $changes + ['updated_by' => (int) $actor['id']]);
        App::activity()->log('user.updated', "Updated user {$target['name']}", 'user', (int) $target['id'], [
            'fields' => array_keys($changes),
            'email_changed' => isset($changes['email']) ? ['from' => $target['email'], 'to' => $changes['email']] : null,
        ]);
    }

    /**
     * @param array<string, mixed> $target
     * @param list<int|string> $roleIds
     * @param array<string, mixed> $actor
     */
    public function syncRoles(array $target, array $roleIds, array $actor): void
    {
        $targetId = (int) $target['id'];
        if ($targetId === (int) $actor['id']) {
            throw new DomainRuleException('You cannot change your own roles. Ask another administrator.');
        }
        $roleIds = $this->normaliseRoleIds($roleIds);
        $before = User::roleIds($targetId);
        sort($before);
        sort($roleIds);
        if ($before === $roleIds) {
            return;
        }

        $gate = App::gate();
        $changedSuper = array_filter(
            Role::findMany(array_values(array_merge(array_diff($before, $roleIds), array_diff($roleIds, $before)))),
            static fn (array $r): bool => (bool) $r['is_super_admin']
        );
        if ($changedSuper !== [] && !$gate->isSuperAdmin($actor)) {
            throw new AuthorizationException('users.assign_roles', 'Only a Super Admin can grant or revoke the Super Admin role.');
        }
        $this->assertCanAssign($roleIds, $actor);

        $losesSuper = $gate->isSuperAdmin($targetId)
            && array_filter(Role::findMany($roleIds), static fn (array $r): bool => (bool) $r['is_super_admin']) === [];
        if ($losesSuper && $target['status'] === 'active' && User::countActiveSuperAdmins($targetId) === 0) {
            throw new DomainRuleException('This is the last active Super Admin. Assign the role to another user first.');
        }

        App::db()->transaction(function (Database $db) use ($targetId, $roleIds, $actor): void {
            $db->delete('user_roles', ['user_id' => $targetId]);
            $this->writeRoles($targetId, $roleIds, (int) $actor['id']);
        });
        $gate->flush($targetId);

        $slugs = static fn (array $ids): array => array_column(Role::findMany($ids), 'slug');
        App::activity()->log('user.roles_changed', "Changed roles of {$target['name']}", 'user', $targetId, [
            'from' => $slugs($before),
            'to' => $slugs($roleIds),
        ]);
    }

    /** @param array<string, mixed> $target @param array<string, mixed> $actor */
    public function setStatus(array $target, string $status, array $actor): void
    {
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new DomainRuleException('Invalid status.');
        }
        $targetId = (int) $target['id'];
        if ($targetId === (int) $actor['id']) {
            throw new DomainRuleException('You cannot change the status of your own account.');
        }
        if ($target['status'] === $status) {
            return;
        }
        if ($status === 'inactive' && App::gate()->isSuperAdmin($targetId) && User::countActiveSuperAdmins($targetId) === 0) {
            throw new DomainRuleException('The last active Super Admin cannot be deactivated.');
        }

        App::db()->transaction(function () use ($targetId, $status, $actor): void {
            User::update($targetId, [
                'status' => $status,
                'deactivated_at' => $status === 'inactive' ? now() : null,
                'failed_login_count' => 0,
                'locked_until' => null,
                'updated_by' => (int) $actor['id'],
            ]);
            if ($status === 'inactive') {
                // Active sessions end on their next request (status is re-checked); remembered devices are revoked now.
                (new RememberMeService())->forgetAllForUser($targetId);
            }
        });
        App::gate()->flush($targetId);
        App::activity()->log(
            $status === 'active' ? 'user.activated' : 'user.deactivated',
            ($status === 'active' ? 'Activated' : 'Deactivated') . " user {$target['name']}",
            'user',
            $targetId
        );
    }

    /** Administrator sets a temporary password; the user must change it at next sign-in. */
    public function adminResetPassword(array $target, string $password, array $actor): void
    {
        $targetId = (int) $target['id'];
        if ($targetId === (int) $actor['id']) {
            throw new DomainRuleException('Use "Change password" to change your own password.');
        }
        $this->assertPasswordPolicy($password, $target, 'password');
        User::update($targetId, [
            'password_hash' => PasswordHasher::hash($password),
            'password_changed_at' => now(),
            'must_change_password' => 1,
            'failed_login_count' => 0,
            'locked_until' => null,
            'updated_by' => (int) $actor['id'],
        ]);
        (new RememberMeService())->forgetAllForUser($targetId);
        App::activity()->log('user.password_reset_by_admin', "Reset password for {$target['name']} (must change at next sign-in)", 'user', $targetId);
    }

    /** Self-service password change (requires the current password). */
    public function changeOwnPassword(array $user, string $current, string $new): void
    {
        $userId = (int) $user['id'];
        $hash = User::passwordHash($userId);
        if ($hash === null || !PasswordHasher::verify($current, $hash)) {
            App::activity()->log('auth.password_change_failed', 'Password change failed: wrong current password', 'user', $userId);
            throw ValidationException::withMessage('current_password', 'Your current password is incorrect.');
        }
        if (PasswordHasher::verify($new, $hash)) {
            throw ValidationException::withMessage('password', 'The new password must be different from the current one.');
        }
        $this->assertPasswordPolicy($new, $user, 'password');

        User::update($userId, [
            'password_hash' => PasswordHasher::hash($new),
            'password_changed_at' => now(),
            'must_change_password' => 0,
            'updated_by' => $userId,
        ]);
        (new RememberMeService())->forgetAllForUser($userId);
        App::session()->regenerate();
        App::auth()->refresh();
        App::activity()->log('auth.password_changed', 'Changed own password (other remembered devices signed out)', 'user', $userId);
    }

    /** @param array<string, mixed> $context */
    private function assertPasswordPolicy(string $password, array $context, string $field): void
    {
        $problems = PasswordPolicy::validate($password, ['name' => $context['name'] ?? null, 'email' => $context['email'] ?? null]);
        if ($problems !== []) {
            throw new ValidationException([$field => $problems]);
        }
    }

    /** @param list<int|string> $ids @return list<int> */
    private function normaliseRoleIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, static fn ($v): bool => is_scalar($v) && ctype_digit((string) $v)))));
        if ($ids === []) {
            return [];
        }
        $found = Role::findMany($ids);
        $active = array_map('intval', array_column(array_filter($found, static fn (array $r): bool => (bool) $r['is_active']), 'id'));
        if (count($active) !== count($ids)) {
            throw ValidationException::withMessage('roles', 'One or more selected roles are invalid.');
        }
        return $ids;
    }

    /** @param list<int> $roleIds @param array<string, mixed> $actor */
    private function assertCanAssign(array $roleIds, array $actor): void
    {
        if ($roleIds === []) {
            return;
        }
        $gate = App::gate();
        if (!$gate->allows($actor, 'users.assign_roles')) {
            throw new AuthorizationException('users.assign_roles', 'You are not allowed to assign roles.');
        }
        $grantsSuper = array_filter(Role::findMany($roleIds), static fn (array $r): bool => (bool) $r['is_super_admin']) !== [];
        if ($grantsSuper && !$gate->isSuperAdmin($actor)) {
            throw new AuthorizationException('users.assign_roles', 'Only a Super Admin can create or promote Super Admin accounts.');
        }
    }

    /** @param list<int> $roleIds */
    private function writeRoles(int $userId, array $roleIds, int $actorId): void
    {
        foreach ($roleIds as $roleId) {
            App::db()->insert('user_roles', ['user_id' => $userId, 'role_id' => $roleId, 'assigned_by' => $actorId, 'assigned_at' => now()]);
        }
    }
}
