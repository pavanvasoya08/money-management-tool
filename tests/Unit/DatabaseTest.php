<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\App;
use App\Core\Database;
use RuntimeException;
use Tests\TestCase;

final class DatabaseTest extends TestCase
{
    public function setUp(): void
    {
        $this->freshDatabase();
    }

    public function testConnectionAndPreparedStatements(): void
    {
        $db = App::db();
        $this->assertSame(1, (int) $db->scalar('SELECT 1'));
        // An injection attempt is treated as a literal value, not SQL.
        $this->assertNull($db->first('SELECT id FROM users WHERE email = ?', ["' OR '1'='1"]));
    }

    public function testIdentifierInjectionIsRejected(): void
    {
        $this->assertThrows(RuntimeException::class, fn () => App::db()->insert('users; DROP TABLE users', ['a' => 1]));
        $this->assertThrows(RuntimeException::class, fn () => App::db()->update('users', ['name' => 'x'], []));
    }

    public function testTransactionRollsBackOnError(): void
    {
        $db = App::db();
        $before = (int) $db->scalar('SELECT COUNT(*) FROM roles');
        $this->assertThrows(RuntimeException::class, function () use ($db): void {
            $db->transaction(function (Database $db): void {
                $db->insert('roles', ['name' => 'Temp', 'slug' => 'temp', 'created_at' => now(), 'updated_at' => now()]);
                throw new RuntimeException('boom');
            });
        });
        $this->assertSame($before, (int) $db->scalar('SELECT COUNT(*) FROM roles'));
    }

    public function testLikeEscaping(): void
    {
        $this->makeUser('percent@example.com', name: 'Plain Name');
        $this->makeUser('other@example.com', name: '100% Real');
        $rows = App::db()->select("SELECT name FROM users WHERE name LIKE ? ESCAPE '!'", [Database::likeContains('%')]);
        $this->assertSame(1, count($rows));
        $this->assertSame('100% Real', $rows[0]['name']);
    }

    public function testForeignKeysAreEnforced(): void
    {
        $this->assertThrows(\PDOException::class, fn () => App::db()->insert('user_roles', ['user_id' => 999, 'role_id' => 999, 'assigned_at' => now()]));
    }
}
