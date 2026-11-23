<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Services\Admin\Catalog;
use App\Services\Admin\PermissionService;
use App\Services\Admin\ResourceAssignmentService;
use App\Services\Admin\TemplateService;
use App\Services\Authorization\AccountState;
use App\Services\Authorization\DataScope;
use App\Services\UserService;

/** Super Admin user profile (8 tabs) and per-user access controls. */
final class AdminUserController extends AdminController
{
    private const TABS = ['personal' => 'Personal information', 'roles' => 'Assigned roles', 'permissions' => 'Module permissions',
        'data' => 'Data visibility', 'fields' => 'Field permissions', 'approvals' => 'Approval permissions', 'logins' => 'Login history', 'activity' => 'Activity history'];

    public function show(Request $request, string $id): Response
    {
        $target = $this->findUser($id);
        $tab = array_key_exists($request->string('tab'), self::TABS) ? $request->string('tab') : 'personal';
        $uid = (int) $target['id'];
        $data = [
            'target' => $target, 'tab' => $tab, 'tabs' => self::TABS, 'state' => AccountState::of($target),
            'isSelf' => $uid === (int) $this->user()['id'], 'targetIsSuper' => App::gate()->isSuperAdmin($uid),
            'roles' => User::roles($uid),
        ];
        $data += match ($tab) {
            'roles' => ['allRoles' => \App\Models\Role::allActive(), 'selected' => User::roleIds($uid), 'templates' => App::db()->select('SELECT id, name FROM permission_templates ORDER BY name')],
            'permissions' => ['matrix' => Catalog::matrix(), 'overrides' => Catalog::userOverrides($uid), 'columns' => Catalog::COLUMNS],
            'data' => [
                'modules' => Catalog::recordModules(), 'scopes' => Catalog::userScopes($uid),
                'resourceTypes' => (new ResourceAssignmentService())->types(), 'assignmentCounts' => (new ResourceAssignmentService())->counts($uid),
            ],
            'fields' => ['fields' => Catalog::fields(), 'rules' => Catalog::userFieldRules($uid), 'effective' => $this->effectiveFields($target)],
            'approvals' => ['workflows' => App::db()->select('SELECT w.*, m.module_key FROM approval_workflows w JOIN modules m ON m.id = w.module_id ORDER BY w.name')],
            'logins' => [
                'logins' => LoginAttempt::recent(30, $uid), 'sessions' => App::sessions()->active($uid),
                'failed' => (int) App::db()->scalar('SELECT COUNT(*) FROM login_attempts WHERE user_id = ? AND successful = 0', [$uid]),
            ],
            'activity' => ['logs' => App::db()->select(
                'SELECT a.*, u.name AS user_name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id
                 WHERE a.affected_user_id = ? OR a.user_id = ? OR (a.subject_type = \'user\' AND a.subject_id = ?) ORDER BY a.created_at DESC, a.id DESC LIMIT 100',
                [$uid, $uid, $uid]
            )],
            default => ['creator' => $target['created_by'] ? User::find((int) $target['created_by']) : null],
        };
        return $this->view('admin/users/show', $data + [
            'title' => $target['name'],
            'section' => 'users',
            'breadcrumbs' => ['Control Center' => route('admin.overview'), 'Users' => route('users.index'), $target['name'] => null],
        ]);
    }

    public function overrides(Request $request, string $id): Response
    {
        $target = $this->findUser($id);
        $states = array_filter((array) $request->input('overrides', []), 'is_string');
        $n = (new PermissionService())->setUserOverrides((int) $target['id'], $states, $this->user(), $this->utc($request->string('expires_at')), $request->string('reason') ?: null);
        return $this->tab($target, 'permissions', $n === 0 ? 'No changes.' : "{$n} override(s) saved and applied immediately.");
    }

    public function restrictModule(Request $request, string $id): Response
    {
        $target = $this->findUser($id);
        $module = $request->string('module');
        $restrict = $request->string('restrict') === '1';
        (new PermissionService())->restrictModule((int) $target['id'], $module, $restrict, $this->user());
        return $this->tab($target, 'permissions', $restrict ? 'Module restricted (all actions denied).' : 'Module restriction removed (inherits from roles).');
    }

    public function dataScope(Request $request, string $id): Response
    {
        $target = $this->findUser($id);
        $scope = $request->string('scope');
        $ids = preg_split('/[\s,]+/', $request->string('resource_ids')) ?: [];
        (new PermissionService())->setUserDataScope((int) $target['id'], $request->string('module'), $scope, $ids, $this->user(), $request->string('reason') ?: null);
        return $this->tab($target, 'data', 'Data visibility updated.');
    }

    public function fields(Request $request, string $id): Response
    {
        $target = $this->findUser($id);
        $n = (new PermissionService())->setUserFieldAccess((int) $target['id'], array_filter((array) $request->input('rules', []), 'is_string'), $this->user());
        return $this->tab($target, 'fields', $n === 0 ? 'No changes.' : "{$n} field rule(s) saved.");
    }

    public function resources(Request $request, string $id): Response
    {
        $target = $this->findUser($id);
        (new ResourceAssignmentService())->sync((int) $target['id'], $request->string('type'), array_values((array) $request->input('ids', [])), $this->user());
        $back = $request->string('return') === 'resources' ? route('admin.resources', ['user' => $target['id'], 'type' => $request->string('type')]) : route('admin.users.show', ['id' => $target['id'], 'tab' => 'data']);
        return $this->redirect($back)->with('success', 'Resource assignments saved.');
    }

    public function template(Request $request, string $id): Response
    {
        $target = $this->findUser($id);
        $n = (new TemplateService())->applyToUser((int) $request->input('template_id', 0), (int) $target['id'], $this->user());
        return $this->tab($target, 'permissions', "Template applied: {$n} permission(s) added as explicit Allow.");
    }

    public function suspend(Request $request, string $id): Response
    {
        $target = $this->findUser($id);
        $data = $this->validate($request, ['reason' => 'required|string|min:3|max:255', 'until' => 'nullable|string|max:20'], ['reason' => 'Suspension reason']);
        (new UserService())->suspend($target, $this->utc($data['until']), (string) $data['reason'], $this->user());
        return $this->tab($target, 'personal', 'User suspended and signed out of all devices.');
    }

    public function unsuspend(Request $request, string $id): Response
    {
        $target = $this->findUser($id);
        (new UserService())->unsuspend($target, $this->user());
        return $this->tab($target, 'personal', 'Suspension lifted.');
    }

    public function expiry(Request $request, string $id): Response
    {
        $target = $this->findUser($id);
        (new UserService())->setExpiry($target, $this->utc($request->string('expires_at')), $this->user());
        return $this->tab($target, 'personal', 'Account expiry updated.');
    }

    public function restrictions(Request $request, string $id): Response
    {
        $target = $this->findUser($id);
        (new UserService())->setAccessRestrictions($target, $request->string('allowed_ips'), $request->string('access_start_time') ?: null, $request->string('access_end_time') ?: null, $this->user());
        return $this->tab($target, 'personal', 'Access restrictions saved.');
    }

    public function forceLogout(Request $request, string $id): Response
    {
        $target = $this->findUser($id);
        $n = (new UserService())->forceLogout($target, $this->user());
        return $this->tab($target, 'logins', "Signed out of {$n} session(s) and all remembered devices.");
    }

    public function delete(Request $request, string $id): Response
    {
        $target = $this->findUser($id);
        (new UserService())->delete($target, $this->user());
        return $this->redirect(route('users.index'))->with('success', "{$target['name']} was deleted. History is preserved; the account can be restored.");
    }

    public function restore(Request $request, string $id): Response
    {
        $target = $this->findUser($id);
        (new UserService())->restore($target, $this->user());
        return $this->tab($target, 'personal', 'User restored (inactive). Activate the account when ready.');
    }

    /** @param array<string, mixed> $target @return array<string, array<string, array{0: \App\Services\Authorization\FieldAccess, 1: string}>> */
    private function effectiveFields(array $target): array
    {
        $out = [];
        foreach (Catalog::fields() as $f) {
            $out[$f['module_key']][$f['field_key']] = [
                App::fields()->access($target, $f['module_key'], $f['field_key']),
                App::fields()->explain($target, $f['module_key'], $f['field_key']),
            ];
        }
        return $out;
    }

    private function tab(array $target, string $tab, string $message): Response
    {
        return $this->redirect(route('admin.users.show', ['id' => $target['id'], 'tab' => $tab]))->with('success', $message);
    }

    /** Scope options available for a module (only scopes the module can actually evaluate). @return array<string, string> */
    public static function scopeOptions(string $module): array
    {
        $map = \App\Services\Authorization\DataAccess::mapping($module);
        $out = [];
        foreach (DataScope::cases() as $s) {
            $ok = match ($s) {
                DataScope::Own => $map['owner_column'] !== null || $map['owner_via'] !== null,
                DataScope::Assigned => $map['resource'] !== null,
                DataScope::Selected => $map['table'] !== null,
                DataScope::All, DataScope::None => true,
                default => isset($map['columns'][(string) $s->resourceType()]),
            };
            if ($ok) {
                $out[$s->value] = $s->label();
            }
        }
        return $out;
    }

    public static function moduleName(string $key): string
    {
        return (string) Config::get("modules.{$key}.name", $key);
    }
}
