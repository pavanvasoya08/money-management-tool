<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Hardened session wrapper:
 *  - strict mode (rejects uninitialised IDs → session fixation protection)
 *  - HttpOnly / Secure / SameSite cookies
 *  - ID regeneration on privilege change
 *  - flash data that lives for exactly one subsequent request
 */
final class Session
{
    /** @var array<string, mixed> */
    private array $memory = [];
    private bool $native = false;
    private bool $started = false;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config = [])
    {
    }

    public function start(bool $native = true): void
    {
        if ($this->started) {
            return;
        }
        $this->native = $native && PHP_SAPI !== 'cli';
        if ($this->native) {
            $savePath = (string) ($this->config['path'] ?? '');
            if ($savePath !== '' && is_dir($savePath) && is_writable($savePath)) {
                session_save_path($savePath);
            }
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_trans_sid', '0');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.gc_maxlifetime', (string) (((int) ($this->config['lifetime'] ?? 480)) * 60));
            session_name((string) ($this->config['name'] ?? 'erp_session'));
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => (bool) ($this->config['secure'] ?? true),
                'httponly' => true,
                'samesite' => (string) ($this->config['same_site'] ?? 'Lax'),
            ]);
            session_start();
        }
        $this->started = true;
        $this->ageFlash();
        if (!$this->has('_token')) {
            $this->regenerateToken();
        }
    }

    /** @return array<string, mixed> */
    private function &store(): array
    {
        if ($this->native) {
            return $_SESSION;
        }
        return $this->memory;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store()[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->store());
    }

    public function put(string $key, mixed $value): void
    {
        $store = &$this->store();
        $store[$key] = $value;
    }

    public function forget(string $key): void
    {
        $store = &$this->store();
        unset($store[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    public function flash(string $key, mixed $value): void
    {
        $store = &$this->store();
        $store[$key] = $value;
        $store['_flash']['new'][] = $key;
        $store['_flash']['new'] = array_values(array_unique($store['_flash']['new']));
        $store['_flash']['old'] = array_values(array_diff($store['_flash']['old'] ?? [], [$key]));
    }

    private function ageFlash(): void
    {
        $store = &$this->store();
        foreach ($store['_flash']['old'] ?? [] as $key) {
            unset($store[$key]);
        }
        $store['_flash'] = ['old' => $store['_flash']['new'] ?? [], 'new' => []];
    }

    public function token(): string
    {
        return (string) $this->get('_token');
    }

    public function regenerateToken(): void
    {
        $this->put('_token', bin2hex(random_bytes(32)));
    }

    /** New session ID, same data. Call after login / privilege change. */
    public function regenerate(): void
    {
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $this->regenerateToken();
    }

    /** Destroy all session data and issue a fresh ID (logout). */
    public function invalidate(): void
    {
        $store = &$this->store();
        $store = [];
        $this->regenerate();
        $this->put('_flash', ['old' => [], 'new' => []]);
    }

    public function id(): string
    {
        return $this->native ? (string) session_id() : 'memory';
    }
}
