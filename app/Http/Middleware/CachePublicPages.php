<?php

namespace App\Http\Middleware;

use App\Support\PublicPageCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public marketing pages, kept by Cloudflare for visitors nobody knows yet
 * (App\Support\PublicPageCache). First in the web group, so it sees the cookies every
 * other part of the group adds to the answer.
 */
class CachePublicPages
{
    public function handle(Request $request, Closure $next): Response
    {
        return PublicPageCache::apply($request, $next($request));
    }
}
