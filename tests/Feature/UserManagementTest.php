<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;

final class UserManagementTest extends HttpTestCase
{
    private function roleId(string $slug): int
    {
        return (int) App::db()->scalar('SELECT id FROM roles WHERE slug = ?', [$slug]);
    }

    public function testViewerCannotReachAdminPages(): void
    {
        $this->makeUser('viewer@example.com', ['viewer']);
        $b = $this->browser();
        $this->signIn($b, 'viewer@example.com');
        foreach (['/users', '/users/create', '/settings', '/activity', '/users/login-history'] as $path) {
            $this->assertSame(403, $b->get($path)['status'], $path);
        }
        $dash = $b->get('/dashboard');
        $this->assertSame(200, $dash['status']);
        $this->assertNotContains('Total users', $dash['body']);
        $this->assertNotContains('href="/users"', $dash['body'], 'menu hides unauthorized modules');
        $r = $b->post('/users', ['name' => 'Hack', 'email' => 'h@example.com', 'password' => 'Hack-Attempt#1', 'password_confirmation' => 'Hack-Attempt#1']);
        $this->assertSame(403, $r['status']);
        $this->assertSame(1, (int) App::db()->scalar("SELECT COUNT(*) FROM activity_logs WHERE action = 'authorization.denied'") >= 1 ? 1 : 0);
    }

    public function testManagerCanViewButNotModify(): void
    {
        $mgr = $this->makeUser('mgr@example.com', ['manager']);
        $other = $this->makeUser('other@example.com', ['viewer']);
        $b = $this->browser();
        $this->signIn($b, 'mgr@example.com');
        $this->assertSame(200, $b->get('/users')['status']);
        $this->assertSame(200, $b->get('/users/' . $other['id'])['status']);
        $this->assertSame(403, $b->get('/users/create')['status']);
        $this->assertSame(403, $b->post('/users/' . $other['id'] . '/status', ['status' => 'inactive'])['status']);
        $this->assertSame('active', App::db()->scalar('SELECT status FROM users WHERE id = ?', [$other['id']]));
        unset($mgr);
    }

    public function testSuperAdminCreatesUserAndOutputIsEscaped(): void
    {
        $this->makeUser('root@example.com', ['super-admin']);
        $b = $this->browser();
        $this->signIn($b, 'root@example.com');
        $b->get('/users/create');
        $r = $b->post('/users', [
            'name' => '<script>alert(1)</script> Mehta', 'email' => 'new.user@example.com', 'phone' => '+91 98765 43210',
            'password' => 'Welcome-Erp#2026', 'password_confirmation' => 'Welcome-Erp#2026',
            'roles' => [(string) $this->roleId('accountant')],
        ]);
        $this->assertSame(302, $r['status']);
        $this->assertContains('/users/', $r['location']);
        $page = $b->get($r['location']);
        $this->assertContains('&lt;script&gt;alert(1)&lt;/script&gt;', $page['body']);
        $this->assertNotContains('<script>alert(1)</script>', $page['body']);
        $this->assertContains('Accountant', $page['body']);
        $list = $b->get('/users?q=mehta');
        $this->assertContains('new.user@example.com', $list['body']);
    }

    public function testValidationErrorsAreShownAndInputKept(): void
    {
        $this->makeUser('root@example.com', ['super-admin']);
        $b = $this->browser();
        $this->signIn($b, 'root@example.com');
        $b->get('/users/create');
        $r = $b->post('/users', ['name' => 'Ok Name', 'email' => 'bad-email', 'password' => 'weak', 'password_confirmation' => 'weak']);
        $this->assertSame(302, $r['status']);
        $form = $b->get('/users/create');
        $this->assertContains('must be a valid email', $form['body']);
        $this->assertContains('value="Ok Name"', $form['body']);
        $this->assertNotContains('value="weak"', $form['body'], 'passwords are never re-filled');
    }

