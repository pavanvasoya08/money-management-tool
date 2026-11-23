<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\Admin\Catalog;
use App\Services\Admin\PermissionService;
use App\Services\Admin\RoleService;

/** Role management, permission matrix, role data visibility and field rules. */
final class RoleController extends AdminController
{
    private const TABS = ['permissions' => 'Permissions', 'data' => 'Data visibility', 'fields' => 'Field access', 'users' => 'Users', 'history' => 'Change history', 'settings' => 'Details'];

    public function index(Request $request): Response
    {
        return $this->admin('admin/roles/index', 'roles', 'Roles', ['roles' => Catalog::roles()]);
    }

    public function create(Request $request): Response
    {
        return $this->admin('admin/roles/form', 'roles', 'Create role', ['role' => null, 'roles' => Catalog::roles()]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, ['name' => 'required|string|min:2|max:100', 'description' => 'nullable|string|max:255', 'copy_from' => 'nullable|integer']);
        $id = (new RoleService())->create($data, $this->user(), $data['copy_from'] ?: null);
        return $this->redirect(route('admin.roles.show', ['id' => $id]))->with('success', 'Role created. Now choose its permissions.');
    }

    public function show(Request $request, string $id): Response
    {
        $role = $this->findRole($id);
        $tab = array_key_exists($request->string('tab'), self::TABS) ? $request->string('tab') : 'permissions';
        $rid = (int) $role['id'];
        $data = ['role' => $role, 'tab' => $tab, 'tabs' => self::TABS, 'roles' => Catalog::roles()];
        $data += match ($tab) {
            'permissions' => ['matrix' => Catalog::matrix(), 'granted' => Catalog::rolePermissionIds($rid), 'columns' => Catalog::COLUMNS],
            'data' => ['modules' => Catalog::recordModules(), 'scopes' => Catalog::roleScopes($rid), 'granted' => Catalog::rolePermissionIds($rid)],
            'fields' => ['fields' => Catalog::fields(), 'rules' => Catalog::roleFieldRules($rid)],
            'users' => ['members' => App::db()->select(
                'SELECT u.id, u.name, u.email, u.status, u.deleted_at, u.suspended_at, u.suspended_until, u.account_expires_at, ur.assigned_at FROM user_roles ur JOIN users u ON u.id = ur.user_id
                 WHERE ur.role_id = ? ORDER BY u.name',
                [$rid]
            )],
            'history' => ['logs' => App::db()->select(
                'SELECT a.*, u.name AS user_name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.affected_role_id = ? ORDER BY a.created_at DESC, a.id DESC LIMIT 100',
                [$rid]
            )],
            default => [],
        };
        return $this->view('admin/roles/show', $data + [
            'title' => $role['name'], 'section' => 'roles',
            'breadcrumbs' => ['Control Center' => route('admin.overview'), 'Roles' => route('admin.roles'), $role['name'] => null],
        ]);
    }

    /** "Module permissions" section: the matrix with a role picker. */
    public function matrix(Request $request): Response
    {
        $roles = array_values(array_filter(Catalog::roles(), static fn (array $r): bool => !$r['is_super_admin']));
        $roleId = (int) $request->input('role', $roles[0]['id'] ?? 0);
        if ($roleId > 0) {
            return $this->redirect(route('admin.roles.show', ['id' => $roleId, 'tab' => 'permissions']));
        }
        return $this->admin('admin/roles/index', 'permissions', 'Module permissions', ['roles' => Catalog::roles()]);
    }

    public function update(Request $request, string $id): Response
    {
        $role = $this->findRole($id);
        $data = $this->validate($request, ['name' => 'required|string|min:2|max:100', 'description' => 'nullable|string|max:255', 'is_active' => 'nullable|boolean']);
        (new RoleService())->update((int) $role['id'], $data, $this->user());
        return $this->redirect(route('admin.roles.show', ['id' => $role['id'], 'tab' => 'settings']))->with('success', 'Role saved.');
    }

    public function duplicate(Request $request, string $id): Response
    {
        $role = $this->findRole($id);
        $data = $this->validate($request, ['name' => 'required|string|min:2|max:100']);
        $newId = (new RoleService())->duplicate((int) $role['id'], (string) $data['name'], $this->user());
        return $this->redirect(route('admin.roles.show', ['id' => $newId]))->with('success', 'Role duplicated with all its permissions, data visibility and field rules.');
    }

    public function delete(Request $request, string $id): Response
    {
        $role = $this->findRole($id);
        $reassign = (int) $request->input('reassign_to', 0) ?: null;
        (new RoleService())->delete((int) $role['id'], $this->user(), $reassign);
        return $this->redirect(route('admin.roles'))->with('success', "Role {$role['name']} deleted." . ($reassign ? ' Users were reassigned.' : ''));
    }

    public function permissions(Request $request, string $id): Response
    {
        $role = $this->findRole($id);
        $result = (new PermissionService())->syncRolePermissions((int) $role['id'], array_values((array) $request->input('permissions', [])), $this->user());
        $msg = $result['granted'] === [] && $result['revoked'] === [] ? 'No changes.' : 'Saved: ' . count($result['granted']) . ' granted, ' . count($result['revoked']) . ' revoked. Applied immediately.';
        return $this->redirect(route('admin.roles.show', ['id' => $role['id'], 'tab' => 'permissions']))->with('success', $msg);
    }

    public function copy(Request $request, string $id): Response
    {
        $role = $this->findRole($id);
        (new RoleService())->copyPermissions((int) $request->input('from_role', 0), (int) $role['id'], $this->user());
        return $this->redirect(route('admin.roles.show', ['id' => $role['id'], 'tab' => 'permissions']))->with('success', 'Permissions, data visibility and field rules copied.');
    }

    public function dataScope(Request $request, string $id): Response
    {
        $role = $this->findRole($id);
        $svc = new PermissionService();
        foreach ((array) $request->input('scopes', []) as $module => $scope) {
            if (!is_string($module) || !is_string($scope)) {
                continue;
            }
            $current = Catalog::roleScopes((int) $role['id'])[$module]['scope'] ?? 'inherit';
            $ids = preg_split('/[\s,]+/', (string) ($request->input('ids', [])[$module] ?? '')) ?: [];
            $currentIds = json_decode((string) (Catalog::roleScopes((int) $role['id'])[$module]['resource_ids'] ?? '[]'), true) ?: [];
            if ($current !== $scope || array_map('intval', $currentIds) != array_values(array_unique(array_map('intval', array_filter($ids, 'ctype_digit'))))) {
                $svc->setRoleDataScope((int) $role['id'], $module, $scope, $ids, $this->user());
            }
        }
        return $this->redirect(route('admin.roles.show', ['id' => $role['id'], 'tab' => 'data']))->with('success', 'Data visibility saved.');
    }

    public function fields(Request $request, string $id): Response
    {
        $role = $this->findRole($id);
        $n = (new PermissionService())->setRoleFieldAccess((int) $role['id'], array_filter((array) $request->input('rules', []), 'is_string'), $this->user());
        return $this->redirect(route('admin.roles.show', ['id' => $role['id'], 'tab' => 'fields']))->with('success', $n === 0 ? 'No changes.' : "{$n} field rule(s) saved.");
    }
}
