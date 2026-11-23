<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Services\UserService;
use Tests\TestCase;

final class UserServiceTest extends TestCase
{
    private UserService $svc;

    public function setUp(): void
    {
        $this->freshDatabase();
        $this->svc = new UserService();
    }

    private function roleId(string $slug): int
    {
        return (int) App::db()->scalar('SELECT id FROM roles WHERE slug = ?', [$slug]);
    }

    private function managerWithUserAdmin(): array
    {
        $now = now();
        $roleId = App::db()->insert('roles', ['name' => 'User Admin', 'slug' => 'user-admin', 'created_at' => $now, 'updated_at' => $now]);
        foreach (['users.view', 'users.create', 'users.edit', 'users.change_status', 'users.assign_roles', 'users.reset_password'] as $slug) {
            $pid = App::db()->scalar('SELECT id FROM permissions WHERE slug = ?', [$slug]);
            App::db()->insert('role_permissions', ['role_id' => $roleId, 'permission_id' => $pid, 'data_scope' => 'all', 'created_at' => $now, 'updated_at' => $now]);
        }
        return $this->makeUser('ua@example.com', ['user-admin']);
    }

    public function testCreateUserWithRolesHashesPasswordAndLogs(): void
    {
        $root = $this->makeUser('root@example.com', ['super-admin']);
        $id = $this->svc->create(['name' => 'Ravi Kumar', 'email' => 'Ravi@Example.com', 'phone' => null, 'password' => 'Temp-Pass#2026x', 'roles' => [(string) $this->roleId('accountant'), (string) $this->roleId('viewer')]], $root);
        $user = User::find($id);
        $this->assertSame('ravi@example.com', $user['email']);
        $this->assertSame(1, (int) $user['must_change_password']);
        $this->assertSame((int) $root['id'], (int) $user['created_by']);
        $this->assertSame(['accountant', 'viewer'], array_column(User::roles($id), 'slug'));
        $hash = (string) App::db()->scalar('SELECT password_hash FROM users WHERE id = ?', [$id]);
        $this->assertNotContains('Temp-Pass#2026x', $hash);
        $this->assertSame('user.created', App::db()->scalar('SELECT action FROM activity_logs ORDER BY id DESC LIMIT 1'));
    }

    public function testWeakPasswordRejected(): void
    {
        $root = $this->makeUser('root@example.com', ['super-admin']);
        $this->assertThrows(ValidationException::class, fn () => $this->svc->create(['name' => 'X Y', 'email' => 'x@example.com', 'password' => 'password'], $root));
    }

    public function testOnlySuperAdminCanCreateOrPromoteSuperAdmins(): void
    {
        $ua = $this->managerWithUserAdmin();
        $this->assertThrows(AuthorizationException::class, fn () => $this->svc->create(['name' => 'Evil', 'email' => 'e@example.com', 'password' => 'Temp-Pass#2026x', 'roles' => [$this->roleId('super-admin')]], $ua), 'Super Admin');
        $plain = $this->makeUser('plain@example.com', ['viewer']);
        $this->assertThrows(AuthorizationException::class, fn () => $this->svc->syncRoles($plain, [$this->roleId('super-admin')], $ua));
        $this->assertFalse(App::gate()->isSuperAdmin($plain));
    }

    public function testUserWithoutAssignRolesPermissionCannotAssign(): void
    {
        $mgr = $this->makeUser('mgr@example.com', ['manager']);
        $this->assertThrows(AuthorizationException::class, fn () => $this->svc->create(['name' => 'Z Z', 'email' => 'z@example.com', 'password' => 'Temp-Pass#2026x', 'roles' => [$this->roleId('viewer')]], $mgr));
    }

    public function testCannotChangeOwnRolesOrStatus(): void
    {
        $root = $this->makeUser('root@example.com', ['super-admin']);
        $this->assertThrows(DomainRuleException::class, fn () => $this->svc->syncRoles($root, [], $root), 'own roles');
        $this->assertThrows(DomainRuleException::class, fn () => $this->svc->setStatus($root, 'inactive', $root), 'own account');
    }

    public function testLastSuperAdminIsProtected(): void
    {
        $root = $this->makeUser('root@example.com', ['super-admin']);
        $second = $this->makeUser('root2@example.com', ['super-admin']);
        // Second can demote first while two exist...
        $this->svc->syncRoles($root, [$this->roleId('viewer')], $second);
        $this->assertFalse(App::gate()->isSuperAdmin($root));
        // ...but cannot be demoted/deactivated by anyone once they are the last one.
        $third = $this->makeUser('root3@example.com', ['super-admin']);
        $this->svc->setStatus($third, 'inactive', $second);
        $this->assertThrows(DomainRuleException::class, fn () => $this->svc->setStatus($second, 'inactive', $third), 'last active Super Admin');
    }

    public function testRoleChangeIsAudited(): void
    {
        $root = $this->makeUser('root@example.com', ['super-admin']);
        $u = $this->makeUser('u@example.com', ['viewer']);
        $this->svc->syncRoles($u, [$this->roleId('accountant')], $root);
        $props = json_decode((string) App::db()->scalar("SELECT properties FROM activity_logs WHERE action = 'user.roles_changed'"), true);
        $this->assertSame(['viewer'], $props['from']);
        $this->assertSame(['accountant'], $props['to']);
    }

    public function testDeactivationRevokesRememberTokensAndBlocksLogin(): void
    {
        $root = $this->makeUser('root@example.com', ['super-admin']);
        $u = $this->makeUser('u@example.com', ['viewer']);
        (new \App\Services\RememberMeService())->issue((int) $u['id'], $this->request());
        $this->svc->setStatus($u, 'inactive', $root);
        $this->assertSame(0, (int) App::db()->scalar('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ?', [$u['id']]));
        $this->assertSame(1, (int) App::db()->scalar("SELECT COUNT(*) FROM activity_logs WHERE action = 'user.deactivated' AND subject_id = ?", [$u['id']]));
        $this->assertFalse(App::auth()->attempt('u@example.com', 'Correct-Horse9!', false, $this->request())->success);
    }

    public function testChangeOwnPassword(): void
    {
        $u = $this->makeUser('u@example.com', ['viewer']);
        App::auth()->attempt('u@example.com', 'Correct-Horse9!', false, $this->request());
        $this->assertThrows(ValidationException::class, fn () => $this->svc->changeOwnPassword($u, 'wrong', 'N3w-Secure#Pass'));
        $this->assertThrows(ValidationException::class, fn () => $this->svc->changeOwnPassword($u, 'Correct-Horse9!', 'Correct-Horse9!'));
        $this->svc->changeOwnPassword($u, 'Correct-Horse9!', 'N3w-Secure#Pass');
        App::auth()->logout($this->request());
        $this->assertFalse(App::auth()->attempt('u@example.com', 'Correct-Horse9!', false, $this->request())->success);
        $this->assertTrue(App::auth()->attempt('u@example.com', 'N3w-Secure#Pass', false, $this->request())->success);
    }

    public function testAdminPasswordResetForcesChange(): void
    {
        $root = $this->makeUser('root@example.com', ['super-admin']);
        $u = $this->makeUser('u@example.com', ['viewer']);
        $this->svc->adminResetPassword($u, 'Temp-Reset#2026', $root);
        $this->assertSame(1, (int) User::find((int) $u['id'])['must_change_password']);
        $this->assertThrows(DomainRuleException::class, fn () => $this->svc->adminResetPassword($root, 'Temp-Reset#2026', $root));
    }
}
