<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Services\Authorization\AccountState;

/**
 * Authentication: credential checks, brute-force protection, session login
 * and session validation. Messages never reveal whether an email exists.
 */
final class AuthService
{
    public const GENERIC_FAILURE = 'These credentials do not match our records.';

    /** @var array<string, mixed>|null */
    private ?array $user = null;
    private bool $resolved = false;

    public function attempt(string $email, string $password, bool $remember, Request $request): AuthResult
    {
        $email = mb_strtolower(trim($email));
        $ip = $request->ip();
        $ua = $request->userAgent();

        $ipLimit = (int) Config::get('auth.lockout.ip_max_attempts', 20);
        $ipWindow = (int) Config::get('auth.lockout.ip_window_minutes', 15);
        if (LoginAttempt::recentFailuresFromIp($ip, $ipWindow) >= $ipLimit) {
            LoginAttempt::record(null, $email, $ip, $ua, false, 'throttled');
            App::activity()->log('auth.login_throttled', "Login blocked: too many failed attempts from {$ip}", properties: ['email' => $email]);
            return AuthResult::fail("Too many failed sign-in attempts from your network. Please wait {$ipWindow} minutes and try again.");
        }

        $user = User::findForAuth($email);
        if ($user !== null && !empty($user['deleted_at'])) {
            $user = null; // deleted accounts behave exactly like unknown accounts
        }
        if ($user === null) {
            PasswordHasher::dummyVerify($password);
            LoginAttempt::record(null, $email, $ip, $ua, false, 'invalid_credentials');
            App::activity()->log('auth.login_failed', 'Failed login for unknown account', properties: ['email' => $email]);
            return AuthResult::fail(self::GENERIC_FAILURE);
        }

        $userId = (int) $user['id'];
        if ($user['locked_until'] !== null && $user['locked_until'] > now()) {
            PasswordHasher::dummyVerify($password);
            LoginAttempt::record($userId, $email, $ip, $ua, false, 'locked');
            return AuthResult::fail('This account is temporarily locked after repeated failed sign-ins. Try again in ' . $this->minutesUntil((string) $user['locked_until']) . ' minute(s) or reset your password.');
        }

        if (!PasswordHasher::verify($password, (string) $user['password_hash'])) {
            $failures = (int) $user['failed_login_count'] + 1;
            $max = (int) App::settings()->get('login_max_attempts', 5);
            $update = ['failed_login_count' => $failures];
            $message = self::GENERIC_FAILURE;
            if ($failures >= $max) {
                $minutes = (int) App::settings()->get('lockout_minutes', 15);
                $update = ['failed_login_count' => 0, 'locked_until' => now("+{$minutes} minutes")];
                $message = "Too many failed attempts. This account is locked for {$minutes} minute(s).";
                App::activity()->log('auth.account_locked', "Account locked after {$failures} failed sign-ins", 'user', $userId, userId: $userId);
            }
            User::update($userId, $update);
            LoginAttempt::record($userId, $email, $ip, $ua, false, 'invalid_credentials');
            App::activity()->log('auth.login_failed', 'Failed login (wrong password)', 'user', $userId, userId: $userId);
            return AuthResult::fail($message);
        }

        // Correct password from here on — account state is only revealed to the real owner.
        $state = AccountState::of($user);
        if ($state !== AccountState::ACTIVE) {
            LoginAttempt::record($userId, $email, $ip, $ua, false, $state);
            App::activity()->log('auth.login_failed', "Login refused: account {$state}", 'user', $userId, userId: $userId);
            return AuthResult::fail(AccountState::message($user));
        }
        $restriction = AccountState::restrictionFor($user, $ip);
        if ($restriction !== null) {
            LoginAttempt::record($userId, $email, $ip, $ua, false, 'access_restricted');
            App::activity()->log('auth.login_failed', 'Login refused: access restriction (' . $restriction . ')', 'user', $userId, userId: $userId);
            return AuthResult::fail($restriction);
        }

        $update = ['failed_login_count' => 0, 'locked_until' => null, 'last_login_at' => now(), 'last_login_ip' => $ip];
        if (PasswordHasher::needsRehash((string) $user['password_hash'])) {
            $update['password_hash'] = PasswordHasher::hash($password);
        }
        User::update($userId, $update);
        LoginAttempt::record($userId, $email, $ip, $ua, true);

        $this->login($userId, $request);
        App::activity()->log('auth.login', 'Signed in' . ($remember ? ' (remember me)' : ''), 'user', $userId, userId: $userId);

        $cookie = $remember ? (new RememberMeService())->issue($userId, $request) : null;
        return AuthResult::ok($cookie);
    }

