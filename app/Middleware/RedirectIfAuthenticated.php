<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use Closure;

final class RedirectIfAuthenticated implements Middleware
{
    public function handle(Request $request, Closure $next, ?string $parameter = null): Response
    {
        if (App::auth()->check()) {
            return Response::redirect(route('dashboard'));
        }
        return $next($request);
    }
}
