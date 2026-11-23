<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\DomainRuleException;
use App\Exceptions\HttpException;
use App\Models\User;
use App\Services\Admin\Catalog;
use App\Services\Admin\PermissionService;
use App\Services\Admin\ResourceAssignmentService;
use App\Services\Authorization\AccountState;

/** Action catalogue, data visibility grid, field grid, resource assignments and access preview. */
final class AccessController extends AdminController
{
    public function actions(Request $request): Response
    {
        $holders = [];
        foreach (App::db()->select(
            'SELECT rp.permission_id, COUNT(DISTINCT rp.role_id) AS roles FROM role_permissions rp GROUP BY rp.permission_id'
        ) as $r) {
            $holders[(int) $r['permission_id']]['roles'] = (int) $r['roles'];
        }
        foreach (App::db()->select("SELECT permission_id, SUM(CASE WHEN effect = 'allow' THEN 1 ELSE 0 END) AS allows, SUM(CASE WHEN effect = 'deny' THEN 1 ELSE 0 END) AS denies FROM user_permission_overrides GROUP BY permission_id") as $r) {
            $holders[(int) $r['permission_id']] = ($holders[(int) $r['permission_id']] ?? []) + ['allows' => (int) $r['allows'], 'denies' => (int) $r['denies']];
        }
        $focus = $request->string('permission');
        $who = null;
        if (preg_match('/^[a-z_]+\.[a-z_]+$/', $focus)) {
            $pid = App::db()->scalar('SELECT id FROM permissions WHERE slug = ?', [$focus]);
            if ($pid !== null) {
                $who = [
                    'slug' => $focus,
                    'roles' => App::db()->select('SELECT r.id, r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE rp.permission_id = ? ORDER BY r.name', [$pid]),
                    'overrides' => App::db()->select('SELECT u.id, u.name, o.effect, o.expires_at FROM user_permission_overrides o JOIN users u ON u.id = o.user_id WHERE o.permission_id = ? ORDER BY u.name', [$pid]),
                ];
            }
        }
        return $this->admin('admin/actions', 'actions', 'Action permissions', ['matrix' => Catalog::matrix(), 'holders' => $holders, 'who' => $who]);
    }

    public function toggleModule(Request $request, string $id): Response
    {
        $module = App::db()->first('SELECT * FROM modules WHERE id = ?', [$this->intParam($id)]) ?? throw new HttpException(404, 'Module not found.');
        if ($module['super_admin_only'] || in_array($module['module_key'], ['dashboard', 'users', 'settings'], true)) {
            throw new DomainRuleException('This core module cannot be disabled.');
        }
        $new = $module['is_active'] ? 0 : 1;
        App::db()->update('modules', ['is_active' => $new, 'updated_at' => now()], ['id' => $module['id']]);
        App::flushAuthorization();
        App::activity()->log('permission.module_toggled', ($new ? 'Enabled' : 'Disabled') . " module {$module['name']}", 'module', (int) $module['id'], module: 'super_admin',
            oldValues: ['is_active' => (bool) $module['is_active']], newValues: ['is_active' => (bool) $new]);
        return $this->back($request, route('admin.actions'))->with('success', "{$module['name']} " . ($new ? 'enabled' : 'disabled for everyone except Super Admins') . '.');
    }

    public function toggleAction(Request $request, string $id): Response
    {
        $p = App::db()->first('SELECT p.*, m.super_admin_only FROM permissions p JOIN modules m ON m.id = p.module_id WHERE p.id = ?', [$this->intParam($id)]) ?? throw new HttpException(404, 'Permission not found.');
        if ($p['super_admin_only'] || $p['slug'] === 'dashboard.view') {
            throw new DomainRuleException('This action cannot be disabled.');
        }
        $new = $p['is_active'] ? 0 : 1;
        App::db()->update('permissions', ['is_active' => $new, 'updated_at' => now()], ['id' => $p['id']]);
        App::flushAuthorization();
        App::activity()->log('permission.action_toggled', ($new ? 'Enabled' : 'Disabled') . " action {$p['slug']}", 'permission', (int) $p['id'], module: 'super_admin',
            oldValues: ['is_active' => (bool) $p['is_active']], newValues: ['is_active' => (bool) $new]);
        return $this->back($request, route('admin.actions'))->with('success', "{$p['slug']} " . ($new ? 'enabled.' : 'disabled for everyone except Super Admins.'));
    }

    public function dataVisibility(Request $request): Response
    {
        $roles = array_values(array_filter(Catalog::roles(), static fn (array $r): bool => !$r['is_super_admin']));
        $scopes = [];
        foreach ($roles as $r) {
            $scopes[(int) $r['id']] = Catalog::roleScopes((int) $r['id']);
        }
        $grants = [];
        foreach (App::db()->select("SELECT rp.role_id, p.slug FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE p.action IN ('view', 'view_all', 'view_own', 'view_assigned')") as $g) {
            $grants[(int) $g['role_id']][$g['slug']] = true;
        }
        $overrides = App::db()->select(
            'SELECT s.*, m.module_key, m.name AS module_name, u.name AS user_name FROM user_data_scope_overrides s JOIN modules m ON m.id = s.module_id JOIN users u ON u.id = s.user_id ORDER BY u.name, m.sort_order'
        );
        return $this->admin('admin/data-visibility', 'data', 'Data visibility', ['roles' => $roles, 'modules' => Catalog::recordModules(), 'scopes' => $scopes, 'grants' => $grants, 'overrides' => $overrides]);
    }

