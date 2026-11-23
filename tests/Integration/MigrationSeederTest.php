<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Migrator;
use App\Core\Schema\Schema;
use Database\Seeders\DatabaseSeeder;
use Tests\TestCase;

final class MigrationSeederTest extends TestCase
{
    public function setUp(): void
    {
        $this->freshDatabase();
    }

    public function testAllTablesExist(): void
    {
        $schema = new Schema(App::db());
        foreach (['users', 'roles', 'permissions', 'user_roles', 'role_permissions', 'user_permission_overrides', 'login_attempts', 'password_reset_tokens', 'activity_logs', 'system_settings', 'remember_tokens', 'migrations'] as $t) {
            $this->assertTrue($schema->hasTable($t), "missing table {$t}");
        }
    }

    public function testSeedersCreateRolesPermissionsSettingsButNoUsers(): void
    {
        $db = App::db();
        $this->assertSame(5, (int) $db->scalar('SELECT COUNT(*) FROM roles'));
        $this->assertSame(['accountant', 'manager', 'partner', 'super-admin', 'viewer'], array_column($db->select('SELECT slug FROM roles ORDER BY slug'), 'slug'));
        $this->assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM roles WHERE is_super_admin = 1'));
        $this->assertSame(11, (int) $db->scalar('SELECT COUNT(*) FROM permissions'));
        $this->assertSame(11, (int) $db->scalar('SELECT COUNT(*) FROM system_settings'));
        $this->assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM users'), 'no hardcoded user accounts');
    }

    public function testSeedersAreIdempotentAndPreserveAdminChanges(): void
    {
        $db = App::db();
        $db->update('system_settings', ['value' => '7'], ['setting_key' => 'login_max_attempts']);
        (new DatabaseSeeder($db))->run();
        (new DatabaseSeeder($db))->run();
        $this->assertSame(5, (int) $db->scalar('SELECT COUNT(*) FROM roles'));
        $this->assertSame(11, (int) $db->scalar('SELECT COUNT(*) FROM permissions'));
        $this->assertSame(7, (int) $db->scalar('SELECT COUNT(*) FROM role_permissions'));
        $this->assertSame('7', (string) $db->scalar("SELECT value FROM system_settings WHERE setting_key = 'login_max_attempts'"));
    }

    public function testRollbackAndRemigrate(): void
    {
        $migrator = new Migrator(App::db(), App::basePath('database/migrations'));
        $rolled = $migrator->rollback(1);
        $this->assertSame(11, count($rolled));
        $this->assertFalse((new Schema(App::db()))->hasTable('users'));
        $this->assertSame(11, count($migrator->migrate()));
        $this->assertSame([], $migrator->migrate());
    }
}
