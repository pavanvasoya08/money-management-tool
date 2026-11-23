<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Exceptions\AuthorizationException;
use Tests\TestCase;

final class GateTest extends TestCase
{
    public function setUp(): void
    {
        $this->freshDatabase();
    }

    private function permId(string $slug): int
    {
        return (int) App::db()->scalar('SELECT id FROM permissions WHERE slug = ?', [$slug]);
    }

    public function testSuperAdminHasUnrestrictedAccess(): void
    {
        $admin = $this->makeUser('root@example.com', ['super-admin']);
        $gate = App::gate();
        $this->assertTrue($gate->isSuperAdmin($admin));
        $this->assertTrue($gate->allows($admin, 'settings.edit'));
        $this->assertTrue($gate->allows($admin, 'some.future_permission'));
        $this->assertTrue(App::dataAccess()->resolve($admin, 'sales_orders')->all);
    }

    public function testNewUserWithoutRolesHasNoAccess(): void
    {
        $user = $this->makeUser('new@example.com');
        $this->assertFalse(App::gate()->allows($user, 'dashboard.view'));
        $this->assertSame([], App::gate()->permissionSlugs($user));
    }

    public function testRoleGrantsAndMultipleRolesUnion(): void
    {
        $viewer = $this->makeUser('v@example.com', ['viewer']);
        $this->assertTrue(App::gate()->allows($viewer, 'dashboard.view'));
        $this->assertFalse(App::gate()->allows($viewer, 'users.view'));

        $multi = $this->makeUser('m@example.com', ['viewer', 'manager']);
        $this->assertTrue(App::gate()->allows($multi, 'users.view'));
        $this->assertTrue(App::gate()->allows($multi, 'activity_logs.view'));
        $this->assertFalse(App::gate()->allows($multi, 'settings.edit'));
    }

    public function testUserOverridesAllowDenyAndExpiry(): void
    {
        $manager = $this->makeUser('mgr@example.com', ['manager']);
        $db = App::db();
        $db->insert('user_permission_overrides', ['user_id' => $manager['id'], 'permission_id' => $this->permId('users.view'), 'effect' => 'deny', 'created_at' => now(), 'updated_at' => now()]);
        $db->insert('user_permission_overrides', ['user_id' => $manager['id'], 'permission_id' => $this->permId('settings.view'), 'effect' => 'allow', 'data_scope' => 'own', 'created_at' => now(), 'updated_at' => now()]);
        $db->insert('user_permission_overrides', ['user_id' => $manager['id'], 'permission_id' => $this->permId('settings.edit'), 'effect' => 'allow', 'expires_at' => now('-1 day'), 'created_at' => now(), 'updated_at' => now()]);
        App::gate()->flush();

        $this->assertFalse(App::gate()->allows($manager, 'users.view'), 'deny wins over role grant');
        $this->assertTrue(App::gate()->allows($manager, 'settings.view'), 'allow override adds permission');
        $this->assertSame('allow', App::gate()->explain($manager, 'settings.view')['source']);
        $this->assertSame('deny', App::gate()->explain($manager, 'users.view')['source']);
        $this->assertFalse(App::gate()->allows($manager, 'settings.edit'), 'expired override ignored');
    }

    public function testInactiveUsersAndInactiveRolesGrantNothing(): void
    {
        $user = $this->makeUser('x@example.com', ['manager'], status: 'inactive');
        $this->assertFalse(App::gate()->allows($user, 'dashboard.view'));

        $active = $this->makeUser('y@example.com', ['manager']);
        App::db()->update('roles', ['is_active' => 0], ['slug' => 'manager']);
        App::gate()->flush();
        $this->assertFalse(App::gate()->allows($active, 'users.view'));
    }