    public function saveDataVisibility(Request $request): Response
    {
        $svc = new PermissionService();
        $n = 0;
        foreach ((array) $request->input('scopes', []) as $roleId => $modules) {
            if (!is_array($modules)) {
                continue;
            }
            $current = Catalog::roleScopes((int) $roleId);
            foreach ($modules as $module => $scope) {
                if (!is_string($scope) || ($current[$module]['scope'] ?? 'inherit') === $scope) {
                    continue;
                }
                $ids = $scope === ($current[$module]['scope'] ?? null) ? (json_decode((string) $current[$module]['resource_ids'], true) ?: []) : [];
                $svc->setRoleDataScope((int) $roleId, (string) $module, $scope, $ids, $this->user());
                $n++;
            }
        }
        return $this->redirect(route('admin.data'))->with('success', $n === 0 ? 'No changes.' : "{$n} data visibility rule(s) saved and applied.");
    }

    public function fields(Request $request): Response
    {
        $modules = array_values(array_filter(Catalog::modules(), static fn (array $m): bool => (bool) App::db()->scalar('SELECT 1 FROM module_fields WHERE module_id = ?', [$m['id']])));
        $module = $request->string('module') ?: ($modules[0]['module_key'] ?? '');
        $roles = array_values(array_filter(Catalog::roles(), static fn (array $r): bool => !$r['is_super_admin']));
        $rules = [];
        foreach ($roles as $r) {
            $rules[(int) $r['id']] = Catalog::roleFieldRules((int) $r['id']);
        }
        return $this->admin('admin/fields', 'fields', 'Field visibility', [
            'modules' => $modules, 'module' => $module, 'fields' => Catalog::fields($module), 'roles' => $roles, 'rules' => $rules,
            'overrides' => App::db()->select(
                'SELECT o.*, f.field_key, f.label, u.name AS user_name FROM user_field_overrides o JOIN module_fields f ON f.id = o.field_id
                 JOIN modules m ON m.id = f.module_id JOIN users u ON u.id = o.user_id WHERE m.module_key = ? ORDER BY u.name, f.sort_order',
                [$module]
            ),
        ]);
    }

    public function saveFields(Request $request): Response
    {
        $svc = new PermissionService();
        $n = 0;
        foreach ((array) $request->input('defaults', []) as $fieldId => $access) {
            if (is_string($access) && $access !== '') {
                $svc->setFieldDefault((int) $fieldId, $access, $this->user());
            }
        }
        foreach ((array) $request->input('rules', []) as $roleId => $rules) {
            if (is_array($rules)) {
                $n += $svc->setRoleFieldAccess((int) $roleId, array_filter($rules, 'is_string'), $this->user());
            }
        }
        return $this->redirect(route('admin.fields', ['module' => $request->string('module')]))->with('success', 'Field visibility saved' . ($n ? " ({$n} rule change(s))" : '') . '.');
    }

    public function resources(Request $request): Response
    {
        $svc = new ResourceAssignmentService();
        $types = $svc->types();
        $userId = (int) $request->input('user', 0);
        $target = $userId > 0 ? User::find($userId) : null;
        $type = array_key_exists($request->string('type'), $types) ? $request->string('type') : 'user';
        $options = $types[$type]['available'] ? $svc->options($type, mb_substr($request->string('q'), 0, 60)) : [];
        if ($type === 'user' && $target !== null) {
            $options = array_values(array_filter($options, static fn (array $o): bool => $o['id'] !== (int) $target['id']));
        }
        return $this->admin('admin/resources', 'resources', 'Resource assignments', [
            'types' => $types, 'type' => $type, 'target' => $target, 'options' => $options,
            'assigned' => $target ? $svc->assigned((int) $target['id'], $type) : [],
            'users' => App::db()->select('SELECT id, name, email FROM users WHERE deleted_at IS NULL ORDER BY name'),
            'summary' => App::db()->select(
                'SELECT a.resource_type, u.id AS user_id, u.name, COUNT(*) AS n FROM user_resource_assignments a JOIN users u ON u.id = a.user_id
                 GROUP BY a.resource_type, u.id, u.name ORDER BY u.name'
            ),
            'isSelf' => $target !== null && (int) $target['id'] === (int) $this->user()['id'],
        ]);
    }

