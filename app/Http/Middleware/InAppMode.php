<?php

namespace App\Http\Middleware;

use App\Http\Controllers\AppShellController;
use App\Support\InApp\InApp;
use App\Support\InApp\InAppWording;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inside the Listen app (App\Support\InApp\InApp) nothing is ever paid:
 *
 * - With plans on the web (the default), a member sees prices and plans as on
 *   the website; a page that takes a payment or changes a plan is not shown
 *   in the app but opened in the phone's browser, already signed in, with
 *   what the member chose (AppShellController::openOnWeb).
 * - With plans hidden, a purchase page shows the account page instead, the
 *   lines that point to buying say what is true and stop, and marketing
 *   pages lead to the studio or to sign-in.
 * - Either way a purchase request from the app is refused, and a page made
 *   for the app is never kept by a shared cache for a browser.
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

        // The app's language: the page's own when its address names one (and that is remembered),
        // else the member's last choice, else English, whatever the phone is set to.
        $pageLanguage = InApp::pageLanguage($request);
        if ($pageLanguage === null && $request->hasSession()) {
            $request->session()->put('locale', InApp::language($request));
        }

        $route = (string) $request->route()?->getName();
        $reads = in_array($request->method(), ['GET', 'HEAD'], true) && ! $request->expectsJson();

        if (InApp::hidesPlans($request)) {
            InAppWording::apply();

            if (InApp::sellsRoute($route)) {
                return $reads
                    ? redirect()->to(InApp::planUrl())
                    : response()->json(['ok' => false, 'message' => __('app.not_sold_here')], 403);
            }
            if ($reads && $request->routeIs(...InApp::MARKETING_PAGES)) {
                return redirect()->to(InApp::homeUrl());
            }
        } elseif (InApp::buysOnWeb($request) && InApp::paysRoute($route)) {
            return $reads
                ? app(AppShellController::class)->openOnWeb($request)
                : response()->json(['ok' => false, 'message' => __('app.web.title')], 403);
        }

        $response = $next($request);

        if (str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            // Private, so no shared cache gives the app's page to a browser; not "no-store", so
            // going back shows the page that was there at once instead of asking for it again.
            if (! $response->headers->hasCacheControlDirective('no-store')) {
                $response->headers->set('Cache-Control', 'private, no-cache');
            }
            $response->headers->set('Vary', 'User-Agent', false);
        }
        if ($pageLanguage !== null && $request->cookies->get(InApp::LANGUAGE_COOKIE) !== $pageLanguage) {
            $response->headers->setCookie(cookie(InApp::LANGUAGE_COOKIE, $pageLanguage, 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'lax'));
        }
        // The app's mark, for the requests it makes without its name (InApp::COOKIE).
        if (InApp::namedApp($request) && $request->cookies->get(InApp::COOKIE) !== '1') {
            $response->headers->setCookie(cookie(InApp::COOKIE, '1', 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'lax'));
        }

        return $response;
    }
}
