<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Authorization\DataScope;
use App\Services\Authorization\FieldAccess;

/**
 * Super Admin changes to module/action permissions, user overrides,
 * record-level data visibility and field-level access. Every change is
 * transactional, takes effect immediately and is audited with old/new values.
 */
final class PermissionService extends AdminService
{
    // ------------------------------------------------------------------ role matrix

    /**
     * Replace a role's permissions with exactly $permissionIds.
     * @param list<int|string> $permissionIds @param array<string, mixed> $actor
     * @return array{granted: list<string>, revoked: list<string>}
     */
    public function syncRolePermissions(int $roleId, array $permissionIds, array $actor): array
    {
        $this->requireSuperAdmin($actor);
        $role = $this->role($roleId);
        if ($role['is_super_admin']) {
            throw new DomainRuleException('The Super Admin role always has every permission; it cannot be edited.');
        }
        $ids = self::intIds($permissionIds);
        $valid = $this->validPermissionIds($ids, excludeSuperAdminOnly: true);
        if (count($valid) !== count($ids)) {
            throw ValidationException::withMessage('permissions', 'One or more permissions are invalid or reserved for Super Admins.');
        }
        $before = array_map('intval', array_column($this->db->select('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$roleId]), 'permission_id'));
        $add = array_values(array_diff($ids, $before));
        $remove = array_values(array_diff($before, $ids));
        if ($add === [] && $remove === []) {
            return ['granted' => [], 'revoked' => []];
        }
        $this->db->transaction(function (Database $db) use ($roleId, $add, $remove, $actor): void {
            $now = now();
            foreach ($remove as $pid) {
                $db->delete('role_permissions', ['role_id' => $roleId, 'permission_id' => $pid]);
            }
            foreach ($add as $pid) {
                $db->insert('role_permissions', ['role_id' => $roleId, 'permission_id' => $pid, 'data_scope' => 'all', 'granted_by' => (int) $actor['id'], 'created_at' => $now, 'updated_at' => $now]);
            }
        });
        $granted = $this->slugsFor($add);
        $revoked = $this->slugsFor($remove);
        $this->audit('permission.role_changed', "Changed permissions of role {$role['name']}: +" . count($granted) . ' / -' . count($revoked), 'role', $roleId,
            ['revoked' => $revoked], ['granted' => $granted], affectedRole: $roleId);
        $this->flush();
        return ['granted' => $granted, 'revoked' => $revoked];
    }

    // ------------------------------------------------------------------ user overrides

    /**
     * Set explicit Allow / Deny / Inherit for a user.
     * @param array<int|string, string> $states permission id => allow|deny|inherit
     * @param array<string, mixed> $actor
     */
    public function setUserOverrides(int $userId, array $states, array $actor, ?string $expiresAt = null, ?string $reason = null): int
    {
        $this->requireSuperAdmin($actor);
        $this->forbidSelf($actor, $userId);
        $this->assertUser($userId);
        $ids = self::intIds(array_keys($states));
        $valid = $this->validPermissionIds($ids, excludeSuperAdminOnly: true);
        if (count($valid) !== count($ids)) {
            throw ValidationException::withMessage('overrides', 'One or more permissions are invalid or reserved for Super Admins.');
        }
        foreach ($states as $state) {
            if (!in_array($state, ['allow', 'deny', 'inherit'], true)) {
                throw ValidationException::withMessage('overrides', 'Invalid override state.');
            }
        }
        $current = [];
        foreach ($this->db->select('SELECT permission_id, effect FROM user_permission_overrides WHERE user_id = ?', [$userId]) as $r) {
            $current[(int) $r['permission_id']] = $r['effect'];
        }
        $old = [];
        $new = [];
        $this->db->transaction(function (Database $db) use ($states, $current, $userId, $actor, $expiresAt, $reason, &$old, &$new): void {
            $now = now();
            foreach ($states as $pid => $state) {
                $pid = (int) $pid;
                $was = $current[$pid] ?? 'inherit';
                if ($was === $state) {
                    continue;
                }
                $slug = (string) $db->scalar('SELECT slug FROM permissions WHERE id = ?', [$pid]);
                $old[$slug] = $was;
                $new[$slug] = $state;
                if ($state === 'inherit') {
                    $db->delete('user_permission_overrides', ['user_id' => $userId, 'permission_id' => $pid]);
                } elseif ($was === 'inherit') {
                    $db->insert('user_permission_overrides', ['user_id' => $userId, 'permission_id' => $pid, 'effect' => $state, 'expires_at' => $expiresAt, 'reason' => $reason, 'granted_by' => (int) $actor['id'], 'created_at' => $now, 'updated_at' => $now]);
                } else {
                    $db->update('user_permission_overrides', ['effect' => $state, 'expires_at' => $expiresAt, 'reason' => $reason, 'granted_by' => (int) $actor['id'], 'updated_at' => $now], ['user_id' => $userId, 'permission_id' => $pid]);
                }
            }
        });
        if ($new !== []) {
            $this->audit('permission.override_changed', 'Changed ' . count($new) . ' permission override(s)', 'user', $userId, $old, $new + ($expiresAt ? ['_expires_at' => $expiresAt] : []), affectedUser: $userId);
            $this->flush();
        }
        return count($new);
    }

    /** Deny every action of a module for one user ("restrict module"). @param array<string, mixed> $actor */
    public function restrictModule(int $userId, string $moduleKey, bool $restrict, array $actor): void
    {
        $module = $this->module($moduleKey);
        $states = [];
        foreach ($this->db->select('SELECT id FROM permissions WHERE module_id = ?', [$module['id']]) as $p) {
            $states[(int) $p['id']] = $restrict ? 'deny' : 'inherit';
        }
        $this->setUserOverrides($userId, $states, $actor, null, $restrict ? 'Module restricted' : null);
    }

    // ------------------------------------------------------------------ data visibility

    /** @param list<int|string> $resourceIds @param array<string, mixed> $actor */
    public function setRoleDataScope(int $roleId, string $moduleKey, string $scope, array $resourceIds, array $actor): void
    {
        $this->requireSuperAdmin($actor);
        $role = $this->role($roleId);
        if ($role['is_super_admin']) {
            throw new DomainRuleException('The Super Admin role always sees all records.');
        }
        $module = $this->module($moduleKey);
        $old = $this->db->first('SELECT scope, resource_ids FROM role_data_scopes WHERE role_id = ? AND module_id = ?', [$roleId, $module['id']]);
        $this->writeScope('role_data_scopes', 'role_id', $roleId, (int) $module['id'], $scope, $resourceIds, $actor);
        $this->audit('data_visibility.role_changed', "Data visibility of {$role['name']} for {$module['name']}: " . ($scope === 'inherit' ? 'derived from actions' : DataScope::from($scope)->label()),
            'role', $roleId, $old ?? ['scope' => 'derived'], ['scope' => $scope, 'resource_ids' => self::intIds($resourceIds), 'module' => $moduleKey], affectedRole: $roleId);
        $this->flush();
    }

    /** @param list<int|string> $resourceIds @param array<string, mixed> $actor */
    public function setUserDataScope(int $userId, string $moduleKey, string $scope, array $resourceIds, array $actor, ?string $reason = null): void
    {
        $this->requireSuperAdmin($actor);
        $this->forbidSelf($actor, $userId);
        $this->assertUser($userId);
        $module = $this->module($moduleKey);
        $old = $this->db->first('SELECT scope, resource_ids FROM user_data_scope_overrides WHERE user_id = ? AND module_id = ?', [$userId, $module['id']]);
        $this->writeScope('user_data_scope_overrides', 'user_id', $userId, (int) $module['id'], $scope, $resourceIds, $actor, $reason);
        $this->audit('data_visibility.user_changed', "Data visibility for {$module['name']}: " . ($scope === 'inherit' ? 'inherit from roles' : DataScope::from($scope)->label()),
            'user', $userId, $old ?? ['scope' => 'inherit'], ['scope' => $scope, 'resource_ids' => self::intIds($resourceIds), 'module' => $moduleKey], affectedUser: $userId);
        $this->flush();
    }

    /** @param list<int|string> $resourceIds @param array<string, mixed> $actor */
    private function writeScope(string $table, string $ownerColumn, int $ownerId, int $moduleId, string $scope, array $resourceIds, array $actor, ?string $reason = null): void
    {
        if ($scope !== 'inherit' && DataScope::tryFrom($scope) === null) {
            throw ValidationException::withMessage('scope', 'Invalid data visibility scope.');
        }
        $ids = self::intIds($resourceIds);
        $this->db->transaction(function (Database $db) use ($table, $ownerColumn, $ownerId, $moduleId, $scope, $ids, $actor, $reason): void {
            $db->delete($table, [$ownerColumn => $ownerId, 'module_id' => $moduleId]);
            if ($scope === 'inherit') {
                return;
            }
            $row = [$ownerColumn => $ownerId, 'module_id' => $moduleId, 'scope' => $scope, 'resource_ids' => $ids === [] ? null : json_encode($ids),
                'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()];
            if ($table === 'user_data_scope_overrides') {
                $row['reason'] = $reason;
            }
            $db->insert($table, $row);
        });
    }

    // ------------------------------------------------------------------ field access

    /**
     * @param array<int|string, string> $rules field id => access | default ('default' removes the rule)
     * @param array<string, mixed> $actor
     */
    public function setRoleFieldAccess(int $roleId, array $rules, array $actor): int
    {
        $this->requireSuperAdmin($actor);
        $role = $this->role($roleId);
        if ($role['is_super_admin']) {
            throw new DomainRuleException('The Super Admin role always has full field access.');
        }
        return $this->writeFieldRules('role_field_permissions', 'role_id', $roleId, $rules, $actor, 'default', function (array $old, array $new) use ($roleId, $role): void {
            $this->audit('field_visibility.role_changed', 'Changed ' . count($new) . " field rule(s) for role {$role['name']}", 'role', $roleId, $old, $new, affectedRole: $roleId);
        });
    }

    /** @param array<int|string, string> $rules field id => access | inherit @param array<string, mixed> $actor */
    public function setUserFieldAccess(int $userId, array $rules, array $actor): int
    {
        $this->requireSuperAdmin($actor);
        $this->forbidSelf($actor, $userId);
        $this->assertUser($userId);
        return $this->writeFieldRules('user_field_overrides', 'user_id', $userId, $rules, $actor, 'inherit', function (array $old, array $new) use ($userId): void {
            $this->audit('field_visibility.user_changed', 'Changed ' . count($new) . ' field override(s)', 'user', $userId, $old, $new, affectedUser: $userId);
        });
    }

    /** Change a field's default access (applies when no role/user rule exists). @param array<string, mixed> $actor */
    public function setFieldDefault(int $fieldId, string $access, array $actor): void
    {
        $this->requireSuperAdmin($actor);
        if (FieldAccess::tryFrom($access) === null) {
            throw ValidationException::withMessage('access', 'Invalid field access.');
        }
        $field = $this->db->first('SELECT f.*, m.module_key FROM module_fields f JOIN modules m ON m.id = f.module_id WHERE f.id = ?', [$fieldId]) ?? throw new DomainRuleException('Unknown field.');
        if ($field['default_access'] === $access) {
            return;
        }
        $this->db->update('module_fields', ['default_access' => $access, 'updated_at' => now()], ['id' => $fieldId]);
        $this->audit('field_visibility.default_changed', "Default access of {$field['module_key']}.{$field['field_key']} set to {$access}", 'module_field', $fieldId, ['default_access' => $field['default_access']], ['default_access' => $access]);
        $this->flush();
    }

    /** @param array<int|string, string> $rules */
    private function writeFieldRules(string $table, string $ownerColumn, int $ownerId, array $rules, array $actor, string $removeKeyword, callable $audit): int
    {
        $current = [];
        foreach ($this->db->select("SELECT field_id, access FROM {$table} WHERE {$ownerColumn} = ?", [$ownerId]) as $r) {
            $current[(int) $r['field_id']] = $r['access'];
        }
        $old = [];
        $new = [];
        $this->db->transaction(function (Database $db) use ($rules, $current, $table, $ownerColumn, $ownerId, $actor, $removeKeyword, &$old, &$new): void {
            foreach ($rules as $fieldId => $access) {
                $fieldId = (int) $fieldId;
                if ($access !== $removeKeyword && FieldAccess::tryFrom((string) $access) === null) {
                    throw ValidationException::withMessage('fields', 'Invalid field access value.');
                }
                $field = $db->first('SELECT f.field_key, m.module_key FROM module_fields f JOIN modules m ON m.id = f.module_id WHERE f.id = ?', [$fieldId]);
                if ($field === null) {
                    throw ValidationException::withMessage('fields', 'Unknown field.');
                }
                $was = $current[$fieldId] ?? $removeKeyword;
                if ($was === $access) {
                    continue;
                }
                $key = $field['module_key'] . '.' . $field['field_key'];
                $old[$key] = $was;
                $new[$key] = $access;
                $db->delete($table, [$ownerColumn => $ownerId, 'field_id' => $fieldId]);
                if ($access !== $removeKeyword) {
                    $db->insert($table, [$ownerColumn => $ownerId, 'field_id' => $fieldId, 'access' => $access, 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
        if ($new !== []) {
            $audit($old, $new);
            $this->flush();
        }
        return count($new);
    }

    // ------------------------------------------------------------------ helpers

    /** @param list<int> $ids @return list<int> */
    private function validPermissionIds(array $ids, bool $excludeSuperAdminOnly): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT p.id FROM permissions p JOIN modules m ON m.id = p.module_id WHERE p.id IN ({$in})";
        if ($excludeSuperAdminOnly) {
            $sql .= ' AND m.super_admin_only = 0';
        }
        return array_map('intval', array_column($this->db->select($sql, $ids), 'id'));
    }

    /** @param list<int> $ids @return list<string> */
    private function slugsFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        return array_column($this->db->select("SELECT slug FROM permissions WHERE id IN ({$in}) ORDER BY slug", $ids), 'slug');
    }

    private function assertUser(int $userId): void
    {
        if (!$this->db->scalar('SELECT 1 FROM users WHERE id = ?', [$userId])) {
            throw new DomainRuleException('User not found.');
        }
    }
}
