<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\TokenMismatchException;
use Closure;

/** Rejects state-changing requests without a valid per-session CSRF token. */
final class VerifyCsrfToken implements Middleware
{
    public function handle(Request $request, Closure $next, ?string $parameter = null): Response
    {
        if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $sent = $request->post['_csrf'] ?? $request->header('X-CSRF-Token');
            $expected = App::session()->token();
            if (!is_string($sent) || $expected === '' || !hash_equals($expected, $sent)) {
                throw new TokenMismatchException();
            }
        }
        return $next($request);
    }
}
