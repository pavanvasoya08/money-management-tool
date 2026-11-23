<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use Closure;
use RuntimeException;

/** Route-level permission check: 'can:users.view'. Delegates to the central Gate. */
final class Authorize implements Middleware
{
    public function handle(Request $request, Closure $next, ?string $parameter = null): Response
    {
        if ($parameter === null || $parameter === '') {
            throw new RuntimeException('The "can" middleware requires a permission, e.g. can:users.view');
        }
        App::gate()->authorize(App::auth()->user(), $parameter);
        return $next($request);
    }
}
