<?php

namespace App\Support;

use App\Support\InApp\InApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public marketing pages, for a visitor nobody knows yet, are the same for everyone: they
 * may be kept by Cloudflare for a few minutes and given to the next visitor at once.
 *
 * A page is kept only when all of these hold:
 * - it is one of PAGES: the home page, the voice samples, the voice agents page, the SEO,
 *   dialect, accent and feature pages, the legal pages and the developer docs, in either
 *   language (the address names it, so a kept page is never in the wrong language). Not
 *   pricing (its prices are in the visitor's own currency, worked out from their country),
 *   nor a page with a form that needs the visitor's form token (contact, the extension
 *   page, video dubbing, the demo), nor anything for members;
 * - the visitor brings no session, no "remember me" cookie and is not the Listen app: so
 *   nobody is signed in, there is no message waiting for them (a flash) and the page has
 *   nothing of theirs;
 * - a GET or HEAD without a query string (an ad's click, a referral or a campaign link
 *   reaches the server every time, which keeps them), answered 200 with an HTML page (not
 *   the Markdown an AI agent asked for, at the same address);
 * - the page sets no cookie of its own.
 *
 * Such a visitor is given no session or form-token cookie on these pages (nothing on them
 * uses one), with or without a query string, so the pages they open next can be kept too.
 * What is kept is told to Cloudflare alone (Cloudflare-CDN-Cache-Control); for every other
 * cache the page stays private, as today. Everything else is answered as before: private, with
 * its cookies. Cloudflare's own rules
 * (the pull request's notes) let it keep only pages under /ar and /en, and never for a
 * visitor with the session, "remember me" or app cookie, or the app's name.
 */
final class PublicPageCache
{
    /** Route names (patterns as Request::routeIs takes them). */
    public const PAGES = ['home', 'samples', 'agents-landing', 'seo.*', 'legal.*', 'developers'];

    /** How long Cloudflare keeps a page: an edit in the admin shows within this (or purge the cache). */
    public const SECONDS = 600;

    /** Cloudflare's own cache header: only Cloudflare reads it, and it strips it before the browser. */
    public const CLOUDFLARE_HEADER = 'Cloudflare-CDN-Cache-Control';

    /** Says what was decided, to check a page from outside: public, anonymous (no cookie, not kept) or private. */
    public const HEADER = 'X-Page-Cache';

    public static function apply(Request $request, Response $response): Response
    {
        if (! self::anonymous($request, $response)) {
            return $response;
        }

        self::dropSessionCookies($response);

        if ($request->getQueryString() === null && $response->headers->getCookies() === []) {
            // For Cloudflare only (it reads this header alone when it is there, and never passes it on).
            // Every other cache, the server's own (Varnish) and the browser's included, keeps the usual
            // "private": Varnish does not tell the Listen app from a browser, Cloudflare's rules do.
            $response->headers->set(self::CLOUDFLARE_HEADER, 'max-age='.self::SECONDS.', stale-while-revalidate=60');
            $response->headers->set('Cache-Control', 'no-cache, private');
            $response->headers->set(self::HEADER, 'public');
        } else {
            $response->headers->set(self::HEADER, 'anonymous');
        }

        return $response;
    }

    /** A public page, for a visitor with nothing of their own on it. */
    public static function anonymous(Request $request, Response $response): bool
    {
        return in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && $request->route() !== null
            && $request->routeIs(...self::PAGES)
            && ! self::bringsAnIdentity($request)
            && Auth::guest()
            && $response->getStatusCode() === 200
            && str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')
            && ! $response->headers->hasCacheControlDirective('no-store');
    }

    /** The cookies or name that make a visitor someone: a session, "remember me", the Listen app. */
    public static function bringsAnIdentity(Request $request): bool
    {
        foreach (array_keys($request->cookies->all()) as $name) {
            $name = (string) $name;
            if ($name === (string) config('session.cookie') || str_starts_with($name, 'remember_')
                || $name === InApp::COOKIE || $name === InApp::LANGUAGE_COOKIE) {
                return true;
            }
        }

        return str_contains((string) $request->userAgent(), InApp::UA_TOKEN);
    }

    private static function dropSessionCookies(Response $response): void
    {
        $drop = [(string) config('session.cookie'), 'XSRF-TOKEN'];
        foreach ($response->headers->getCookies() as $cookie) {
            /** @var Cookie $cookie */
            if (in_array($cookie->getName(), $drop, true)) {
                $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
        }
    }
}
