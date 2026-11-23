<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\ActivityLogger;
use App\Services\AuthService;
use App\Services\Authorization\DataAccess;
use App\Services\Authorization\FieldPolicy;
use App\Services\Authorization\Gate;
use App\Services\SessionService;
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

    /** Drop every shared service (used by the test suite between tests). */
    public static function resetServices(): void
    {
        self::$instances = [];
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

    /** Record-level access (data visibility). */
    public static function dataAccess(): DataAccess
    {
        return self::shared(DataAccess::class, static fn (): DataAccess => new DataAccess(self::db(), self::gate()));
    }

    /** Field-level access (hidden / masked / read-only / editable). */
    public static function fields(): FieldPolicy
    {
        return self::shared(FieldPolicy::class, static fn (): FieldPolicy => new FieldPolicy(self::db(), self::gate()));
    }

    public static function approvals(): \App\Services\Approval\ApprovalEngine
    {
        return self::shared(\App\Services\Approval\ApprovalEngine::class, static function (): \App\Services\Approval\ApprovalEngine {
            // Modules that react to approval decisions register here (once per process).
            \App\Services\Partners\CapitalService::registerApprovalListener();
            return new \App\Services\Approval\ApprovalEngine(self::db());
        });
    }

    public static function capitalLedger(): \App\Services\Partners\CapitalLedger
    {
        return self::shared(\App\Services\Partners\CapitalLedger::class, static fn (): \App\Services\Partners\CapitalLedger => new \App\Services\Partners\CapitalLedger(self::db()));
    }

    public static function postingQueue(): \App\Services\Accounting\PostingQueue
    {
        return self::shared(\App\Services\Accounting\PostingQueue::class, static fn (): \App\Services\Accounting\PostingQueue => new \App\Services\Accounting\PostingQueue(self::db()));
    }

    public static function sessions(): SessionService
    {
        return self::shared(SessionService::class, static fn (): SessionService => new SessionService(self::db()));
    }

    /** Clear all authorization caches after permission changes (applied immediately). */
    public static function flushAuthorization(): void
    {
        self::gate()->flush();
        if (isset(self::$instances[DataAccess::class])) {
            self::dataAccess()->flush();
        }
        if (isset(self::$instances[FieldPolicy::class])) {
            self::fields()->flush();
        }
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
