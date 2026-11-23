<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\RememberMeService;
use Closure;

/**
 * Starts the hardened session, validates an existing login (idle timeout,
 * deactivation, password change…) and restores a login from a valid
 * remember-me cookie.
 */
final class StartSession implements Middleware
{
    public function handle(Request $request, Closure $next, ?string $parameter = null): Response
    {
        $session = App::session();
        $session->start();
        $auth = App::auth();
        $remember = new RememberMeService();
        $cookieName = RememberMeService::cookieName();

        $endedReason = $auth->validateSession($request);
        if ($endedReason !== null) {
            $session->flash('toast', ['type' => 'warning', 'message' => $endedReason]);
        }

        $rotated = null;
        $clearCookie = false;
        $cookie = $request->cookie($cookieName);
        if (!$auth->check() && $cookie !== null && $endedReason === null) {
            $result = $remember->consume($cookie, $request);
            $user = $result !== null ? \App\Models\User::find($result[0]) : null;
            if ($result !== null && $user !== null && \App\Services\Authorization\AccountState::usable($user)
                && \App\Services\Authorization\AccountState::restrictionFor($user, $request->ip()) === null) {
                $auth->login($result[0], $request, true);
                $rotated = $result[1];
                App::activity()->log('auth.login', 'Signed in automatically (remember me)', 'user', $result[0], userId: $result[0]);
            } else {
                $clearCookie = true;
            }
        } elseif ($endedReason !== null && $cookie !== null) {
            $clearCookie = true;
        }

        $response = $next($request);

        if ($rotated !== null) {
            $remember->attachCookie($response, $rotated, $request);
        } elseif ($clearCookie) {
            $remember->clearCookie($response);
        }
        return $response;
    }
}
