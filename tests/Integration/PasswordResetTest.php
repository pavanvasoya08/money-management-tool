<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Services\PasswordResetService;
use Tests\TestCase;

final class PasswordResetTest extends TestCase
{
    public function setUp(): void
    {
        $this->freshDatabase();
        @unlink(App::basePath('storage/logs/mail.log'));
    }

    private function tokenFromMail(): string
    {
        preg_match('#/reset-password/([a-f0-9]{64})#', (string) file_get_contents(App::basePath('storage/logs/mail.log')), $m);
        return $m[1] ?? '';
    }

    public function testFullResetFlowSingleUse(): void
    {
        $this->makeUser('asha@example.com');
        $svc = new PasswordResetService();
        $svc->request('asha@example.com', $this->request());
        $token = $this->tokenFromMail();
        $this->assertSame(64, strlen($token));
        $this->assertSame(0, (int) App::db()->scalar('SELECT COUNT(*) FROM password_reset_tokens WHERE token_hash = ?', [$token]), 'raw token never stored');
        $this->assertSame(1, (int) App::db()->scalar('SELECT COUNT(*) FROM password_reset_tokens WHERE token_hash = ?', [hash('sha256', $token)]));

        $this->assertTrue($svc->findValid($token) !== null);
        $this->assertTrue($svc->reset($token, 'Brand-New#Pass1'));
        $this->assertFalse($svc->reset($token, 'Another-New#Pass2'), 'single use');
        $this->assertTrue(App::auth()->attempt('asha@example.com', 'Brand-New#Pass1', false, $this->request())->success);
    }

    public function testExpiredTokenRejected(): void
    {
        $this->makeUser('asha@example.com');
        $svc = new PasswordResetService();
        $svc->request('asha@example.com', $this->request());
        $token = $this->tokenFromMail();
        App::db()->execute('UPDATE password_reset_tokens SET expires_at = ?', [now('-1 minute')]);
        $this->assertNull($svc->findValid($token));
        $this->assertFalse($svc->reset($token, 'Brand-New#Pass1'));
    }

    public function testUnknownEmailSendsNothingAndNewLinkInvalidatesOld(): void
    {
        $svc = new PasswordResetService();
        $svc->request('nobody@example.com', $this->request());
        $this->assertFalse(is_file(App::basePath('storage/logs/mail.log')));

        $this->makeUser('asha@example.com');
        $svc->request('asha@example.com', $this->request());
        $first = $this->tokenFromMail();
        @unlink(App::basePath('storage/logs/mail.log'));
        $svc->request('asha@example.com', $this->request());
        $second = $this->tokenFromMail();
        $this->assertNull($svc->findValid($first));
        $this->assertTrue($svc->findValid($second) !== null);
    }

    public function testRateLimitAndMalformedTokens(): void
    {
        $this->makeUser('asha@example.com');
        $svc = new PasswordResetService();
        for ($i = 0; $i < 5; $i++) {
            $svc->request('asha@example.com', $this->request());
        }
        $this->assertSame(3, (int) App::db()->scalar('SELECT COUNT(*) FROM password_reset_tokens'));
        $this->assertNull($svc->findValid("' OR 1=1 --"));
    }
}
