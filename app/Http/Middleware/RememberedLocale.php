<?php

namespace App\Http\Middleware;

use App\Support\HomeRedirect;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Before mcamara's session redirect (an address without a language goes to the visitor's
 * last one): a visitor whose session does not know their language yet brings it in the
 * listen_locale cookie, because the public pages Cloudflare keeps give no session
 * (App\Support\PublicPageCache, HomeRedirect).
 */
class RememberedLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && ! $request->session()->has('locale')) {
            $remembered = HomeRedirect::locale($request->cookies->get(HomeRedirect::LOCALE_COOKIE));
            if ($remembered !== null) {
                $request->session()->put('locale', $remembered);
            }
        }

        return $next($request);
    }
}
