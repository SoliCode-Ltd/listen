<?php

namespace App\Http\Middleware;

use App\Support\InApp\InApp;
use App\Support\InApp\InAppWording;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inside the Listen app (App\Support\InApp\InApp) nothing is sold:
 *
 * - a purchase page (pricing, billing, a pack, managing the subscription)
 *   shows the neutral plan page instead, and a purchase request is refused;
 * - a marketing page, which sells, leads to the studio or to sign-in;
 * - the pages are never kept by a cache, so a page made for the app is never
 *   shown to a browser, or the other way round.
 *
 * Browsers are not touched at all.
 */
class InAppMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! InApp::active($request)) {
            return $next($request);
        }

        InAppWording::apply();

        if ($this->sells($request)) {
            if (! in_array($request->method(), ['GET', 'HEAD'], true) || $request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => __('app.not_sold_here')], 403);
            }

            return redirect()->to(InApp::planUrl());
        }

        if ($request->isMethod('GET') && $request->routeIs(...InApp::MARKETING_PAGES)) {
            return redirect()->to(InApp::homeUrl());
        }

        $response = $next($request);

        if (str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    private function sells(Request $request): bool
    {
        return InApp::sellsRoute((string) $request->route()?->getName());
    }
}
