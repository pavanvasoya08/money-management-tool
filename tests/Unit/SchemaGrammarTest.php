<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Schema\Blueprint;
use App\Core\Schema\Grammar;
use Tests\TestCase;

/** Verifies the MySQL 8 DDL that production migrations generate. */
final class SchemaGrammarTest extends TestCase
{
    public function testMysqlCreateTable(): void
    {
        $bp = new Blueprint('users');
        $bp->id();
        $bp->string('email', 191);
        $bp->enum('status', ['active', 'inactive'])->default('active');
        $bp->boolean('must_change_password')->default(false);
        $bp->json('meta')->nullable();
        $bp->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
        $bp->unique('email');
        $bp->index('status');
        [$sql] = (new Grammar('mysql'))->compileCreate($bp);

        $this->assertContains('`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', $sql);
        $this->assertContains("`status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active'", $sql);
        $this->assertContains('`must_change_password` TINYINT(1) NOT NULL DEFAULT 0', $sql);
        $this->assertContains('`meta` JSON NULL', $sql);
        $this->assertContains('UNIQUE KEY `uq_users_email` (`email`)', $sql);
        $this->assertContains('KEY `ix_users_status` (`status`)', $sql);
        $this->assertContains('CONSTRAINT `fk_users_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE', $sql);
        $this->assertContains('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $sql);
    }

    public function testEveryMigrationCompilesToValidMysqlDdl(): void
    {
        $this->freshDatabase();
        $migrator = new \App\Core\Migrator(\App\Core\App::db(), \App\Core\App::basePath('database/migrations'));
        $all = $migrator->pretend('mysql', true);
        $this->assertSame(count($migrator->files()), count($all));
        $tables = [];
        foreach ($all as $name => $queries) {
            if (str_contains($name, '_add_')) {
                // ALTER migrations: every FK column added must be BIGINT UNSIGNED and get a real constraint
                $joined = implode(";
", $queries);
                $this->assertContains('ALTER TABLE', $joined, $name);
                preg_match_all('/ADD CONSTRAINT `[a-z_]+` FOREIGN KEY \(`([a-z_]+)`\)/', $joined, $fks);
                foreach ($fks[1] as $col) {
                    $this->assertContains("`{$col}` BIGINT UNSIGNED", $joined, "{$name}: {$col}");
                }
                continue;
            }
            foreach ($queries as $sql) {
            $this->assertSame(substr_count($sql, '('), substr_count($sql, ')'), "{$name} balanced parentheses");
            $this->assertContains('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', $sql, $name);
            preg_match('/^CREATE TABLE `([a-z_]+)`/', $sql, $m);
            $tables[] = $m[1];
            // Every FK column must be BIGINT UNSIGNED to match the referenced id column.
            preg_match_all('/FOREIGN KEY \(`([a-z_]+)`\)/', $sql, $fks);
            foreach ($fks[1] as $col) {
                $this->assertContains("`{$col}` BIGINT UNSIGNED", $sql, "{$name}: {$col} type");
            }
            }
        }
        foreach (['users', 'roles', 'permissions', 'user_roles', 'role_permissions', 'user_permission_overrides', 'login_attempts', 'password_reset_tokens', 'activity_logs', 'system_settings', 'remember_tokens'] as $t) {
            $this->assertTrue(in_array($t, $tables, true), "table {$t} defined");
        }
    }

    public function testRejectsUnsafeIdentifiers(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, fn () => (new Grammar('mysql'))->wrap('users; DROP TABLE x'));
    }
}
