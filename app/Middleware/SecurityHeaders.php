<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use Closure;

/** Adds defensive HTTP headers to every response. */
final class SecurityHeaders implements Middleware
{
    public const CDN = 'https://cdn.jsdelivr.net https://unpkg.com';

    public function handle(Request $request, Closure $next, ?string $parameter = null): Response
    {
        $response = $next($request);
        $cdn = self::CDN;
        $response->header('X-Frame-Options', 'DENY')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->header('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()')
            ->header('Cross-Origin-Opener-Policy', 'same-origin')
            ->header('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self' {$cdn}",
                "style-src 'self' {$cdn}",
                "img-src 'self' data:",
                "font-src 'self' {$cdn} data:",
                "connect-src 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "object-src 'none'",
            ]));
        if (str_starts_with($response->headers['Content-Type'] ?? '', 'text/html')) {
            // Pages may contain private data: never cache (also stops "back" after logout showing data).
            $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')->header('Pragma', 'no-cache');
        }
        if ($request->isSecure()) {
            $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        return $response;
    }
}
