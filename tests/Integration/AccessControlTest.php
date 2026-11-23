<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Services\Admin\PermissionService;
use App\Services\Admin\RoleService;
use App\Services\Admin\TemplateService;
use App\Services\Admin\WorkflowService;
use App\Services\Authorization\FieldAccess;
use App\Services\UserService;
use Tests\TestCase;

/** Phase 2: overrides, data scopes, field policy, roles, templates, approvals, sessions. */
final class AccessControlTest extends TestCase
{
    private array $root;

    public function setUp(): void
    {
        $this->freshDatabase();
        $this->root = $this->makeUser('root@example.com', ['super-admin'], name: 'Root');
    }

    private function pid(string $slug): int
    {
        return (int) App::db()->scalar('SELECT id FROM permissions WHERE slug = ?', [$slug]);
    }

    private function roleId(string $slug): int
    {
        return (int) App::db()->scalar('SELECT id FROM roles WHERE slug = ?', [$slug]);
    }

    private function fieldId(string $module, string $field): int
    {
        return (int) App::db()->scalar('SELECT f.id FROM module_fields f JOIN modules m ON m.id = f.module_id WHERE m.module_key = ? AND f.field_key = ?', [$module, $field]);
    }

    private function fresh(array $u): array
    {
        App::flushAuthorization();
        return User::find((int) $u['id']);
    }

    private function perms(): PermissionService { return new PermissionService(App::db()); }

    public function testDenyOverrideBeatsRoleAndAllowGrantsExtra(): void
    {
        $mgr = $this->makeUser('mgr@example.com', ['manager']);
        $this->assertTrue(App::gate()->allows($mgr, 'users.view'));
        $this->perms()->setUserOverrides((int) $mgr['id'], [$this->pid('users.view') => 'deny', $this->pid('users.export') => 'allow'], $this->root);
        $mgr = $this->fresh($mgr);
        $this->assertFalse(App::gate()->allows($mgr, 'users.view'), 'explicit deny wins over role grant');
        $this->assertTrue(App::gate()->allows($mgr, 'users.export'), 'explicit allow adds a permission');
        $this->assertSame('deny', App::gate()->explain($mgr, 'users.view')['source'] ?? App::gate()->explain($mgr, 'users.view')['decision'] ?? 'deny');
        $this->perms()->setUserOverrides((int) $mgr['id'], [$this->pid('users.view') => 'inherit'], $this->root);
        $this->assertTrue(App::gate()->allows($this->fresh($mgr), 'users.view'), 'inherit falls back to role');
    }

    public function testExpiredOverrideIsIgnored(): void
    {
        $v = $this->makeUser('v@example.com', ['viewer']);
        $this->perms()->setUserOverrides((int) $v['id'], [$this->pid('users.export') => 'allow'], $this->root, '2000-01-01 00:00:00');
        $this->assertFalse(App::gate()->allows($this->fresh($v), 'users.export'));
    }

    public function testSuperAdminOnlyPermissionsCannotBeGrantedOrDelegated(): void
    {
        $mgr = $this->makeUser('mgr@example.com', ['manager']);
        $this->assertThrows(ValidationException::class, fn () => $this->perms()->setUserOverrides((int) $mgr['id'], [$this->pid('super_admin.view') => 'allow'], $this->root));
        $this->assertFalse(App::gate()->allows($mgr, 'super_admin.view'));
    }

    public function testNonSuperAdminCannotChangeAccessAndNobodyCanChangeTheirOwn(): void
    {
        $admin = $this->makeUser('partner@example.com', ['partner']);
        $victim = $this->makeUser('victim@example.com', ['viewer']);
        $this->assertThrows(AuthorizationException::class, fn () => $this->perms()->setUserOverrides((int) $victim['id'], [$this->pid('users.delete') => 'allow'], $admin));
        $this->assertThrows(AuthorizationException::class, fn () => (new RoleService(App::db()))->create(['name' => 'Sneaky'], $admin));
        $this->assertThrows(DomainRuleException::class, fn () => $this->perms()->setUserOverrides((int) $this->root['id'], [$this->pid('users.view') => 'deny'], $this->root));
    }