    /** Establish an authenticated session (new session ID → fixation protection). */
    public function login(int $userId, Request $request, bool $viaRemember = false): void
    {
        $session = App::session();
        $session->regenerate();
        $user = User::find($userId);
        $session->put('auth', [
            'user_id' => $userId,
            'session' => App::sessions()->start($userId, $request, $viaRemember),
            'login_at' => time(),
            'last_activity' => time(),
            'password_changed_at' => $user['password_changed_at'] ?? null,
            'fingerprint' => hash('sha256', $request->userAgent()),
        ]);
        $this->user = null;
        $this->resolved = false;
        App::gate()->flush($userId);
    }

    public function logout(Request $request, string $reason = 'Signed out'): void
    {
        $id = $this->id();
        if ($id !== null) {
            App::activity()->log('auth.logout', $reason, 'user', $id, userId: $id);
        }
        (new RememberMeService())->forget($request->cookie(RememberMeService::cookieName()));
        $row = App::sessions()->find(App::session()->get('auth')['session'] ?? null);
        if ($row !== null) {
            App::sessions()->revoke((int) $row['id'], $id, 'Signed out');
        }
        App::session()->invalidate();
        $this->user = null;
        $this->resolved = true;
    }

    /**
     * Validate the current authenticated session. Logs the user out when the
     * session is idle/expired, the browser changed, the account was deactivated
     * or the password was changed elsewhere.
     *
     * @return string|null reason the session was ended, or null if still valid
     */
    public function validateSession(Request $request): ?string
    {
        $auth = App::session()->get('auth');
        if (!is_array($auth) || !isset($auth['user_id'])) {
            return null;
        }
        $idle = (int) App::settings()->get('session_idle_minutes', 30) * 60;
        $absolute = (int) Config::get('session.lifetime', 480) * 60;
        $now = time();

        $reason = match (true) {
            $now - (int) $auth['last_activity'] > $idle => 'Your session expired due to inactivity. Please sign in again.',
            $now - (int) $auth['login_at'] > $absolute => 'Your session has expired. Please sign in again.',
            !hash_equals((string) $auth['fingerprint'], hash('sha256', $request->userAgent())) => 'Your session was ended for security reasons. Please sign in again.',
            default => null,
        };

        $row = null;
        if ($reason === null) {
            $row = App::sessions()->find($auth['session'] ?? null);
            if ($row === null) {
                $reason = 'Your session has ended. Please sign in again.';
            } elseif ($row['revoked_at'] !== null) {
                $reason = $row['revoked_by'] !== null && (int) $row['revoked_by'] !== (int) $auth['user_id']
                    ? 'You were signed out by an administrator.'
                    : 'Your session has ended. Please sign in again.';
            }
        }

        if ($reason === null) {
            $user = User::find((int) $auth['user_id']);
            if ($user === null || !AccountState::usable($user)) {
                $reason = $user === null ? 'Your account is no longer active.' : AccountState::message($user);
            } elseif (($restriction = AccountState::restrictionFor($user, $request->ip())) !== null) {
                $reason = $restriction;
            } elseif (($user['password_changed_at'] ?? null) !== ($auth['password_changed_at'] ?? null)) {
                $reason = 'Your password was changed. Please sign in again.';
            } else {
                $this->user = $user;
                $this->resolved = true;
                App::sessions()->touch($row, $request);
            }
        }

        if ($reason !== null) {
            $this->logout($request, 'Session ended: ' . $reason);
            return $reason;
        }

        $auth['last_activity'] = $now;
        App::session()->put('auth', $auth);
        return null;
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;
        $auth = App::session()->get('auth');
        if (!is_array($auth) || !isset($auth['user_id'])) {
            return $this->user = null;
        }
        $user = User::find((int) $auth['user_id']);
        return $this->user = ($user !== null && AccountState::usable($user)) ? $user : null;
    }

    /** ID of the current row in user_sessions (null when not signed in). */
    public function sessionRowId(): ?int
    {
        $row = App::sessions()->find(App::session()->get('auth')['session'] ?? null);
        return $row === null ? null : (int) $row['id'];
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function id(): ?int
    {
        $user = $this->user();
        return $user === null ? null : (int) $user['id'];
    }

    /** Refresh the cached user and the session's password marker (after own password change). */
    public function refresh(): void
    {
        $this->resolved = false;
        $user = $this->user();
        $auth = App::session()->get('auth');
        if ($user !== null && is_array($auth)) {
            $auth['password_changed_at'] = $user['password_changed_at'];
            App::session()->put('auth', $auth);
        }
        App::gate()->flush();
    }

    private function minutesUntil(string $utc): int
    {
        return max(1, (int) ceil((strtotime($utc . ' UTC') - time()) / 60));
    }
}
