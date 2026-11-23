<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\Admin\PermissionService;

/** Phase 2 security cases exercised through real HTTP requests. */
final class ControlCenterSecurityTest extends HttpTestCase
{
    private function pid(string $slug): int
    {
        return (int) App::db()->scalar('SELECT id FROM permissions WHERE slug = ?', [$slug]);
    }

    private function fieldId(string $field): int
    {
        return (int) App::db()->scalar("SELECT f.id FROM module_fields f JOIN modules m ON m.id = f.module_id WHERE m.module_key = 'users' AND f.field_key = ?", [$field]);
    }

    private function roleId(string $slug): int
    {
        return (int) App::db()->scalar('SELECT id FROM roles WHERE slug = ?', [$slug]);
    }

    public function testNonSuperAdminsAreBlockedFromEveryControlCenterRoute(): void
    {
        $root = $this->makeUser('root@example.com', ['super-admin']);
        $partner = $this->makeUser('partner@example.com', ['partner']);
        // even an explicit allow on every user permission does not open the Control Center
        $all = array_column(App::db()->select("SELECT p.id FROM permissions p JOIN modules m ON m.id = p.module_id WHERE m.module_key = 'users'"), 'id');
        (new PermissionService(App::db()))->setUserOverrides((int) $partner['id'], array_fill_keys(array_map('intval', $all), 'allow'), $root);
        $b = $this->browser();
        $this->signIn($b, 'partner@example.com');
        foreach (['/admin', '/admin/roles', '/admin/permissions', '/admin/users/' . $root['id'], '/admin/preview', '/admin/audit', '/admin/security', '/admin/sessions'] as $path) {
            $this->assertSame(403, $b->get($path)['status'], $path);
        }
        $b->get('/dashboard');
        $r = $b->post('/admin/users/' . $partner['id'] . '/overrides', ['overrides' => [$this->pid('super_admin.view') => 'allow']]);
        $this->assertSame(403, $r['status'], 'cannot self-elevate through the admin endpoint');
        $this->assertSame(0, (int) App::db()->scalar('SELECT COUNT(*) FROM user_permission_overrides WHERE user_id = ? AND permission_id = ?', [$partner['id'], $this->pid('super_admin.view')]));
    }

    public function testSuperAdminCannotChangeOwnAccess(): void
    {
        $root = $this->makeUser('root@example.com', ['super-admin']);
        $b = $this->browser();
        $this->signIn($b, 'root@example.com');
        $b->get('/admin/users/' . $root['id']);
        $b->post('/admin/users/' . $root['id'] . '/suspend', ['reason' => 'test']);
        $b->post('/admin/users/' . $root['id'] . '/overrides', ['overrides' => [$this->pid('users.view') => 'deny']]);
        $this->assertSame(0, (int) App::db()->scalar('SELECT COUNT(*) FROM user_permission_overrides WHERE user_id = ?', [$root['id']]));
        $this->assertNull(App::db()->scalar('SELECT suspended_at FROM users WHERE id = ?', [$root['id']]));
    }

    public function testApiAndExportNeverReturnHiddenFields(): void
    {
        $root = $this->makeUser('root@example.com', ['super-admin']);
        $mgr = $this->makeUser('mgr@example.com', ['manager']);
        App::db()->update('users', ['phone' => '+919812345678'], ['id' => $mgr['id']]);
        $perm = new PermissionService(App::db());
        $perm->setRoleFieldAccess($this->roleId('manager'), [$this->fieldId('phone') => 'hidden', $this->fieldId('email') => 'masked'], $root);
        $b = $this->browser();
        $this->signIn($b, 'mgr@example.com');
        $api = $b->get('/api/users/' . $mgr['id']);
        $this->assertSame(200, $api['status']);
        $json = json_decode($api['body'], true);
        $this->assertFalse(array_key_exists('phone', $json['data']), 'hidden field absent from API');
        $this->assertNotContains('9812345678', $api['body']);
        $this->assertNotContains('mgr@example.com', $api['body'], 'masked email');
        $this->assertNotContains('password', $api['body']);
        $this->assertSame(403, $b->get('/users/export')['status'], 'export needs users.export');

        $perm->setUserOverrides((int) $mgr['id'], [$this->pid('users.export') => 'allow'], $root);
        $csv = $b->get('/users/export');
        $this->assertSame(200, $csv['status']);
        $this->assertNotContains('Phone', $csv['body'], 'hidden column dropped from header');
        $this->assertNotContains('9812345678', $csv['body']);
    }

