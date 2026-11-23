<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;

final class AuthFlowTest extends HttpTestCase
{
    public function testGuestsAreRedirectedToLogin(): void
    {
        $b = $this->browser();
        foreach (['/dashboard', '/users', '/settings', '/activity', '/account'] as $path) {
            $r = $b->get($path);
            $this->assertSame(302, $r['status'], $path);
            $this->assertSame('/login', $r['location'], $path);
        }
        $this->assertSame(200, $b->get('/login')['status']);
    }

    public function testSecurityHeadersAndCookieFlags(): void
    {
        $r = $this->browser()->get('/login');
        foreach (['X-Frame-Options: DENY', 'X-Content-Type-Options: nosniff', "Content-Security-Policy: default-src 'self'", 'Cache-Control: no-store'] as $h) {
            $this->assertContains($h, $r['headers']);
        }
        $this->assertContains('HttpOnly', $r['headers']);
        $this->assertContains('SameSite=Lax', $r['headers']);
    }

    public function testCsrfIsRequired(): void
    {
        $this->makeUser('root@example.com', ['super-admin']);
        $b = $this->browser();
        $b->get('/login');
        $r = $b->post('/login', ['email' => 'root@example.com', 'password' => 'Correct-Horse9!', '_csrf' => str_repeat('a', 64)]);
        $this->assertSame(419, $r['status']);
        $this->assertSame(0, (int) App::db()->scalar('SELECT COUNT(*) FROM login_attempts'));
        $r = $b->post('/login', ['email' => 'root@example.com', 'password' => 'Correct-Horse9!'], csrf: false);
        $this->assertSame(419, $r['status']);
    }

    public function testLoginRegeneratesSessionIdAndShowsDashboard(): void
    {
        $this->makeUser('root@example.com', ['super-admin'], name: 'Root Admin');
        $b = $this->browser();
        $b->get('/login');
        $before = $b->cookie('erp_session');
        $r = $b->post('/login', ['email' => 'root@example.com', 'password' => 'Correct-Horse9!']);
        $this->assertSame('/dashboard', $r['location']);
        $this->assertNotSame($before, $b->cookie('erp_session'), 'session fixation protection');
        $dash = $b->get('/dashboard');
        $this->assertSame(200, $dash['status']);
        $this->assertContains('Root Admin', $dash['body']);
        $this->assertContains('Total users', $dash['body']);
        $this->assertNotContains('Net Profit', $dash['body']);
        $this->assertContains('Coming in Phase', $dash['body']);
    }

    public function testInvalidLoginShowsGenericError(): void
    {
        $b = $this->browser();
        $this->signIn($b, 'ghost@example.com', 'whatever');
        $page = $b->get('/login');
        $this->assertContains('These credentials do not match our records.', $page['body']);
    }

    public function testLogoutEndsSession(): void
    {
        $this->makeUser('root@example.com', ['super-admin']);
        $b = $this->browser();
        $this->signIn($b, 'root@example.com');
        $b->get('/dashboard');
        $r = $b->post('/logout');
        $this->assertSame('/login', $r['location']);
        $this->assertSame(302, $b->get('/dashboard')['status']);
    }

    public function testRememberMeRestoresLoginOnNewDevice(): void
    {
        $this->makeUser('root@example.com', ['super-admin']);
        $b = $this->browser();
        $r = $this->signIn($b, 'root@example.com', remember: true);
        $this->assertContains('erp_remember=', $r['headers']);
        $this->assertContains('httponly', strtolower($r['headers']));
        $cookie = $b->cookie('erp_remember');
        $this->assertTrue(is_string($cookie) && (bool) preg_match('/^[a-f0-9]{24}:[a-f0-9]{64}$/', urldecode($cookie)), 'selector:validator format');

        $device = $b->withOnlyCookie('erp_remember', (string) $cookie);
        $dash = $device->get('/dashboard');
        $this->assertSame(200, $dash['status']);
        $this->assertNotSame($cookie, $device->cookie('erp_remember'), 'token rotated');
    }

    public function testForcedPasswordChangeForTemporaryPasswords(): void
    {
        $user = $this->makeUser('new@example.com', ['viewer']);
        App::db()->update('users', ['must_change_password' => 1], ['id' => $user['id']]);
        $b = $this->browser();
        $this->signIn($b, 'new@example.com');
        $r = $b->get('/dashboard');
        $this->assertSame('/account/password', $r['location']);
        $b->get('/account/password');
        $r = $b->post('/account/password', ['current_password' => 'Correct-Horse9!', 'password' => 'Fresh-Start#2026', 'password_confirmation' => 'Fresh-Start#2026']);
        $this->assertSame('/dashboard', $r['location']);
        $this->assertSame(200, $b->get('/dashboard')['status']);
    }

    public function testPasswordResetOverHttp(): void
    {
        $this->makeUser('asha@example.com', ['viewer']);
        $b = $this->browser();
        $b->get('/forgot-password');
        $r = $b->post('/forgot-password', ['email' => 'asha@example.com']);
        $this->assertSame('/forgot-password', $r['location']);
        $this->assertContains('If an active account exists', $b->get('/forgot-password')['body']);
        preg_match('#(/reset-password/[a-f0-9]{64})#', (string) file_get_contents(App::basePath('storage/logs/mail.log')), $m);
        $page = $b->get($m[1]);
        $this->assertContains('Set a new password', $page['body']);
        $token = substr($m[1], strlen('/reset-password/'));
        $r = $b->post('/reset-password', ['token' => $token, 'password' => 'Reset-Done#2026', 'password_confirmation' => 'Reset-Done#2026']);
        $this->assertSame('/login', $r['location']);
        $this->assertContains('expired or invalid', $b->get($m[1])['body'], 'link is single-use');
        $this->signIn($b, 'asha@example.com', 'Reset-Done#2026');
        $this->assertSame(200, $b->get('/dashboard')['status']);
    }
}
