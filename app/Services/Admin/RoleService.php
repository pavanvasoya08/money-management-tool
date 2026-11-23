<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;
use App\Exceptions\DomainRuleException;

/**
 * Custom role management. Roles are reusable permission templates:
 * permissions + data visibility + field access. System roles cannot be
 * deleted; the Super Admin role's access cannot be edited.
 */
final class RoleService extends AdminService
{
    /** @param array{name: string, description?: ?string} $data @param array<string, mixed> $actor */
    public function create(array $data, array $actor, ?int $copyFromRoleId = null): int
    {
        $this->requireSuperAdmin($actor);
        $name = trim($data['name']);
        $slug = $this->uniqueSlug($name);
        $id = $this->db->transaction(function (Database $db) use ($name, $slug, $data, $copyFromRoleId): int {
            $now = now();
            $id = $db->insert('roles', [
                'name' => $name, 'slug' => $slug, 'description' => $data['description'] ?? null,
                'is_system' => 0, 'is_super_admin' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($copyFromRoleId !== null) {
                $this->copyAccess($copyFromRoleId, $id);
            }
            return $id;
        });
        $this->audit('role.created', "Created role {$name}" . ($copyFromRoleId ? ' (copied)' : ''), 'role', $id, null, ['name' => $name, 'slug' => $slug, 'description' => $data['description'] ?? null, 'copied_from' => $copyFromRoleId], affectedRole: $id);
        $this->flush();
        return $id;
    }

    /** @param array{name: string, description?: ?string, is_active?: bool} $data @param array<string, mixed> $actor */
    public function update(int $roleId, array $data, array $actor): void
    {
        $this->requireSuperAdmin($actor);
        $role = $this->role($roleId);
        if ($role['is_super_admin'] && isset($data['is_active']) && !$data['is_active']) {
            throw new DomainRuleException('The Super Admin role cannot be disabled.');
        }
        $new = ['name' => trim($data['name']), 'description' => $data['description'] ?? null, 'is_active' => isset($data['is_active']) ? (int) $data['is_active'] : (int) $role['is_active']];
        $old = ['name' => $role['name'], 'description' => $role['description'], 'is_active' => (int) $role['is_active']];
        if ($old === $new) {
            return;
        }
        $this->db->update('roles', $new + ['updated_at' => now()], ['id' => $roleId]);
        $this->audit('role.updated', "Updated role {$new['name']}", 'role', $roleId, $old, $new, affectedRole: $roleId);
        $this->flush();
    }

    /** @param array<string, mixed> $actor */
    public function duplicate(int $roleId, string $newName, array $actor): int
    {
        $role = $this->role($roleId);
        if ($role['is_super_admin']) {
            throw new DomainRuleException('The Super Admin role cannot be duplicated.');
        }
        return $this->create(['name' => $newName, 'description' => $role['description']], $actor, $roleId);
    }

    /**
     * Delete a custom role. If users still hold it, they must be reassigned to
     * another role in the same transaction — never silently stripped of access.
     *
     * @param array<string, mixed> $actor
     */
    public function delete(int $roleId, array $actor, ?int $reassignTo = null): void
    {
        $this->requireSuperAdmin($actor);
        $role = $this->role($roleId);
        if ($role['is_system']) {
            throw new DomainRuleException('System roles cannot be deleted. You can remove their permissions instead.');
        }
        $users = array_map('intval', array_column($this->db->select('SELECT user_id FROM user_roles WHERE role_id = ?', [$roleId]), 'user_id'));
        if ($users !== [] && $reassignTo === null) {
            throw new DomainRuleException(count($users) . ' user(s) still have this role. Choose a role to reassign them to.');
        }
        if ($reassignTo !== null) {
            $target = $this->role($reassignTo);
            if ($reassignTo === $roleId || !$target['is_active']) {
                throw new DomainRuleException('Choose a different, active role for reassignment.');
            }
            if ($target['is_super_admin']) {
                throw new DomainRuleException('Users cannot be bulk-reassigned to the Super Admin role.');
            }
        }
        if ($this->db->scalar('SELECT 1 FROM approval_workflow_steps WHERE approver_role_id = ?', [$roleId])) {
            throw new DomainRuleException('This role is an approver in an approval workflow. Change the workflow first.');
        }
        $snapshot = [
            'name' => $role['name'], 'slug' => $role['slug'], 'description' => $role['description'],
            'permissions' => array_column($this->db->select('SELECT p.slug FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.slug', [$roleId]), 'slug'),
            'users' => $users,
        ];
        $this->db->transaction(function (Database $db) use ($roleId, $users, $reassignTo, $actor): void {
            if ($reassignTo !== null) {
                foreach ($users as $userId) {
                    if (!$db->scalar('SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = ?', [$userId, $reassignTo])) {
                        $db->insert('user_roles', ['user_id' => $userId, 'role_id' => $reassignTo, 'assigned_by' => (int) $actor['id'], 'assigned_at' => now()]);
                    }
                }
            }
            $db->delete('roles', ['id' => $roleId]); // grants, scopes and field rules cascade; audit history keeps the snapshot
        });
        $this->audit('role.deleted', "Deleted role {$role['name']}" . ($reassignTo ? ' (users reassigned)' : ''), 'role', $roleId, $snapshot, ['reassigned_to' => $reassignTo]);
        $this->flush();
    }

    /** Copy permissions, data scopes and field rules from one role to another (replacing the target's). @param array<string, mixed> $actor */
    public function copyPermissions(int $fromRoleId, int $toRoleId, array $actor): void
    {
        $this->requireSuperAdmin($actor);
        $to = $this->role($toRoleId);
        $from = $this->role($fromRoleId);
        if ($to['is_super_admin'] || $from['is_super_admin']) {
            throw new DomainRuleException('The Super Admin role has implicit full access and cannot be copied to or from.');
        }
        $before = $this->slugs($toRoleId);
        $this->db->transaction(function (Database $db) use ($fromRoleId, $toRoleId): void {
            $db->delete('role_permissions', ['role_id' => $toRoleId]);
            $db->delete('role_data_scopes', ['role_id' => $toRoleId]);
            $db->delete('role_field_permissions', ['role_id' => $toRoleId]);
            $this->copyAccess($fromRoleId, $toRoleId);
        });
        $this->audit('role.permissions_copied', "Copied access from {$from['name']} to {$to['name']}", 'role', $toRoleId, ['permissions' => $before], ['permissions' => $this->slugs($toRoleId), 'copied_from' => $from['name']], affectedRole: $toRoleId);
        $this->flush();
    }

    private function copyAccess(int $fromRoleId, int $toRoleId): void
    {
        $now = now();
        foreach ($this->db->select('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$fromRoleId]) as $r) {
            $this->db->insert('role_permissions', ['role_id' => $toRoleId, 'permission_id' => $r['permission_id'], 'data_scope' => 'all', 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ($this->db->select('SELECT module_id, scope, resource_ids FROM role_data_scopes WHERE role_id = ?', [$fromRoleId]) as $r) {
            $this->db->insert('role_data_scopes', ['role_id' => $toRoleId, 'module_id' => $r['module_id'], 'scope' => $r['scope'], 'resource_ids' => $r['resource_ids'], 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ($this->db->select('SELECT field_id, access FROM role_field_permissions WHERE role_id = ?', [$fromRoleId]) as $r) {
            $this->db->insert('role_field_permissions', ['role_id' => $toRoleId, 'field_id' => $r['field_id'], 'access' => $r['access'], 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /** @return list<string> */
    public function slugs(int $roleId): array
    {
        return array_column($this->db->select('SELECT p.slug FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.slug', [$roleId]), 'slug');
    }

    private function uniqueSlug(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-') ?: 'role';
        $slug = $base;
        $i = 2;
        while ($this->db->scalar('SELECT 1 FROM roles WHERE slug = ?', [$slug])) {
            $slug = $base . '-' . $i++;
        }
        return mb_substr($slug, 0, 100);
    }
}