    public function testDuplicateEmailIsCaseInsensitive(): void
    {
        $this->makeUser('root@example.com', ['super-admin']);
        $this->makeUser('taken@example.com');
        $b = $this->browser();
        $this->signIn($b, 'root@example.com');
        $b->get('/users/create');
        $r = $b->post('/users', ['name' => 'Dup User', 'email' => 'TAKEN@Example.com', 'password' => 'Welcome-Erp#2026', 'password_confirmation' => 'Welcome-Erp#2026']);
        $this->assertSame(302, $r['status']);
        $this->assertContains('already been taken', $b->get('/users/create')['body']);
        $this->assertSame(1, (int) App::db()->scalar("SELECT COUNT(*) FROM users WHERE email = 'taken@example.com'"));
    }

    public function testDeactivationSignsUserOutOnNextRequest(): void
    {
        $this->makeUser('root@example.com', ['super-admin']);
        $target = $this->makeUser('staff@example.com', ['viewer']);
        $staff = $this->browser();
        $this->signIn($staff, 'staff@example.com');
        $this->assertSame(200, $staff->get('/dashboard')['status']);

        $admin = $this->browser();
        $this->signIn($admin, 'root@example.com');
        $admin->get('/users/' . $target['id']);
        $r = $admin->post('/users/' . $target['id'] . '/status', ['status' => 'inactive']);
        $this->assertSame(302, $r['status']);

        $this->assertSame(302, $staff->get('/dashboard')['status'], 'deactivated user is signed out');
        $this->assertSame(1, (int) App::db()->scalar("SELECT COUNT(*) FROM activity_logs WHERE action = 'user.deactivated'"));
    }

    public function testAdminCannotChangeOwnStatusOrRolesOverHttp(): void
    {
        $root = $this->makeUser('root@example.com', ['super-admin']);
        $b = $this->browser();
        $this->signIn($b, 'root@example.com');
        $b->get('/users/' . $root['id']);
        $b->post('/users/' . $root['id'] . '/status', ['status' => 'inactive']);
        $b->post('/users/' . $root['id'] . '/roles', ['roles' => ['']]);
        $this->assertSame('active', App::db()->scalar('SELECT status FROM users WHERE id = ?', [$root['id']]));
        $this->assertTrue(App::gate()->isSuperAdmin((int) $root['id']));
    }

    public function testRoleAssignmentAndSettingsUpdate(): void
    {
        $this->makeUser('root@example.com', ['super-admin']);
        $u = $this->makeUser('u@example.com', ['viewer']);
        $b = $this->browser();
        $this->signIn($b, 'root@example.com');
        $b->get('/users/' . $u['id']);
        $b->post('/users/' . $u['id'] . '/roles', ['roles' => [(string) $this->roleId('manager'), (string) $this->roleId('viewer')]]);
        $this->assertSame(['manager', 'viewer'], array_column(\App\Models\User::roles((int) $u['id']), 'slug'));

        $b->get('/settings');
        $settings = App::settings()->all();
        $settings['app_name'] = 'Acme Finance ERP';
        $r = $b->post('/settings', $settings);
        $this->assertSame('/settings', $r['location']);
        $this->assertContains('Acme Finance ERP', $b->get('/dashboard')['body']);
        $this->assertSame(1, (int) App::db()->scalar("SELECT COUNT(*) FROM activity_logs WHERE action = 'settings.updated'"));
    }

    public function testErrorPagesDoNotLeakInternals(): void
    {
        $this->makeUser('root@example.com', ['super-admin']);
        $b = $this->browser();
        $this->signIn($b, 'root@example.com');
        $r = $b->get('/users/99999');
        $this->assertSame(404, $r['status']);
        $this->assertNotContains('Stack trace', $r['body']);
        $this->assertNotContains('.php', $r['body']);
        $this->assertSame(404, $b->get('/users/1%27%20OR%201=1')['status']);
    }
}
