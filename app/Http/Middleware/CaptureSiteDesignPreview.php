<?php

namespace App\Http\Middleware;

use App\Support\SiteDesign;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stores an admin's ?design= preview choice in their session before any locale
 * redirect can drop the query string. Does nothing for everyone else.
 */
class CaptureSiteDesignPreview
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->query->has(SiteDesign::PREVIEW_QUERY_KEY)) {
            SiteDesign::preview($request);
        }

        return $next($request);
    }
}
