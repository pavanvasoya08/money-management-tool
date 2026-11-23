<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\HttpException;
use Closure;
use RuntimeException;

/**
 * Small router with route groups, named routes, {param} placeholders and a
 * middleware pipeline. Middleware are referenced by alias, e.g. 'auth' or
 * 'can:users.view' (alias:parameter).
 */
final class Router
{
    /** @var list<array{method: string, path: string, regex: string, action: array{0: class-string, 1: string}, middleware: list<string>, name: ?string}> */
    private array $routes = [];
    /** @var array<string, int> */
    private array $names = [];
    /** @var list<array{prefix: string, middleware: list<string>}> */
    private array $groups = [];
    /** @var array<string, class-string<Middleware>> */
    private array $aliases = [];
    /** @var list<string> */
    private array $global = [];

    /** @param array<string, class-string<Middleware>> $aliases @param list<string> $global */
    public function __construct(array $aliases = [], array $global = [])
    {
        $this->aliases = $aliases;
        $this->global = $global;
    }

    /** @param array{0: class-string, 1: string} $action @param list<string> $middleware */
    public function get(string $path, array $action, ?string $name = null, array $middleware = []): void
    {
        $this->add('GET', $path, $action, $name, $middleware);
    }

    /** @param array{0: class-string, 1: string} $action @param list<string> $middleware */
    public function post(string $path, array $action, ?string $name = null, array $middleware = []): void
    {
        $this->add('POST', $path, $action, $name, $middleware);
    }

    /** @param array{prefix?: string, middleware?: list<string>} $attributes */
    public function group(array $attributes, Closure $routes): void
    {
        $this->groups[] = ['prefix' => trim($attributes['prefix'] ?? '', '/'), 'middleware' => $attributes['middleware'] ?? []];
        $routes($this);
        array_pop($this->groups);
    }

    private function add(string $method, string $path, array $action, ?string $name, array $middleware): void
    {
        $prefix = implode('/', array_filter(array_column($this->groups, 'prefix')));
        $full = '/' . trim($prefix . '/' . trim($path, '/'), '/');
        $groupMw = array_merge(...array_column($this->groups, 'middleware') ?: [[]]);
        $regex = '#^' . preg_replace('/\{([a-z_]+)\}/', '(?P<$1>[^/]+)', $full) . '$#';

        $this->routes[] = [
            'method' => $method, 'path' => $full, 'regex' => $regex, 'action' => $action,
            'middleware' => array_merge($groupMw, $middleware), 'name' => $name,
        ];
        if ($name !== null) {
            $this->names[$name] = array_key_last($this->routes);
        }
    }

    /** @param array<string, string|int> $params */
    public function url(string $name, array $params = []): string
    {
        if (!isset($this->names[$name])) {
            throw new RuntimeException("Route [{$name}] is not defined.");
        }
        $path = $this->routes[$this->names[$name]]['path'];
        $query = [];
        foreach ($params as $key => $value) {
            if (str_contains($path, '{' . $key . '}')) {
                $path = str_replace('{' . $key . '}', rawurlencode((string) $value), $path);
            } else {
                $query[$key] = $value;
            }
        }
        return $path . ($query ? '?' . http_build_query($query) : '');
    }

    public function has(string $name): bool
    {
        return isset($this->names[$name]);
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        $match = null;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            if ($route['method'] !== $request->method && !($route['method'] === 'GET' && $request->method === 'HEAD')) {
                $allowed[] = $route['method'];
                continue;
            }
            $match = $route;
            $request->attributes['route'] = $route['name'];
            $request->routeParams = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            break;
        }

        $core = function (Request $req) use ($match, $allowed): Response {
            if ($match === null) {
                throw $allowed !== []
                    ? new HttpException(405, 'Method not allowed.')
                    : new HttpException(404, 'Page not found.');
            }
            [$class, $method] = $match['action'];
            $controller = new $class();
            $response = $controller->{$method}($req, ...array_values($req->routeParams));
            return $response instanceof Response ? $response : new Response((string) $response);
        };

        $stack = array_merge($this->global, $match['middleware'] ?? []);
        return $this->pipeline($stack, $core)($request);
    }

    /** @param list<string> $stack */
    private function pipeline(array $stack, Closure $core): Closure
    {
        $next = $core;
        foreach (array_reverse($stack) as $entry) {
            [$alias, $param] = array_pad(explode(':', $entry, 2), 2, null);
            $class = $this->aliases[$alias] ?? throw new RuntimeException("Unknown middleware [{$alias}].");
            $next = static function (Request $request) use ($class, $param, $next): Response {
                /** @var Middleware $mw */
                $mw = new $class();
                return $mw->handle($request, $next, $param);
            };
        }
        return $next;
    }
}