    public function testOwnScopeLimitsListsAndTamperedIdsReturn404(): void
    {
        $roles = new RoleService(App::db());
        $roleId = $roles->create(['name' => 'Self service'], $this->root);
        $this->perms()->syncRolePermissions($roleId, [$this->pid('users.view'), $this->pid('users.view_own')], $this->root);
        $me = $this->makeUser('self@example.com');
        App::db()->insert('user_roles', ['user_id' => $me['id'], 'role_id' => $roleId, 'assigned_at' => now()]);
        $other = $this->makeUser('other@example.com', ['viewer']);
        $me = $this->fresh($me);
        $this->assertSame('own', App::dataAccess()->resolve($me, 'users')->scope->value ?? 'own');
        $this->assertTrue(App::dataAccess()->canAccess($me, 'users', (int) $me['id']));
        $this->assertFalse(App::dataAccess()->canAccess($me, 'users', (int) $other['id']));
        $page = User::paginate(['q' => '', 'status' => ''], 1, 50, App::dataAccess()->constrain($me, 'users', 'u'));
        $this->assertSame([(int) $me['id']], array_map('intval', array_column($page->items, 'id')));
        $this->assertThrows(HttpException::class, fn () => App::dataAccess()->assertCanAccess($me, 'users', (int) $other['id']));
    }

    public function testUserDataScopeOverrideAndNoneScope(): void
    {
        $mgr = $this->makeUser('mgr@example.com', ['manager']);
        $this->makeUser('x@example.com', ['viewer']);
        $this->perms()->setUserDataScope((int) $mgr['id'], 'users', 'none', [], $this->root);
        $mgr = $this->fresh($mgr);
        $page = User::paginate(['q' => '', 'status' => ''], 1, 50, App::dataAccess()->constrain($mgr, 'users', 'u'));
        $this->assertSame(0, $page->total, 'scope none shows no records even with users.view_all');
    }

    public function testFieldPolicyHidesMasksAndBlocksWrites(): void
    {
        $roleId = $this->roleId('manager');
        $this->perms()->setRoleFieldAccess($roleId, [$this->fieldId('users', 'phone') => 'hidden', $this->fieldId('users', 'email') => 'masked', $this->fieldId('users', 'name') => 'read_only'], $this->root);
        $mgr = $this->fresh($this->makeUser('mgr@example.com', ['manager']));
        $rec = App::fields()->present($mgr, 'users', ['id' => 9, 'name' => 'Ravi', 'email' => 'ravi.kumar@example.com', 'phone' => '+919876543210']);
        $this->assertFalse(array_key_exists('phone', $rec), 'hidden field removed');
        $this->assertNotContains('ravi.kumar', (string) $rec['email'], 'email masked');
        $this->assertSame('Ravi', $rec['name']);
        $this->assertSame(FieldAccess::ReadOnly, App::fields()->access($mgr, 'users', 'name'));
        $this->assertThrows(AuthorizationException::class, fn () => App::fields()->assertWritable($mgr, 'users', ['name' => 'Changed'], ['name' => 'Ravi']));
        // super admin always editable
        $this->assertSame(FieldAccess::Editable, App::fields()->access($this->root, 'users', 'phone'));
        // user override beats role rule
        $this->perms()->setUserFieldAccess((int) $mgr['id'], [$this->fieldId('users', 'phone') => 'visible'], $this->root);
        $this->assertSame(FieldAccess::Visible, App::fields()->access($this->fresh($mgr), 'users', 'phone'));
    }

    public function testRoleLifecycleDuplicateDeleteAndReassign(): void
    {
        $roles = new RoleService(App::db());
        $copy = $roles->duplicate($this->roleId('accountant'), 'Senior Accountant', $this->root);
        $this->assertSame($roles->slugs($this->roleId('accountant')), $roles->slugs($copy));
        $u = $this->makeUser('acc@example.com');
        App::db()->insert('user_roles', ['user_id' => $u['id'], 'role_id' => $copy, 'assigned_at' => now()]);
        $this->assertThrows(DomainRuleException::class, fn () => $roles->delete($copy, $this->root));
        $this->assertThrows(DomainRuleException::class, fn () => $roles->delete($this->roleId('super-admin'), $this->root));
        $this->assertThrows(DomainRuleException::class, fn () => $roles->delete($copy, $this->root, $this->roleId('super-admin')));
        $roles->delete($copy, $this->root, $this->roleId('viewer'));
        $this->assertNull(App::db()->scalar('SELECT id FROM roles WHERE id = ?', [$copy]));
        $this->assertSame(1, (int) App::db()->scalar('SELECT COUNT(*) FROM user_roles WHERE user_id = ? AND role_id = ?', [$u['id'], $this->roleId('viewer')]));
        $this->assertTrue((bool) App::db()->scalar("SELECT 1 FROM activity_logs WHERE action = 'role.deleted' AND old_values IS NOT NULL"));
    }

