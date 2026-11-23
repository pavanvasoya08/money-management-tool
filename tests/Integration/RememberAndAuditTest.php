<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Services\RememberMeService;
use App\Services\SettingsService;
use Tests\TestCase;

final class RememberAndAuditTest extends TestCase
{
    public function setUp(): void
    {
        $this->freshDatabase();
    }

    public function testRememberTokenRotatesAndDetectsTheft(): void
    {
        $u = $this->makeUser('asha@example.com');
        $svc = new RememberMeService();
        $cookie = $svc->issue((int) $u['id'], $this->request());
        $this->assertSame(0, (int) App::db()->scalar('SELECT COUNT(*) FROM remember_tokens WHERE validator_hash = ?', [explode(':', $cookie)[1]]), 'validator stored hashed');

        [$userId, $rotated] = $svc->consume($cookie, $this->request());
        $this->assertSame((int) $u['id'], $userId);
        $this->assertNotSame($cookie, $rotated);

        // Re-using the old cookie (stolen copy) revokes all tokens.
        $this->assertNull($svc->consume($cookie, $this->request()));
        $this->assertSame(0, (int) App::db()->scalar('SELECT COUNT(*) FROM remember_tokens'));
        $this->assertNull($svc->consume($rotated, $this->request()));
        $this->assertSame(1, (int) App::db()->scalar("SELECT COUNT(*) FROM activity_logs WHERE action = 'auth.remember_token_mismatch'"));
    }

    public function testExpiredAndMalformedRememberCookies(): void
    {
        $u = $this->makeUser('asha@example.com');
        $svc = new RememberMeService();
        $cookie = $svc->issue((int) $u['id'], $this->request());
        App::db()->execute('UPDATE remember_tokens SET expires_at = ?', [now('-1 second')]);
        $this->assertNull($svc->consume($cookie, $this->request()));
        $this->assertNull($svc->consume('garbage', $this->request()));
    }

    public function testActivityLoggerCapturesContextAndRedactsSecrets(): void
    {
        $u = $this->makeUser('asha@example.com');
        App::activity()->log('test.action', 'Did a thing', 'user', (int) $u['id'], ['password' => 'hunter2', 'token' => 'abc', 'note' => 'ok'], (int) $u['id']);
        $row = App::db()->first('SELECT * FROM activity_logs ORDER BY id DESC LIMIT 1');
        $this->assertSame('test.action', $row['action']);
        $this->assertSame('10.0.0.1', $row['ip_address']);
        $this->assertSame('TestAgent/1.0', $row['user_agent']);
        $this->assertNotContains('hunter2', (string) $row['properties']);
        $this->assertNotContains('"abc"', (string) $row['properties']);
        $this->assertContains('"note":"ok"', (string) $row['properties']);
    }

    public function testAuditModelHasNoWriteMethods(): void
    {
        $methods = get_class_methods(\App\Models\ActivityLog::class);
        foreach ($methods as $m) {
            $this->assertFalse((bool) preg_match('/update|delete|remove|edit|truncate/i', $m), "ActivityLog::{$m} must not modify records");
        }
    }

    public function testSettingsValidateAndPersist(): void
    {
        $root = $this->makeUser('root@example.com', ['super-admin']);
        $this->assertThrows(\App\Exceptions\ValidationException::class, fn () => App::settings()->update(['login_max_attempts' => '1', 'timezone' => 'Nowhere/Land'], (int) $root['id']));
        $changed = App::settings()->update(['login_max_attempts' => '7', 'app_name' => 'Acme ERP', 'unknown_key' => 'x'] + App::settings()->all(), (int) $root['id']);
        sort($changed);
        $this->assertSame(['app_name', 'login_max_attempts'], $changed);
        $this->assertSame(7, App::settings()->get('login_max_attempts'));
        $this->assertSame(0, (int) App::db()->scalar("SELECT COUNT(*) FROM system_settings WHERE setting_key = 'unknown_key'"));
        $this->assertSame(15, count(SettingsService::definitions()));
    }
}
