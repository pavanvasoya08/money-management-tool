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
 *  - Non-Super-Admins can only assign roles whose permissions they hold (no escalation by proxy).
 *  - Suspending, expiring, deactivating, deleting or force-logging-out revokes sessions server-side.
 *  - Deletion is soft (history preserved) and only for already-deactivated accounts.
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
        App::activity()->log('user.updated', "Updated user {$target['name']}", 'user', (int) $target['id'], ['fields' => array_keys($changes)],
            module: 'users', oldValues: array_intersect_key($target, $changes), newValues: $changes);
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
        ], module: 'users', oldValues: ['roles' => $slugs($before)], newValues: ['roles' => $slugs($roleIds)]);
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
                // Sessions are revoked server-side and remembered devices forgotten immediately.
                (new RememberMeService())->forgetAllForUser($targetId);
                App::sessions()->revokeAllForUser($targetId, (int) $actor['id'], 'Account deactivated');
            }
        });
        App::gate()->flush($targetId);
        App::activity()->log(
            $status === 'active' ? 'user.activated' : 'user.deactivated',
            ($status === 'active' ? 'Activated' : 'Deactivated') . " user {$target['name']}",
            'user',
            $targetId,
            module: 'users',
            oldValues: ['status' => $target['status']],
            newValues: ['status' => $status]
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
        App::sessions()->revokeAllForUser($targetId, (int) $actor['id'], 'Password reset by administrator');
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
        App::sessions()->revokeAllForUser($userId, $userId, 'Password changed', App::auth()->sessionRowId());
        App::session()->regenerate();
        App::auth()->refresh();
        App::activity()->log('auth.password_changed', 'Changed own password (other remembered devices signed out)', 'user', $userId);
    }

    // ------------------------------------------------------------------ Phase 2: lifecycle

    /** Suspend sign-in (optionally until a date). Sessions end immediately. @param array<string, mixed> $target @param array<string, mixed> $actor */
    public function suspend(array $target, ?string $until, string $reason, array $actor): void
    {
        $targetId = $this->assertNotSelf($target, $actor, 'suspend');
        if ($until !== null && $until <= now()) {
            throw ValidationException::withMessage('suspended_until', 'The end of the suspension must be in the future.');
        }
        $this->assertNotLastSuperAdmin($targetId, 'suspended');
        User::update($targetId, ['suspended_at' => now(), 'suspended_until' => $until, 'suspension_reason' => mb_substr($reason, 0, 255) ?: null, 'suspended_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id']]);
        $this->endAllSessions($targetId, $actor, 'Account suspended');
        App::activity()->log('user.suspended', "Suspended {$target['name']}" . ($until ? ' until ' . $until . ' UTC' : ''), 'user', $targetId,
            module: 'users', oldValues: ['suspended' => false], newValues: ['suspended' => true, 'until' => $until, 'reason' => $reason]);
    }

    /** @param array<string, mixed> $target @param array<string, mixed> $actor */
    public function unsuspend(array $target, array $actor): void
    {
        $targetId = $this->assertNotSelf($target, $actor, 'unsuspend');
        User::update($targetId, ['suspended_at' => null, 'suspended_until' => null, 'suspension_reason' => null, 'suspended_by' => null, 'updated_by' => (int) $actor['id']]);
        App::activity()->log('user.unsuspended', "Lifted suspension of {$target['name']}", 'user', $targetId, module: 'users',
            oldValues: ['suspended' => true, 'until' => $target['suspended_until'], 'reason' => $target['suspension_reason']], newValues: ['suspended' => false]);
    }

    /** @param array<string, mixed> $target @param array<string, mixed> $actor */
    public function setExpiry(array $target, ?string $expiresAt, array $actor): void
    {
        $targetId = $this->assertNotSelf($target, $actor, 'change the expiry of');
        if ($expiresAt !== null && $expiresAt <= now()) {
            $this->assertNotLastSuperAdmin($targetId, 'expired');
        }
        User::update($targetId, ['account_expires_at' => $expiresAt, 'updated_by' => (int) $actor['id']]);
        if ($expiresAt !== null && $expiresAt <= now()) {
            $this->endAllSessions($targetId, $actor, 'Account expired');
        }
        App::activity()->log('user.expiry_changed', $expiresAt ? "Account of {$target['name']} expires {$expiresAt} UTC" : "Removed expiry of {$target['name']}", 'user', $targetId,
            module: 'users', oldValues: ['account_expires_at' => $target['account_expires_at']], newValues: ['account_expires_at' => $expiresAt]);
    }

    /** IP allow-list and allowed hours. @param array<string, mixed> $target @param array<string, mixed> $actor */
    public function setAccessRestrictions(array $target, string $allowedIps, ?string $start, ?string $end, array $actor): void
    {
        $targetId = $this->assertNotSelf($target, $actor, 'restrict');
        $bad = \App\Services\Authorization\AccountState::invalidEntries($allowedIps);
        if ($bad !== []) {
            throw ValidationException::withMessage('allowed_ips', 'Invalid IP address or CIDR range: ' . implode(', ', $bad));
        }
        foreach (['access_start_time' => $start, 'access_end_time' => $end] as $field => $time) {
            if ($time !== null && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                throw ValidationException::withMessage($field, 'Use 24-hour HH:MM.');
            }
        }
        if (($start === null) !== ($end === null) || ($start !== null && $start === $end)) {
            throw ValidationException::withMessage('access_end_time', 'Set both start and end time (different values), or leave both empty.');
        }
        $ips = implode(', ', array_filter(preg_split('/[\s,]+/', trim($allowedIps)) ?: [])) ?: null;
        $old = ['allowed_ips' => $target['allowed_ips'], 'access_start_time' => $target['access_start_time'], 'access_end_time' => $target['access_end_time']];
        $new = ['allowed_ips' => $ips, 'access_start_time' => $start, 'access_end_time' => $end];
        if ($old === $new) {
            return;
        }
        User::update($targetId, $new + ['updated_by' => (int) $actor['id']]);
        App::activity()->log('user.access_restrictions_changed', "Changed access restrictions of {$target['name']}", 'user', $targetId, module: 'users', oldValues: $old, newValues: $new);
    }

    /** Revoke every session and remembered device of a user. @param array<string, mixed> $target @param array<string, mixed> $actor */
    public function forceLogout(array $target, array $actor): int
    {
        $targetId = $this->assertNotSelf($target, $actor, 'force-logout');
        $count = $this->endAllSessions($targetId, $actor, 'Signed out by administrator');
        App::activity()->log('user.force_logout', "Forced logout of {$target['name']} ({$count} session(s))", 'user', $targetId, module: 'users', newValues: ['sessions_revoked' => $count]);
        return $count;
    }

    /**
     * Soft delete: the account can no longer sign in or be listed, but every
     * historical record (audit, approvals, created_by…) stays intact.
     * Only inactive accounts can be deleted.
     *
     * @param array<string, mixed> $target @param array<string, mixed> $actor
     */
    public function delete(array $target, array $actor): void
    {
        $targetId = $this->assertNotSelf($target, $actor, 'delete');
        if ($target['status'] !== 'inactive') {
            throw new DomainRuleException('Deactivate the account before deleting it.');
        }
        if (!empty($target['deleted_at'])) {
            return;
        }
        if (App::db()->scalar("SELECT 1 FROM approval_requests WHERE requested_by = ? AND status = 'pending'", [$targetId])) {
            throw new DomainRuleException('This user has pending approval requests. Resolve them first.');
        }
        if (App::db()->scalar('SELECT 1 FROM approval_workflow_steps WHERE approver_user_id = ?', [$targetId])) {
            throw new DomainRuleException('This user is a named approver in an approval workflow. Change the workflow first.');
        }
        User::update($targetId, ['deleted_at' => now(), 'deleted_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id']]);
        $this->endAllSessions($targetId, $actor, 'Account deleted');
        App::activity()->log('user.deleted', "Deleted user {$target['name']} ({$target['email']})", 'user', $targetId, module: 'users',
            oldValues: ['deleted' => false], newValues: ['deleted' => true]);
    }

    /** Restore a deleted account (it stays inactive until activated). @param array<string, mixed> $target @param array<string, mixed> $actor */
    public function restore(array $target, array $actor): void
    {
        $targetId = $this->assertNotSelf($target, $actor, 'restore');
        if (empty($target['deleted_at'])) {
            throw new DomainRuleException('This account is not deleted.');
        }
        User::update($targetId, ['deleted_at' => null, 'deleted_by' => null, 'status' => 'inactive', 'updated_by' => (int) $actor['id']]);
        App::activity()->log('user.restored', "Restored user {$target['name']} (inactive until activated)", 'user', $targetId, module: 'users',
            oldValues: ['deleted' => true], newValues: ['deleted' => false, 'status' => 'inactive']);
    }

    /** @param array<string, mixed> $target @param array<string, mixed> $actor */
    private function assertNotSelf(array $target, array $actor, string $verb): int
    {
        $targetId = (int) $target['id'];
        if ($targetId === (int) $actor['id']) {
            throw new DomainRuleException("You cannot {$verb} your own account.");
        }
        return $targetId;
    }

    private function assertNotLastSuperAdmin(int $targetId, string $what): void
    {
        if (App::gate()->isSuperAdmin($targetId) && User::countActiveSuperAdmins($targetId) === 0) {
            throw new DomainRuleException("The last active Super Admin cannot be {$what}.");
        }
    }

    /** @param array<string, mixed> $actor */
    private function endAllSessions(int $userId, array $actor, string $reason): int
    {
        (new RememberMeService())->forgetAllForUser($userId);
        App::gate()->flush($userId);
        return App::sessions()->revokeAllForUser($userId, (int) $actor['id'], $reason);
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
        if (!$gate->isSuperAdmin($actor)) {
            // No privilege escalation by proxy: a delegated admin can only hand out roles
            // whose permissions they hold themselves.
            $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
            $needed = array_column(App::db()->select(
                "SELECT DISTINCT p.slug FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id IN ({$placeholders})",
                $roleIds
            ), 'slug');
            $missing = array_diff($needed, $gate->permissionSlugs($actor));
            if ($missing !== []) {
                throw new AuthorizationException('users.assign_roles', 'You can only assign roles whose permissions you hold yourself. Missing: ' . implode(', ', array_slice($missing, 0, 5)) . (count($missing) > 5 ? '…' : ''));
            }
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