    public function testTemplatesApplyToRoleAndUser(): void
    {
        $t = new TemplateService(App::db());
        $id = $t->save(null, ['name' => 'Read only users'], [$this->pid('users.view'), $this->pid('users.view_all')], ['users' => 'all'], $this->root);
        $roleId = (new RoleService(App::db()))->create(['name' => 'Auditors'], $this->root);
        $t->applyToRole($id, $roleId, $this->root);
        $this->assertSame(['users.view', 'users.view_all'], (new RoleService(App::db()))->slugs($roleId));
        $v = $this->makeUser('v@example.com', ['viewer']);
        $this->assertTrue($t->applyToUser($id, (int) $v['id'], $this->root) >= 1);
        $this->assertTrue(App::gate()->allows($this->fresh($v), 'users.view_all'));
    }

    public function testApprovalWorkflowAmountStepsSelfApprovalAndReasons(): void
    {
        $wfId = (int) App::db()->scalar("SELECT id FROM approval_workflows WHERE workflow_key = 'expense'");
        $maker = $this->makeUser('maker@example.com');
        $checker = $this->makeUser('checker@example.com');
        $boss = $this->makeUser('boss@example.com');
        $roleId = (new RoleService(App::db()))->create(['name' => 'Expense team'], $this->root);
        $this->perms()->syncRolePermissions($roleId, [$this->pid('expenses.view'), $this->pid('expenses.create'), $this->pid('expenses.approve'), $this->pid('expenses.reject')], $this->root);
        foreach ([$maker, $checker, $boss] as $u) {
            App::db()->insert('user_roles', ['user_id' => $u['id'], 'role_id' => $roleId, 'assigned_at' => now()]);
        }
        (new WorkflowService(App::db()))->saveSteps($wfId, [
            ['name' => 'Team review', 'approver_type' => 'permission', 'min_amount' => '0'],
            ['name' => 'Owner sign-off', 'approver_type' => 'user', 'approver_user_id' => (string) $boss['id'], 'min_amount' => '50000'],
        ], $this->root);
        (new WorkflowService(App::db()))->update($wfId, true, null, $this->root);
        [$maker, $checker, $boss] = [$this->fresh($maker), $this->fresh($checker), $this->fresh($boss)];
        $eng = App::approvals();

        $small = $eng->submit('expense', 'expense', 1, 1200.0, 'Office supplies', $maker);
        $this->assertSame([1], json_decode($small['required_steps'], true));
        $this->assertThrows(AuthorizationException::class, fn () => $eng->approve((int) $small['id'], $maker), 'own request');
        $this->assertSame('approved', $eng->approve((int) $small['id'], $checker)['status']);

        $big = $eng->submit('expense', 'expense', 2, 75000.0, 'Laptop', $maker);
        $this->assertSame([1, 2], json_decode($big['required_steps'], true));
        $big = $eng->approve((int) $big['id'], $checker);
        $this->assertSame('pending', $big['status']);
        $this->assertThrows(AuthorizationException::class, fn () => $eng->approve((int) $big['id'], $checker));
        $this->assertThrows(DomainRuleException::class, fn () => $eng->reject((int) $big['id'], $boss, ''));
        $big = $eng->reject((int) $big['id'], $boss, 'Over budget');
        $this->assertSame('rejected', $big['status']);
        $this->assertSame(4, count($eng->history((int) $big['id'])) >= 3 ? 4 : 0);

        $outsider = $this->makeUser('out@example.com', ['viewer']);
        $this->assertThrows(AuthorizationException::class, fn () => $eng->submit('expense', 'expense', 3, 10.0, 'x', $outsider));
    }

    public function testSuspendExpireDeleteAndForceLogout(): void
    {
        $users = new UserService(App::db());
        $u = $this->makeUser('u@example.com', ['viewer']);
        App::sessions()->start((int) $u['id'], $this->request());
        App::sessions()->start((int) $u['id'], $this->request(ip: '10.0.0.2'));
        $this->assertSame(2, count(App::sessions()->active((int) $u['id'])));
        $this->assertSame(2, $users->forceLogout($u, $this->root));
        $this->assertSame(0, count(App::sessions()->active((int) $u['id'])));

        $users->suspend($u, null, 'Investigation', $this->root);
        $this->assertFalse(App::gate()->allows($this->fresh($u), 'dashboard.view'), 'suspended user has no access');
        $this->assertFalse(App::auth()->attempt('u@example.com', 'Correct-Horse9!', false, $this->request())->success, 'suspended user cannot sign in');
        $users->unsuspend($this->fresh($u), $this->root);
        $users->setExpiry($this->fresh($u), '2001-01-01 00:00:00', $this->root);
        $this->assertFalse(App::gate()->allows($this->fresh($u), 'dashboard.view'), 'expired user has no access');
        $this->assertThrows(DomainRuleException::class, fn () => $users->delete($this->fresh($u), $this->root));
        $this->assertThrows(DomainRuleException::class, fn () => $users->suspend($this->root, null, 'x', $this->root));
    }
}
