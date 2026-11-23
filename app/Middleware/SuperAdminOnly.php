<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\AuthorizationException;
use Closure;

/** Restricts a route to Super Admins (used sparingly; prefer permissions). */
final class SuperAdminOnly implements Middleware
{
    public function handle(Request $request, Closure $next, ?string $parameter = null): Response
    {
        $user = App::auth()->user();
        if ($user === null || !App::gate()->isSuperAdmin($user)) {
            throw new AuthorizationException('super-admin');
        }
        return $next($request);
    }
}
