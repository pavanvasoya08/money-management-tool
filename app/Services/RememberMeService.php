<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * "Remember me" using split tokens (selector + validator):
 *  - cookie = selector:validator; only SHA-256(validator) is stored
 *  - the validator is rotated on every use
 *  - a valid selector with a wrong validator indicates cookie theft → all of
 *    the user's remember tokens are revoked
 */
final class RememberMeService
{
    public static function cookieName(): string
    {
        return (string) Config::get('auth.remember_cookie', 'erp_remember');
    }

    public function issue(int $userId, Request $request): string
    {
        $selector = bin2hex(random_bytes(12));          // 24 chars
        $validator = bin2hex(random_bytes(32));
        $days = (int) App::settings()->get('remember_me_days', 30);
        App::db()->insert('remember_tokens', [
            'user_id' => $userId,
            'selector' => $selector,
            'validator_hash' => hash('sha256', $validator),
            'expires_at' => now("+{$days} days"),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
        return $selector . ':' . $validator;
    }

    /**
     * Validate a cookie. Returns [userId, rotatedCookieValue] or null.
     * @return array{0: int, 1: string}|null
     */
    public function consume(string $cookie, Request $request): ?array
    {
        if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $cookie, $m)) {
            return null;
        }
        [, $selector, $validator] = $m;
        $db = App::db();
        $row = $db->first('SELECT * FROM remember_tokens WHERE selector = ?', [$selector]);
        if ($row === null) {
            return null;
        }
        if ($row['expires_at'] <= now()) {
            $db->delete('remember_tokens', ['id' => $row['id']]);
            return null;
        }
        if (!hash_equals((string) $row['validator_hash'], hash('sha256', $validator))) {
            // Selector valid but secret wrong: possible stolen cookie. Revoke everything.
            $this->forgetAllForUser((int) $row['user_id']);
            App::activity()->log('auth.remember_token_mismatch', 'Remember-me token mismatch — all remembered devices signed out', 'user', (int) $row['user_id'], userId: (int) $row['user_id']);
            return null;
        }
        $newValidator = bin2hex(random_bytes(32));
        $db->update('remember_tokens', [
            'validator_hash' => hash('sha256', $newValidator),
            'last_used_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ], ['id' => $row['id']]);
        return [(int) $row['user_id'], $selector . ':' . $newValidator];
    }

    public function forget(?string $cookie): void
    {
        if ($cookie !== null && preg_match('/^([a-f0-9]{24}):/', $cookie, $m)) {
            App::db()->delete('remember_tokens', ['selector' => $m[1]]);
        }
    }

    public function forgetAllForUser(int $userId): void
    {
        App::db()->delete('remember_tokens', ['user_id' => $userId]);
    }

    public function attachCookie(Response $response, string $value, Request $request): void
    {
        $days = (int) App::settings()->get('remember_me_days', 30);
        $response->cookie(self::cookieName(), $value, $this->options(time() + $days * 86400));
    }

    public function clearCookie(Response $response): void
    {
        $response->cookie(self::cookieName(), '', $this->options(time() - 3600));
    }

    /** @return array<string, mixed> */
    private function options(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => '/',
            'secure' => (bool) Config::get('session.secure', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
