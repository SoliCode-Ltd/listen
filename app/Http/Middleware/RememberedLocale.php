<?php

namespace App\Http\Middleware;

use App\Support\HomeRedirect;
use App\Support\InApp\InApp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Before mcamara's session redirect (an address without a language goes to the visitor's
 * last one): the last one is the language of the page the visitor last read, which the page
 * itself keeps in the listen_locale cookie (components/page-head, HomeRedirect).
 *
 * Not the session's: every page fetches the other language's version ahead (the language
 * switch, components/scripts), and mcamara stores the language of each address asked for,
 * so the session ended up in the language the visitor did not read. A fetch ahead runs no
 * script, so the cookie stays right, and a page the browser shows from that fetch sets it.
 * The public pages Cloudflare keeps give no session at all (App\Support\PublicPageCache).
 *
 * Without the cookie (no script), the session decides as before. The Listen app keeps its
 * own language rules (InAppMode).
 */
class RememberedLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && ! InApp::active($request)) {
            $remembered = HomeRedirect::locale($request->cookies->get(HomeRedirect::LOCALE_COOKIE));
            if ($remembered !== null) {
                $request->session()->put('locale', $remembered);
            }
        }

        return $next($request);
    }
}
