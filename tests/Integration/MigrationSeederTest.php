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
        foreach (['users', 'roles', 'permissions', 'user_roles', 'role_permissions', 'user_permission_overrides', 'login_attempts', 'password_reset_tokens', 'activity_logs', 'system_settings', 'remember_tokens', 'migrations',
            'modules', 'role_data_scopes', 'user_data_scope_overrides', 'module_fields', 'role_field_permissions', 'user_field_overrides', 'user_resource_assignments',
            'approval_workflows', 'approval_workflow_steps', 'approval_requests', 'approval_actions', 'permission_templates', 'permission_template_items',
            'permission_template_scopes', 'user_sessions', 'access_requests'] as $t) {
            $this->assertTrue($schema->hasTable($t), "missing table {$t}");
        }
    }

    public function testSeedersCreateRolesPermissionsSettingsButNoUsers(): void
    {
        $db = App::db();
        $this->assertSame(5, (int) $db->scalar('SELECT COUNT(*) FROM roles'));
        $this->assertSame(['accountant', 'manager', 'partner', 'super-admin', 'viewer'], array_column($db->select('SELECT slug FROM roles ORDER BY slug'), 'slug'));
        $this->assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM roles WHERE is_super_admin = 1'));
        $modules = count((array) \App\Core\Config::get('modules'));
        $actions = array_sum(array_map(static fn (array $m): int => count($m['actions']), (array) \App\Core\Config::get('modules')));
        $this->assertSame(27, $modules); // Phase 3 added withdrawals, adjustments, ledger, profit sharing, partner reports; + Help & User Guide
        $this->assertSame($modules, (int) $db->scalar('SELECT COUNT(*) FROM modules'));
        $this->assertSame($actions, (int) $db->scalar('SELECT COUNT(*) FROM permissions'));
        $this->assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM permissions WHERE module_id IS NULL'), 'every permission belongs to a module');
        // Every definition is seeded, plus the one-time markers of Phase 4 (Partner GST scope) and Phase 5 (bank role defaults).
        $this->assertSame(count(\App\Services\SettingsService::definitions()) + 2, (int) $db->scalar('SELECT COUNT(*) FROM system_settings'));
        $this->assertSame('1', (string) $db->scalar("SELECT value FROM system_settings WHERE setting_key = 'bank_role_defaults_released'"));
        $this->assertSame('1', (string) $db->scalar("SELECT value FROM system_settings WHERE setting_key = 'gst_partner_scope_released'"));
        $this->assertSame(18, (int) $db->scalar('SELECT COUNT(*) FROM approval_workflows WHERE is_active = 0'), 'workflow types seeded inactive');
        $this->assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM approval_requests'), 'no fake transactions');
        foreach (['partners', 'partner_capital_transactions', 'profit_sharing_arrangements', 'profit_sharing_allocations', 'partner_documents', 'accounting_posting_queue', 'gst_registrations', 'gst_registration_partner_mappings', 'gst_registration_documents', 'gst_registration_links', 'bank_accounts', 'bank_transactions', 'bank_transfers', 'bank_statement_rows', 'bank_reconciliation_matches'] as $table) {
            $this->assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM {$table}"), "no example business data in {$table}");
        }
        $this->assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM users'), 'no hardcoded user accounts');
        // Phase 1 permission slugs are preserved
        foreach (['dashboard.view', 'users.view', 'users.create', 'users.edit', 'users.change_status', 'users.assign_roles', 'users.reset_password', 'users.view_login_history', 'activity_logs.view', 'settings.view', 'settings.edit'] as $slug) {
            $this->assertTrue((bool) $db->scalar('SELECT 1 FROM permissions WHERE slug = ?', [$slug]), $slug);
        }
    }

    public function testSeedersAreIdempotentAndPreserveAdminChanges(): void
    {
        $db = App::db();
        $db->update('system_settings', ['value' => '7'], ['setting_key' => 'login_max_attempts']);
        $grants = (int) $db->scalar('SELECT COUNT(*) FROM role_permissions');
        $perms = (int) $db->scalar('SELECT COUNT(*) FROM permissions');
        // An administrator removes a default grant; re-seeding must not bring it back.
        $managerId = $db->scalar("SELECT id FROM roles WHERE slug = 'manager'");
        $pid = $db->scalar("SELECT id FROM permissions WHERE slug = 'users.view_all'");
        $db->delete('role_permissions', ['role_id' => $managerId, 'permission_id' => $pid]);
        $db->update('modules', ['is_active' => 0], ['module_key' => 'inventory']);
        (new DatabaseSeeder($db))->run();
        (new DatabaseSeeder($db))->run();
        $this->assertSame(5, (int) $db->scalar('SELECT COUNT(*) FROM roles'));
        $this->assertSame($perms, (int) $db->scalar('SELECT COUNT(*) FROM permissions'));
        $this->assertSame($grants - 1, (int) $db->scalar('SELECT COUNT(*) FROM role_permissions'), 'removed grant stays removed');
        $this->assertSame(0, (int) $db->scalar("SELECT is_active FROM modules WHERE module_key = 'inventory'"), 'disabled module stays disabled');
        $this->assertSame(6, (int) $db->scalar('SELECT COUNT(*) FROM permission_templates'));
        $this->assertSame('7', (string) $db->scalar("SELECT value FROM system_settings WHERE setting_key = 'login_max_attempts'"));
    }

    public function testRollbackAndRemigrate(): void
    {
        $migrator = new Migrator(App::db(), App::basePath('database/migrations'));
        $count = count($migrator->files());
        $rolled = $migrator->rollback(1);
        $this->assertSame($count, count($rolled));
        $this->assertFalse((new Schema(App::db()))->hasTable('users'));
        $this->assertSame($count, count($migrator->migrate()));
        $this->assertSame([], $migrator->migrate());
    }
}