    /**
     * Preview User Access — computed with the target user's permissions but
     * WITHOUT signing in as them: read-only, no identity switch, no actions.
     */
    public function preview(Request $request): Response
    {
        $userId = (int) $request->input('user', 0);
        $users = App::db()->select('SELECT id, name, email FROM users WHERE deleted_at IS NULL ORDER BY name');
        if ($userId <= 0) {
            return $this->admin('admin/preview', 'preview', 'User access preview', ['users' => $users, 'target' => null]);
        }
        $target = User::find($userId) ?? throw new HttpException(404, 'User not found.');
        $gate = App::gate();
        $da = App::dataAccess();
        $fp = App::fields();

        $nav = [];
        foreach ((array) Config::get('navigation', []) as $item) {
            if (isset($item['section'])) {
                continue;
            }
            $perm = $item['permission'] ?? null;
            $visible = $item['route'] !== null && ($perm === null ? AccountState::usable($target) : $gate->allows($target, $perm));
            if (($item['active'] ?? '') === 'admin') {
                $visible = $gate->isSuperAdmin($target) && AccountState::usable($target);
            }
            $nav[] = ['label' => $item['label'], 'icon' => $item['icon'], 'visible' => $visible, 'future' => $item['route'] === null, 'phase' => $item['phase'] ?? null,
                'future_access' => $item['route'] === null && isset($item['module']) ? $gate->allows($target, $item['module'] . '.view') : null];
        }

        $pages = [];
        foreach ([
            ['Dashboard', '/dashboard', null], ['User list', '/users', 'users.view'], ['Create user', '/users/create', 'users.create'],
            ['Export users (CSV)', '/users/export', 'users.export'], ['Login history', '/users/login-history', 'users.view_login_history'],
            ['Activity log', '/activity', 'activity_logs.view'], ['Settings', '/settings', 'settings.view'], ['Approvals inbox', '/approvals', null],
            ['Users API', '/api/users', 'users.view'], ['Super Admin Control Center', '/admin', 'super_admin'],
        ] as [$label, $path, $perm]) {
            $ok = AccountState::usable($target) && match ($perm) {
                null => true,
                'super_admin' => $gate->isSuperAdmin($target),
                default => $gate->allows($target, $perm),
            };
            $pages[] = ['label' => $label, 'path' => $path, 'permission' => $perm, 'allowed' => $ok];
        }

        $modules = [];
        foreach (Catalog::matrix() as $row) {
            $m = $row['module'];
            $perms = array_merge(array_values($row['columns']), $row['extras']);
            $allowed = [];
            foreach ($perms as $p) {
                $ex = $gate->explain($target, $p['slug']);
                if ($ex['allowed']) {
                    $allowed[] = ['action' => $p['action'], 'label' => $p['description'], 'source' => $ex['detail']];
                }
            }
            $scope = Config::get("modules.{$m['module_key']}.data") ? $da->resolve($target, $m['module_key']) : null;
            $fields = [];
            foreach ($fp->fields($m['module_key']) as $f) {
                $fields[] = ['label' => $f['label'], 'access' => $fp->access($target, $m['module_key'], $f['field_key']), 'source' => $fp->explain($target, $m['module_key'], $f['field_key'])];
            }
            $modules[] = ['module' => $m, 'allowed' => $allowed, 'total' => count($perms), 'scope' => $scope, 'fields' => $fields];
        }

        // Real records the user would see in the only record module with data today (users).
        [$where, $params] = $da->constrain($target, 'users', 'u');
        $visibleUsers = $fp->presentMany($target, 'users', App::db()->select(
            "SELECT u.id, u.name, u.email, u.phone, u.status, u.last_login_ip FROM users u WHERE u.deleted_at IS NULL AND {$where} ORDER BY u.name LIMIT 15",
            $params
        ));
        $visibleCount = (int) App::db()->scalar("SELECT COUNT(*) FROM users u WHERE u.deleted_at IS NULL AND {$where}", $params);

        $approvals = [];
        foreach (App::db()->select('SELECT w.*, m.module_key FROM approval_workflows w JOIN modules m ON m.id = w.module_id ORDER BY w.name') as $wf) {
            $steps = [];
            foreach (App::approvals()->steps((int) $wf['id']) as $s) {
                $fake = ['status' => 'pending', 'requested_by' => 0, 'module_key' => $wf['module_key'], 'workflow_id' => $wf['id'], 'current_step' => $s['step_order']];
                $steps[] = ['step' => $s, 'approve' => App::approvals()->whyNot($target, $fake, 'approve') === null, 'reject' => App::approvals()->whyNot($target, $fake, 'reject') === null];
            }
            $approvals[] = ['wf' => $wf, 'submit' => $gate->allows($target, $wf['module_key'] . '.create'), 'approve' => $gate->allows($target, $wf['module_key'] . '.approve'), 'reject' => $gate->allows($target, $wf['module_key'] . '.reject'), 'steps' => $steps];
        }

        App::activity()->log('access.previewed', "Previewed access of {$target['name']}", 'user', (int) $target['id'], module: 'super_admin');
        return $this->admin('admin/preview', 'preview', 'User access preview', [
            'users' => $users, 'target' => $target, 'state' => AccountState::of($target), 'isSuper' => $gate->isSuperAdmin($target),
            'roles' => User::roles((int) $target['id']), 'nav' => $nav, 'pages' => $pages, 'modules' => $modules,
            'visibleUsers' => $visibleUsers, 'visibleCount' => $visibleCount, 'userFieldAccess' => $fp->accessMap($target, 'users'), 'approvals' => $approvals,
        ]);
    }
}
