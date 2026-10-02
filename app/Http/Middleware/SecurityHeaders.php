<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser security headers for web pages.
 *
 * - X-Frame-Options: other sites cannot frame the app (clickjacking).
 * - X-Content-Type-Options: browsers do not guess content types (MIME sniffing).
 * - Referrer-Policy: full URLs (which may carry tokens) are not sent to other sites.
 * - Strict-Transport-Security: browsers stick to HTTPS once they have seen it.
 *
 * Headers already set by a controller or the web server are left alone.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $defaults = [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];
        if ($request->isSecure()) {
            $defaults['Strict-Transport-Security'] = 'max-age=31536000';
        }

        foreach ($defaults as $name => $value) {
            if (! $headers->has($name)) {
                $headers->set($name, $value);
            }
        }

        return $response;
    }
}
