<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use Closure;

/**
 * Requires a signed-in, active user. Users flagged "must change password"
 * can only reach the change-password page and logout.
 */
final class Authenticate implements Middleware
{
    private const ALLOWED_WHEN_PASSWORD_EXPIRED = ['account.password', 'account.password.update', 'logout'];

    public function handle(Request $request, Closure $next, ?string $parameter = null): Response
    {
        $user = App::auth()->user();
        if ($user === null) {
            if ($request->method === 'GET') {
                App::session()->put('url.intended', $request->fullUrl());
            }
            if (!App::session()->has('toast')) {
                App::session()->flash('toast', ['type' => 'info', 'message' => 'Please sign in to continue.']);
            }
            return Response::redirect(route('login'));
        }

        if ((int) $user['must_change_password'] === 1
            && !in_array($request->attributes['route'] ?? '', self::ALLOWED_WHEN_PASSWORD_EXPIRED, true)) {
            return Response::redirect(route('account.password'))
                ->with('warning', 'Please set a new password before continuing.');
        }
        return $next($request);
    }
}