    public function testAllowOverridesCanBeDisabledBySuperAdminSetting(): void
    {
        $viewer = $this->makeUser('v@example.com', ['viewer']);
        App::db()->insert('user_permission_overrides', ['user_id' => $viewer['id'], 'permission_id' => $this->permId('users.view'), 'effect' => 'allow', 'created_at' => now(), 'updated_at' => now()]);
        App::flushAuthorization();
        $this->assertTrue(App::gate()->allows($viewer, 'users.view'));
        App::db()->update('system_settings', ['value' => '0'], ['setting_key' => 'allow_user_level_grants']);
        App::forget(\App\Services\SettingsService::class);
        App::flushAuthorization();
        $this->assertFalse(App::gate()->allows($viewer, 'users.view'), 'explicit allow ignored when disabled');
    }

    public function testDisabledAndReservedModules(): void
    {
        $mgr = $this->makeUser('m@example.com', ['manager']);
        $this->assertTrue(App::gate()->allows($mgr, 'inventory.view'));
        App::db()->update('modules', ['is_active' => 0], ['module_key' => 'inventory']);
        App::flushAuthorization();
        $this->assertFalse(App::gate()->allows($mgr, 'inventory.view'));
        // super_admin module can never be delegated, even if a grant row exists
        $now = now();
        App::db()->insert('role_permissions', ['role_id' => App::db()->scalar("SELECT id FROM roles WHERE slug='manager'"), 'permission_id' => $this->permId('super_admin.view'), 'created_at' => $now, 'updated_at' => $now]);
        App::flushAuthorization();
        $this->assertFalse(App::gate()->allows($mgr, 'super_admin.view'));
        $this->assertSame('reserved', App::gate()->explain($mgr, 'super_admin.view')['source']);
    }

    public function testSuspendedExpiredAndDeletedAccountsGetNothing(): void
    {
        $root = $this->makeUser('root@example.com', ['super-admin']);
        foreach ([['suspended_at' => now()], ['account_expires_at' => now('-1 minute')], ['deleted_at' => now()]] as $change) {
            $u = $this->makeUser(uniqid('u') . '@example.com', ['manager']);
            App::db()->update('users', $change, ['id' => $u['id']]);
            $u = \App\Models\User::find((int) $u['id']);
            $this->assertFalse(App::gate()->allows($u, 'dashboard.view'));
        }
        App::db()->update('users', ['suspended_at' => now('-2 days'), 'suspended_until' => now('-1 day')], ['id' => $root['id']]);
        $this->assertTrue(App::gate()->allows(\App\Models\User::find((int) $root['id']), 'settings.edit'), 'suspension ended');
    }

    public function testAuthorizeThrowsAndOwnership(): void
    {
        $viewer = $this->makeUser('v@example.com', ['viewer']);
        $this->assertThrows(AuthorizationException::class, fn () => App::gate()->authorize($viewer, 'users.create'));
        $this->assertThrows(AuthorizationException::class, fn () => App::gate()->authorize(null, 'dashboard.view'));
        $this->assertTrue(App::gate()->owns($viewer, ['created_by' => $viewer['id']]));
        $this->assertFalse(App::gate()->owns($viewer, ['created_by' => 999]));
    }

    public function testNonSuperAdminCannotManageSuperAdmin(): void
    {
        $root = $this->makeUser('root@example.com', ['super-admin']);
        $now = now();
        $roleId = App::db()->insert('roles', ['name' => 'HR', 'slug' => 'hr', 'created_at' => $now, 'updated_at' => $now]);
        foreach (['users.edit', 'users.change_status'] as $p) {
            App::db()->insert('role_permissions', ['role_id' => $roleId, 'permission_id' => $this->permId($p), 'data_scope' => 'all', 'created_at' => $now, 'updated_at' => $now]);
        }
        $hr = $this->makeUser('hr@example.com', ['hr']);
        $plain = $this->makeUser('plain@example.com', ['viewer']);
        $this->assertTrue(App::gate()->allows($hr, 'users.edit', $plain));
        $this->assertFalse(App::gate()->allows($hr, 'users.edit', $root));
        $this->assertFalse(App::gate()->allows($hr, 'users.change_status', $root));
    }
}
