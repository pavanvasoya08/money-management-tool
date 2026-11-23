<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Config;

/*
 * Global helpers used by views and services. Every helper that outputs
 * user-controlled data must escape it; templates use e() for all output.
 */

if (!function_exists('e')) {
    /** HTML-escape a value for safe output (XSS protection). */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('now')) {
    /** Current UTC time formatted for storage. */
    function now(string $modify = ''): string
    {
        $dt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($modify !== '') {
            $dt = $dt->modify($modify);
        }
        return $dt->format('Y-m-d H:i:s');
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        return App::settings()->get($key, $default);
    }
}

if (!function_exists('route')) {
    /** @param array<string, string|int> $params */
    function route(string $name, array $params = []): string
    {
        return App::router()->url($name, $params);
    }
}

if (!function_exists('asset')) {
    /** Public asset URL with cache-busting version based on file mtime. */
    function asset(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $file = App::basePath('public' . $path);
        return $path . (is_file($file) ? '?v=' . filemtime($file) : '');
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return App::session()->token();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): mixed
    {
        $old = App::session()->get('old', []);
        return is_array($old) && array_key_exists($key, $old) ? $old[$key] : $default;
    }
}

if (!function_exists('errors')) {
    /** @return array<string, list<string>> */
    function errors(): array
    {
        $errors = App::session()->get('errors', []);
        return is_array($errors) ? $errors : [];
    }
}

if (!function_exists('error_for')) {
    function error_for(string $field): ?string
    {
        return errors()[$field][0] ?? null;
    }
}

if (!function_exists('invalid')) {
    /** Bootstrap "is-invalid" class when a field has an error. */
    function invalid(string $field): string
    {
        return error_for($field) !== null ? ' is-invalid' : '';
    }
}

if (!function_exists('auth_user')) {
    /** @return array<string, mixed>|null */
    function auth_user(): ?array
    {
        return App::auth()->user();
    }
}

if (!function_exists('can')) {
    function can(string $permission, mixed $resource = null): bool
    {
        $user = App::auth()->user();
        return $user !== null && App::gate()->allows($user, $permission, $resource);
    }
}

if (!function_exists('display_tz')) {
    function display_tz(): DateTimeZone
    {
        static $tz = null;
        $name = (string) setting('timezone', config('app.timezone', 'UTC'));
        if ($tz === null || $tz->getName() !== $name) {
            try {
                $tz = new DateTimeZone($name);
            } catch (Throwable) {
                $tz = new DateTimeZone('UTC');
            }
        }
        return $tz;
    }
}

if (!function_exists('format_datetime')) {
    /** Convert a stored UTC timestamp to the configured display timezone/format. */
    function format_datetime(?string $utc, bool $withTime = true): string
    {
        if ($utc === null || $utc === '') {
            return '—';
        }
        try {
            $dt = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(display_tz());
        } catch (Throwable) {
            return '—';
        }
        $format = (string) setting('date_format', 'd M Y');
        return $dt->format($withTime ? $format . ', H:i' : $format);
    }
}

if (!function_exists('time_ago')) {
    function time_ago(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return 'never';
        }
        $seconds = time() - (int) strtotime($utc . ' UTC');
        return match (true) {
            $seconds < 60 => 'just now',
            $seconds < 3600 => intdiv($seconds, 60) . ' min ago',
            $seconds < 86400 => intdiv($seconds, 3600) . ' h ago',
            $seconds < 86400 * 30 => intdiv($seconds, 86400) . ' d ago',
            default => format_datetime($utc, false),
        };
    }
}

if (!function_exists('initials')) {
    function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = array_map(static fn (string $p): string => mb_strtoupper(mb_substr($p, 0, 1)), array_slice($parts, 0, 2));
        return implode('', $letters) ?: '?';
    }
}

if (!function_exists('query_with')) {
    /** Current query string with overrides — used by pagination/sort links. */
    function query_with(array $overrides): string
    {
        $query = array_merge(App::request()?->query ?? [], $overrides);
        $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');
        return $query === [] ? '' : '?' . http_build_query($query);
    }
}
