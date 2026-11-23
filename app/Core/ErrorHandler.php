<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\AuthorizationException;
use App\Exceptions\HttpException;
use App\Exceptions\TokenMismatchException;
use App\Exceptions\ValidationException;
use ErrorException;
use Throwable;

/**
 * Converts exceptions into safe HTTP responses. Stack traces are only shown
 * when APP_DEBUG=true AND APP_ENV=local; otherwise users see a generic page
 * with a reference ID that maps to the log entry.
 */
final class ErrorHandler
{
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    public static function render(Throwable $e, ?Request $request): Response
    {
        if ($request !== null && (str_starts_with($request->path, '/api/') || $request->path === '/api')) {
            return self::json($e, $request);
        }
        if ($e instanceof ValidationException) {
            $back = $e->redirectTo ?? ($request ? self::back($request) : '/');
            return Response::redirect($back)->withErrors($e->errors, $request?->all() ?? []);
        }

        if ($e instanceof \App\Exceptions\DomainRuleException) {
            return Response::redirect($request ? self::back($request) : '/')->with('error', $e->getMessage());
        }

        if ($e instanceof TokenMismatchException) {
            return self::page(419, 'Page expired', 'Your session expired or the form was already submitted. Please go back, refresh the page and try again.');
        }

        if ($e instanceof AuthorizationException) {
            if ($request !== null) {
                App::activity()->log('authorization.denied', 'Access denied to ' . $request->method . ' ' . $request->path, properties: ['permission' => $e->permission]);
            }
            $msg = $e->getMessage() !== 'This action is unauthorized.'
                ? $e->getMessage()
                : 'You do not have permission to perform this action. Contact your administrator if you believe this is a mistake.';
            $requestable = null;
            if ($e->permission !== null && preg_match('/^[a-z_]+\.[a-z_]+$/', $e->permission)
                && \App\Services\Admin\AccessRequestService::enabled() && App::auth()->check()) {
                $requestable = $e->permission;
            }
            return self::page(403, 'Access denied', $msg, requestable: $requestable);
        }

        if ($e instanceof HttpException) {
            $titles = [404 => 'Page not found', 405 => 'Method not allowed', 429 => 'Too many requests'];
            return self::page($e->status, $titles[$e->status] ?? 'Error', $e->getMessage());
        }

        $reference = strtoupper(bin2hex(random_bytes(4)));
        Logger::error($e::class . ': ' . $e->getMessage(), [
            'reference' => $reference,
            'file' => $e->getFile() . ':' . $e->getLine(),
            'path' => $request?->path,
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 15),
        ]);

        $debug = Config::get('app.debug') === true && Config::get('app.env') === 'local';
        $detail = $debug ? $e::class . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString() : null;
        return self::page(500, 'Something went wrong', 'An unexpected error occurred. Please try again. If the problem continues, give this reference to your administrator.', $reference, $detail);
    }

    /** API errors: JSON, no HTML, no internals. */
    private static function json(Throwable $e, Request $request): Response
    {
        [$status, $message] = match (true) {
            $e instanceof ValidationException => [422, 'The given data was invalid.'],
            $e instanceof TokenMismatchException => [419, 'CSRF token mismatch.'],
            $e instanceof AuthorizationException => [403, 'This action is unauthorized.'],
            $e instanceof HttpException => [$e->status, $e->getMessage()],
            $e instanceof \App\Exceptions\DomainRuleException => [409, $e->getMessage()],
            default => [500, 'Server error.'],
        };
        if ($e instanceof AuthorizationException) {
            App::activity()->log('authorization.denied', 'API access denied to ' . $request->method . ' ' . $request->path, properties: ['permission' => $e->permission]);
        }
        if ($status === 500) {
            Logger::error($e::class . ': ' . $e->getMessage(), ['path' => $request->path, 'file' => $e->getFile() . ':' . $e->getLine()]);
        }
        $body = ['error' => ['status' => $status, 'message' => $message]];
        if ($e instanceof ValidationException) {
            $body['error']['fields'] = $e->errors;
        }
        return Response::json($body, $status);
    }

    private static function page(int $status, string $title, string $message, ?string $reference = null, ?string $detail = null, ?string $requestable = null): Response
    {
        try {
            $html = App::view()->render('errors/error', compact('status', 'title', 'message', 'reference', 'detail', 'requestable'), 'layouts/minimal');
        } catch (Throwable) {
            $html = '<!doctype html><meta charset="utf-8"><title>' . $status . '</title><h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($message) . '</p>';
        }
        return new Response($html, $status);
    }

    private static function back(Request $request): string
    {
        $referer = (string) $request->header('Referer');
        $path = parse_url($referer, PHP_URL_PATH);
        $query = parse_url($referer, PHP_URL_QUERY);
        $host = parse_url($referer, PHP_URL_HOST);
        $currentHost = (string) ($request->server['HTTP_HOST'] ?? '');
        if (is_string($path) && (!is_string($host) || $host === explode(':', $currentHost)[0])) {
            return $path . (is_string($query) ? '?' . $query : '');
        }
        return $request->path;
    }
}
