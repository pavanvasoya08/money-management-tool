<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Models\User;

/**
 * Forgot / reset password with single-use, expiring tokens.
 * Only the SHA-256 hash of a token is stored. Responses never reveal
 * whether an email address is registered.
 */
final class PasswordResetService
{
    public function __construct(private readonly Mailer $mailer = new Mailer())
    {
    }

    public function request(string $email, Request $request): void
    {
        $user = User::findForAuth($email);
        if ($user === null || $user['status'] !== 'active') {
            App::activity()->log('auth.password_reset_requested', 'Password reset requested for unknown or inactive account', properties: ['email' => mb_strtolower(trim($email))]);
            return;
        }
        $userId = (int) $user['id'];
        $db = App::db();

        $maxPerHour = (int) Config::get('auth.reset.max_requests_per_hour', 3);
        $recent = (int) $db->scalar('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = ? AND created_at > ?', [$userId, now('-1 hour')]);
        if ($recent >= $maxPerHour) {
            App::activity()->log('auth.password_reset_throttled', 'Password reset request ignored (rate limit)', 'user', $userId, userId: $userId);
            return;
        }

        $token = bin2hex(random_bytes(32));
        $minutes = (int) App::settings()->get('password_reset_expiry_minutes', 60);
        $db->transaction(function (Database $db) use ($userId, $token, $minutes, $request): void {
            // Only the newest link is valid.
            $db->execute('UPDATE password_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now(), $userId]);
            $db->insert('password_reset_tokens', [
                'user_id' => $userId,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now("+{$minutes} minutes"),
                'requested_ip' => $request->ip(),
                'created_at' => now(),
            ]);
        });

        $link = Config::get('app.url') . route('password.reset', ['token' => $token]);
        $appName = (string) App::settings()->get('app_name', 'Business ERP');
        $body = "Hello {$user['name']},\n\nWe received a request to reset the password for your {$appName} account.\n\n"
            . "Reset your password using this link (valid for {$minutes} minutes, single use):\n{$link}\n\n"
            . "If you did not request this, you can ignore this email — your password will not change.\n";
        try {
            $this->mailer->send((string) $user['email'], "Reset your {$appName} password", $body);
        } catch (\Throwable $e) {
            Logger::error('Password reset email failed: ' . $e->getMessage(), ['user_id' => $userId]);
        }
        App::activity()->log('auth.password_reset_requested', 'Password reset link sent', 'user', $userId, userId: $userId);
    }

    /** @return array<string, mixed>|null token row + user fields if the token is valid */
    public function findValid(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return App::db()->first(
            "SELECT t.id AS token_id, t.user_id, u.name, u.email FROM password_reset_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > ? AND u.status = 'active'",
            [hash('sha256', $token), now()]
        );
    }

    /** @return bool false if the token was invalid, expired or already used */
    public function reset(string $token, string $password): bool
    {
        $row = $this->findValid($token);
        if ($row === null) {
            return false;
        }
        $userId = (int) $row['user_id'];
        $ok = App::db()->transaction(function (Database $db) use ($row, $userId, $password): bool {
            // Atomic single-use: only one request can flip used_at.
            $claimed = $db->execute('UPDATE password_reset_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL', [now(), $row['token_id']]);
            if ($claimed !== 1) {
                return false;
            }
            User::update($userId, [
                'password_hash' => PasswordHasher::hash($password),
                'password_changed_at' => now(),
                'must_change_password' => 0,
                'failed_login_count' => 0,
                'locked_until' => null,
            ]);
            $db->execute('UPDATE password_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now(), $userId]);
            (new RememberMeService())->forgetAllForUser($userId);
            return true;
        });
        if ($ok) {
            App::activity()->log('auth.password_reset', 'Password reset via emailed link', 'user', $userId, userId: $userId);
        }
        return $ok;
    }
}
