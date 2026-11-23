<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Models\User;
use App\Services\AuthService;
use Tests\TestCase;

final class AuthServiceTest extends TestCase
{
    public function setUp(): void
    {
        $this->freshDatabase();
    }

    public function testSuccessfulLoginRegeneratesSessionAndLogs(): void
    {
        $user = $this->makeUser('asha@example.com', ['viewer']);
        $tokenBefore = App::session()->token();
        $result = App::auth()->attempt('ASHA@example.com ', 'Correct-Horse9!', false, $this->request());
        $this->assertTrue($result->success);
        $this->assertSame((int) $user['id'], App::auth()->id());
        $this->assertNotSame($tokenBefore, App::session()->token(), 'CSRF token rotated with the session');
        $this->assertSame(1, (int) App::db()->scalar('SELECT COUNT(*) FROM login_attempts WHERE successful = 1'));
        $this->assertSame('auth.login', App::db()->scalar('SELECT action FROM activity_logs ORDER BY id DESC LIMIT 1'));
        $this->assertSame('10.0.0.1', User::find((int) $user['id'])['last_login_ip']);
    }

    public function testInvalidLoginIsGenericAndRecorded(): void
    {
        $this->makeUser('asha@example.com');
        $wrong = App::auth()->attempt('asha@example.com', 'nope', false, $this->request());
        $unknown = App::auth()->attempt('ghost@example.com', 'nope', false, $this->request());
        $this->assertFalse($wrong->success);
        $this->assertSame(AuthService::GENERIC_FAILURE, $wrong->message);
        $this->assertSame($wrong->message, $unknown->message, 'no user enumeration');
        $this->assertSame(2, (int) App::db()->scalar('SELECT COUNT(*) FROM login_attempts WHERE successful = 0'));
        $this->assertSame(2, (int) App::db()->scalar("SELECT COUNT(*) FROM activity_logs WHERE action = 'auth.login_failed'"));
        $this->assertNull(App::auth()->user());
    }

    public function testTemporaryLockoutAfterRepeatedFailures(): void
    {
        $user = $this->makeUser('asha@example.com');
        for ($i = 0; $i < 4; $i++) {
            App::auth()->attempt('asha@example.com', 'bad', false, $this->request());
        }
        $fifth = App::auth()->attempt('asha@example.com', 'bad', false, $this->request());
        $this->assertContains('locked', $fifth->message);
        // Even the correct password is refused while locked.
        $locked = App::auth()->attempt('asha@example.com', 'Correct-Horse9!', false, $this->request());
        $this->assertFalse($locked->success);
        $this->assertContains('temporarily locked', $locked->message);
        // After the lock expires, login works and counters reset.
        App::db()->update('users', ['locked_until' => now('-1 minute')], ['id' => $user['id']]);
        $ok = App::auth()->attempt('asha@example.com', 'Correct-Horse9!', false, $this->request());
        $this->assertTrue($ok->success);
        $this->assertSame(0, (int) User::find((int) $user['id'])['failed_login_count']);
        $this->assertSame(1, (int) App::db()->scalar("SELECT COUNT(*) FROM activity_logs WHERE action = 'auth.account_locked'"));
    }

    public function testIpThrottle(): void
    {
        for ($i = 0; $i < 20; $i++) {
            App::auth()->attempt("u{$i}@example.com", 'bad', false, $this->request(ip: '10.9.9.9'));
        }
        $this->makeUser('real@example.com');
        $blocked = App::auth()->attempt('real@example.com', 'Correct-Horse9!', false, $this->request(ip: '10.9.9.9'));
        $this->assertFalse($blocked->success);
        $this->assertContains('Too many failed sign-in attempts', $blocked->message);
        $other = App::auth()->attempt('real@example.com', 'Correct-Horse9!', false, $this->request(ip: '10.1.1.1'));
        $this->assertTrue($other->success, 'other IPs unaffected');
    }

    public function testInactiveUserCannotSignIn(): void
    {
        $this->makeUser('off@example.com', status: 'inactive');
        $result = App::auth()->attempt('off@example.com', 'Correct-Horse9!', false, $this->request());
        $this->assertFalse($result->success);
        $this->assertContains('inactive', $result->message);
    }

    public function testSessionValidationEndsIdleAndChangedSessions(): void
    {
        $user = $this->makeUser('asha@example.com');
        App::auth()->attempt('asha@example.com', 'Correct-Horse9!', false, $this->request());
        $this->assertNull(App::auth()->validateSession($this->request()));

        // Different browser fingerprint → session hijack protection.
        $this->assertContains('security', (string) App::auth()->validateSession($this->request(ua: 'Other/2.0')));
        $this->assertNull(App::auth()->user());

        // Idle timeout.
        App::auth()->attempt('asha@example.com', 'Correct-Horse9!', false, $this->request());
        $auth = App::session()->get('auth');
        $auth['last_activity'] = time() - 3600;
        App::session()->put('auth', $auth);
        $this->assertContains('inactivity', (string) App::auth()->validateSession($this->request()));

        // Deactivated while signed in.
        App::auth()->attempt('asha@example.com', 'Correct-Horse9!', false, $this->request());
        App::db()->update('users', ['status' => 'inactive'], ['id' => $user['id']]);
        $this->assertContains('inactive', (string) App::auth()->validateSession($this->request()));
    }

    public function testLogoutInvalidatesSession(): void
    {
        $this->makeUser('asha@example.com');
        App::auth()->attempt('asha@example.com', 'Correct-Horse9!', false, $this->request());
        App::auth()->logout($this->request());
        $this->assertNull(App::session()->get('auth'));
        $this->assertFalse(App::auth()->check());
        $this->assertSame('auth.logout', App::db()->scalar('SELECT action FROM activity_logs ORDER BY id DESC LIMIT 1'));
    }
}
