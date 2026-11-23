<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Exceptions\AuthorizationException;
use App\Services\Authorization\DataScope;
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
        $this->assertSame(DataScope::All, $gate->dataScope($admin, 'sales.view'));
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
        $this->assertSame(DataScope::Own, App::gate()->dataScope($manager, 'settings.view'));
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

    public function testConstraintsAndWidestScopeMerge(): void
    {
        $db = App::db();
        $now = now();
        $r1 = $db->insert('roles', ['name' => 'GST Clerk A', 'slug' => 'clerk-a', 'created_at' => $now, 'updated_at' => $now]);
        $r2 = $db->insert('roles', ['name' => 'GST Clerk B', 'slug' => 'clerk-b', 'created_at' => $now, 'updated_at' => $now]);
        $pid = $this->permId('users.view');
        $db->insert('role_permissions', ['role_id' => $r1, 'permission_id' => $pid, 'data_scope' => 'own', 'constraints' => '{"gst_registration_ids":[1]}', 'created_at' => $now, 'updated_at' => $now]);
        $db->insert('role_permissions', ['role_id' => $r2, 'permission_id' => $pid, 'data_scope' => 'assigned', 'constraints' => '{"gst_registration_ids":[2],"bank_account_ids":[5]}', 'created_at' => $now, 'updated_at' => $now]);
        $user = $this->makeUser('clerk@example.com', ['clerk-a', 'clerk-b']);
        $this->assertSame(DataScope::Assigned, App::gate()->dataScope($user, 'users.view'));
        $c = App::gate()->constraints($user, 'users.view');
        sort($c['gst_registration_ids']);
        $this->assertSame([1, 2], $c['gst_registration_ids']);
        $this->assertSame([5], $c['bank_account_ids']);
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
