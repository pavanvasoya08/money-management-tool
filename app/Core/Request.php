<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @var array<string, string> */
    public array $routeParams = [];
    /** @var array<string, mixed> request-scoped attributes set by middleware */
    public array $attributes = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @param array<string, string> $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $server = [],
        public readonly array $cookies = [],
    ) {
    }

    public static function capture(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';
        $path = '/' . trim($path, '/');

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $_POST,
            $_SERVER,
            array_map('strval', array_filter($_COOKIE, 'is_scalar')),
        );
    }

    /** Input from POST body first, then query string. Always returns scalars/arrays as received. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    /** String input, trimmed; arrays are rejected (returns default). */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->post + $this->query;
    }

    /** @param list<string> $keys @return array<string, mixed> */
    public function only(array $keys): array
    {
        $all = $this->all();
        return array_intersect_key($all, array_flip($keys));
    }

    public function param(string $key): ?string
    {
        return $this->routeParams[$key] ?? null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function isSecure(): bool
    {
        if (!empty($this->server['HTTPS']) && strtolower((string) $this->server['HTTPS']) !== 'off') {
            return true;
        }
        return $this->fromTrustedProxy() && strtolower((string) $this->header('X-Forwarded-Proto')) === 'https';
    }

    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        if ($this->fromTrustedProxy()) {
            $forwarded = (string) $this->header('X-Forwarded-For');
            $first = trim(explode(',', $forwarded)[0] ?? '');
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    private function fromTrustedProxy(): bool
    {
        $trusted = (array) Config::get('app.trusted_proxies', []);
        return $trusted !== [] && in_array((string) ($this->server['REMOTE_ADDR'] ?? ''), $trusted, true);
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 500);
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function fullUrl(): string
    {
        $qs = $this->query === [] ? '' : '?' . http_build_query($this->query);
        return $this->path . $qs;
    }

    public function wantsJson(): bool
    {
        return str_contains((string) $this->header('Accept'), 'application/json')
            || strtolower((string) $this->header('X-Requested-With')) === 'xmlhttprequest';
    }
}
