<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One address for the whole site: a page opened at www. (www.listenai.studio) is sent, for good,
 * to the same page at the main address (APP_URL). Every link and request a page makes is written
 * with the main address, so a page left open at www. sent its requests to another site, which
 * the browser refuses: on an agent's page "Find them on my website" answered "Failed to fetch"
 * and "Read the website again" did nothing.
 *
 * Only pages are moved (GET and HEAD); anything else is answered where it was sent, as before.
 */
final class CanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $main = (string) config('app.url');
        $host = strtolower((string) parse_url($main, PHP_URL_HOST));
        if ($host !== '' && ! str_starts_with($host, 'www.') && strtolower($request->getHost()) === 'www.'.$host
            && in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return redirect()->away(rtrim($main, '/').$request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
