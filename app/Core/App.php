<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\ActivityLogger;
use App\Services\AuthService;
use App\Services\Authorization\Gate;
use App\Services\SettingsService;
use RuntimeException;

/**
 * Application kernel and lightweight service registry. Services are created
 * lazily once per request; tests can swap them with App::set().
 */
final class App
{
    private static string $basePath = '';
    /** @var array<string, object> */
    private static array $instances = [];
    private static ?Request $request = null;

    public static function boot(string $basePath): void
    {
        self::$basePath = rtrim($basePath, '/');
        self::$instances = [];
        Env::load(self::$basePath . '/.env');
        Config::loadDirectory(self::$basePath . '/config');
        // All timestamps are stored in UTC; they are converted for display only.
        date_default_timezone_set('UTC');
        mb_internal_encoding('UTF-8');
        ErrorHandler::register();
    }

    public static function basePath(string $path = ''): string
    {
        if (self::$basePath === '') {
            self::$basePath = dirname(__DIR__, 2);
        }
        return self::$basePath . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }

    /** Handle one HTTP request end-to-end. */
    public static function handle(Request $request): Response
    {
        self::$request = $request;
        try {
            return self::router()->dispatch($request);
        } catch (\Throwable $e) {
            return ErrorHandler::render($e, $request);
        }
    }

    public static function request(): ?Request
    {
        return self::$request;
    }

    public static function setRequest(?Request $request): void
    {
        self::$request = $request;
    }

    public static function set(string $id, object $instance): void
    {
        self::$instances[$id] = $instance;
    }

    public static function forget(string $id): void
    {
        unset(self::$instances[$id]);
    }

    /** @template T of object @param class-string<T> $class @return T */
    private static function shared(string $class, callable $factory): object
    {
        $instance = self::$instances[$class] ??= $factory();
        if (!$instance instanceof $class) {
            throw new RuntimeException("Service [{$class}] has an unexpected type.");
        }
        return $instance;
    }

    public static function db(): Database
    {
        return self::shared(Database::class, static function (): Database {
            $name = (string) Config::get('database.default', 'mysql');
            return new Database((array) Config::get("database.connections.{$name}"));
        });
    }

    public static function session(): Session
    {
        return self::shared(Session::class, static fn (): Session => new Session((array) Config::get('session', [])));
    }

    public static function view(): View
    {
        return self::shared(View::class, static fn (): View => new View(self::basePath('resources/views')));
    }

    public static function router(): Router
    {
        return self::shared(Router::class, static function (): Router {
            $router = new Router((array) Config::get('middleware.aliases', []), (array) Config::get('middleware.global', []));
            (require self::basePath('routes/web.php'))($router);
            return $router;
        });
    }

    public static function auth(): AuthService
    {
        return self::shared(AuthService::class, static fn (): AuthService => new AuthService());
    }

    public static function gate(): Gate
    {
        return self::shared(Gate::class, static fn (): Gate => new Gate(self::db()));
    }

    public static function settings(): SettingsService
    {
        return self::shared(SettingsService::class, static fn (): SettingsService => new SettingsService(self::db()));
    }

    public static function activity(): ActivityLogger
    {
        return self::shared(ActivityLogger::class, static fn (): ActivityLogger => new ActivityLogger(self::db()));
    }
}
