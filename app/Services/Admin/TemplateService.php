<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Authorization\DataScope;

/**
 * Reusable permission templates (permissions + optional data scopes).
 *  - Apply to a role : the role's permissions become exactly the template's;
 *                      template data scopes replace the role's scopes for those modules.
 *  - Apply to a user : adds an explicit Allow for each template permission the
 *                      user does not already have (nothing is removed).
 */
final class TemplateService extends AdminService
{
    /** @param array{name: string, description?: ?string} $data @param list<int|string> $permissionIds @param array<string, string> $scopes module key => scope */
    public function save(?int $id, array $data, array $permissionIds, array $scopes, array $actor): int
    {
        $this->requireSuperAdmin($actor);
        $name = trim($data['name']);
        $ids = self::intIds($permissionIds);
        $old = $id ? $this->snapshot($id) : null;
        $id = $this->db->transaction(function (Database $db) use ($id, $name, $data, $ids, $scopes, $actor): int {
            $now = now();
            if ($id === null) {
                $id = $db->insert('permission_templates', ['name' => $name, 'slug' => $this->uniqueSlug($name), 'description' => $data['description'] ?? null, 'created_by' => (int) $actor['id'], 'created_at' => $now, 'updated_at' => $now]);
            } else {
                $db->update('permission_templates', ['name' => $name, 'description' => $data['description'] ?? null, 'updated_at' => $now], ['id' => $id]);
                $db->delete('permission_template_items', ['template_id' => $id]);
                $db->delete('permission_template_scopes', ['template_id' => $id]);
            }
            foreach ($ids as $pid) {
                $ok = $db->scalar('SELECT 1 FROM permissions p JOIN modules m ON m.id = p.module_id WHERE p.id = ? AND m.super_admin_only = 0', [$pid]);
                if (!$ok) {
                    throw ValidationException::withMessage('permissions', 'Templates cannot contain invalid or Super-Admin-only permissions.');
                }
                $db->insert('permission_template_items', ['template_id' => $id, 'permission_id' => $pid]);
            }
            foreach ($scopes as $moduleKey => $scope) {
                if ($scope === '' || $scope === 'inherit') {
                    continue;
                }
                if (DataScope::tryFrom($scope) === null) {
                    throw ValidationException::withMessage('scopes', 'Invalid data scope in template.');
                }
                $moduleId = $db->scalar('SELECT id FROM modules WHERE module_key = ?', [$moduleKey]);
                if ($moduleId !== null) {
                    $db->insert('permission_template_scopes', ['template_id' => $id, 'module_id' => $moduleId, 'scope' => $scope]);
                }
            }
            return $id;
        });
        $this->audit($old ? 'template.updated' : 'template.created', ($old ? 'Updated' : 'Created') . " permission template {$name}", 'permission_template', $id, $old, $this->snapshot($id));
        return $id;
    }

    /** @param array<string, mixed> $actor */
    public function duplicate(int $id, array $actor): int
    {
        $t = $this->template($id);
        $snap = $this->snapshot($id);
        $permIds = array_map('intval', array_column($this->db->select('SELECT permission_id FROM permission_template_items WHERE template_id = ?', [$id]), 'permission_id'));
        return $this->save(null, ['name' => $t['name'] . ' (copy)', 'description' => $t['description']], $permIds, $snap['scopes'], $actor);
    }

    /** @param array<string, mixed> $actor */
    public function delete(int $id, array $actor): void
    {
        $this->requireSuperAdmin($actor);
        $snap = $this->snapshot($id);
        $this->db->delete('permission_templates', ['id' => $id]);
        $this->audit('template.deleted', "Deleted permission template {$snap['name']}", 'permission_template', $id, $snap, null);
    }

    /** @param array<string, mixed> $actor */
    public function applyToRole(int $templateId, int $roleId, array $actor): void
    {
        $this->requireSuperAdmin($actor);
        $t = $this->template($templateId);
        $role = $this->role($roleId);
        if ($role['is_super_admin']) {
            throw new DomainRuleException('Templates cannot be applied to the Super Admin role.');
        }
        $permIds = array_column($this->db->select('SELECT permission_id FROM permission_template_items WHERE template_id = ?', [$templateId]), 'permission_id');
        $permissions = new PermissionService();
        $permissions->syncRolePermissions($roleId, $permIds, $actor);
        foreach ($this->db->select('SELECT m.module_key, s.scope FROM permission_template_scopes s JOIN modules m ON m.id = s.module_id WHERE s.template_id = ?', [$templateId]) as $s) {
            $permissions->setRoleDataScope($roleId, $s['module_key'], $s['scope'], [], $actor);
        }
        $this->audit('template.applied', "Applied template {$t['name']} to role {$role['name']}", 'role', $roleId, null, ['template' => $t['name']], affectedRole: $roleId);
    }

    /** @param array<string, mixed> $actor @return int number of permissions added */
    public function applyToUser(int $templateId, int $userId, array $actor): int
    {
        $this->requireSuperAdmin($actor);
        $this->forbidSelf($actor, $userId);
        $t = $this->template($templateId);
        $user = \App\Models\User::find($userId) ?? throw new DomainRuleException('User not found.');
        $gate = \App\Core\App::gate();
        $states = [];
        foreach ($this->db->select('SELECT p.id, p.slug FROM permission_template_items i JOIN permissions p ON p.id = i.permission_id WHERE i.template_id = ?', [$templateId]) as $p) {
            if (!$gate->allows($user, $p['slug'])) {
                $states[(int) $p['id']] = 'allow';
            }
        }
        $count = (new PermissionService())->setUserOverrides($userId, $states, $actor, null, "Template: {$t['name']}");
        foreach ($this->db->select('SELECT m.module_key, s.scope FROM permission_template_scopes s JOIN modules m ON m.id = s.module_id WHERE s.template_id = ?', [$templateId]) as $s) {
            (new PermissionService())->setUserDataScope($userId, $s['module_key'], $s['scope'], [], $actor, "Template: {$t['name']}");
        }
        $this->audit('template.applied', "Applied template {$t['name']} to user (+{$count} permissions)", 'user', $userId, null, ['template' => $t['name'], 'added' => $count], affectedUser: $userId);
        return $count;
    }

    /** @return array{name: string, permissions: list<string>, scopes: array<string, string>} */
    public function snapshot(int $id): array
    {
        $t = $this->template($id);
        $scopes = [];
        foreach ($this->db->select('SELECT m.module_key, s.scope FROM permission_template_scopes s JOIN modules m ON m.id = s.module_id WHERE s.template_id = ?', [$id]) as $s) {
            $scopes[$s['module_key']] = $s['scope'];
        }
        return [
            'name' => $t['name'],
            'permissions' => array_column($this->db->select('SELECT p.slug FROM permission_template_items i JOIN permissions p ON p.id = i.permission_id WHERE i.template_id = ? ORDER BY p.slug', [$id]), 'slug'),
            'scopes' => $scopes,
        ];
    }

    /** @return array<string, mixed> */
    private function template(int $id): array
    {
        return $this->db->first('SELECT * FROM permission_templates WHERE id = ?', [$id]) ?? throw new DomainRuleException('Template not found.');
    }

    private function uniqueSlug(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-') ?: 'template';
        $slug = $base;
        $i = 2;
        while ($this->db->scalar('SELECT 1 FROM permission_templates WHERE slug = ?', [$slug])) {
            $slug = $base . '-' . $i++;
        }
        return mb_substr($slug, 0, 100);
    }
}