    public function testApiRequiresAuthenticationAndTamperedIdsAre404(): void
    {
        $root = $this->makeUser('root@example.com', ['super-admin']);
        $b = $this->browser();
        $this->assertSame(401, $b->get('/api/users')['status']);
        $self = $this->makeUser('self@example.com');
        $other = $this->makeUser('other@example.com');
        $roleId = App::db()->insert('roles', ['name' => 'Self', 'slug' => 'self', 'is_system' => 0, 'is_super_admin' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        (new PermissionService(App::db()))->syncRolePermissions($roleId, [$this->pid('dashboard.view'), $this->pid('users.view'), $this->pid('users.view_own')], $root);
        App::db()->insert('user_roles', ['user_id' => $self['id'], 'role_id' => $roleId, 'assigned_at' => now()]);
        $this->signIn($b, 'self@example.com');
        $this->assertSame(200, $b->get('/users/' . $self['id'])['status']);
        $this->assertSame(404, $b->get('/users/' . $other['id'])['status'], 'other record hidden as 404');
        $this->assertSame(404, $b->get('/api/users/' . $other['id'])['status']);
        $list = json_decode($b->get('/api/users')['body'], true);
        $this->assertSame([(int) $self['id']], array_map(static fn ($u) => (int) $u['id'], $list['data']));
    }

    public function testCraftedWriteToReadOnlyFieldIsRejected(): void
    {
        $root = $this->makeUser('root@example.com', ['super-admin']);
        $editor = $this->makeUser('editor@example.com', ['partner']);
        $target = $this->makeUser('target@example.com', ['viewer'], name: 'Original Name');
        $perm = new PermissionService(App::db());
        $perm->setUserOverrides((int) $editor['id'], [$this->pid('users.edit') => 'allow', $this->pid('users.view') => 'allow', $this->pid('users.view_all') => 'allow'], $root);
        $perm->setUserFieldAccess((int) $editor['id'], [$this->fieldId('name') => 'read_only'], $root);
        $b = $this->browser();
        $this->signIn($b, 'editor@example.com');
        $form = $b->get('/users/' . $target['id'] . '/edit');
        $this->assertSame(200, $form['status']);
        $this->assertNotContains('name="name"', $form['body'], 'read-only input is not submitted');
        $r = $b->post('/users/' . $target['id'], ['name' => 'Hacked Name', 'email' => 'target@example.com']);
        $this->assertSame(403, $r['status']);
        $this->assertSame('Original Name', App::db()->scalar('SELECT name FROM users WHERE id = ?', [$target['id']]));
    }

    public function testForcedLogoutEndsTheSessionImmediately(): void
    {
        $root = $this->makeUser('root@example.com', ['super-admin']);
        $victim = $this->makeUser('victim@example.com', ['viewer']);
        $vb = $this->browser();
        $this->signIn($vb, 'victim@example.com');
        $this->assertSame(200, $vb->get('/dashboard')['status']);
        $ab = $this->browser();
        $this->signIn($ab, 'root@example.com');
        $ab->get('/admin/users/' . $victim['id']);
        $this->assertSame(302, $ab->post('/admin/users/' . $victim['id'] . '/force-logout')['status']);
        $after = $vb->get('/dashboard');
        $this->assertSame(302, $after['status']);
        $this->assertContains('/login', $after['location']);
    }

    public function testPreviewIsReadOnlyAndDoesNotSwitchIdentity(): void
    {
        $this->makeUser('root@example.com', ['super-admin'], name: 'Root Admin');
        $v = $this->makeUser('viewer@example.com', ['viewer'], name: 'Vee Viewer');
        $b = $this->browser();
        $this->signIn($b, 'root@example.com');
        $p = $b->get('/admin/preview?user=' . $v['id']);
        $this->assertSame(200, $p['status']);
        $this->assertContains('Vee Viewer', $p['body']);
        $this->assertSame(200, $b->get('/admin')['status'], 'still signed in as the super admin');
        $this->assertSame(0, (int) App::db()->scalar("SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL", [$v['id']]));
        $this->assertTrue((bool) App::db()->scalar("SELECT 1 FROM activity_logs WHERE action LIKE 'access.preview%'"));
    }
}
