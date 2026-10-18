<?php

namespace App\Http\Middleware;

use App\Models\ApiRequestLog;
use App\Support\Api\ApiErrorRenderer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First stop for every developer API request: gives it a request id (sent
 * back as X-Request-Id on every answer, errors included) and answers in
 * English, whatever the site's language, so messages are stable for code.
 * Each request is written to the admin's request log on the way out.
 */
class ApiRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        $requestId = ApiErrorRenderer::requestId($request);
        app()->setLocale('en');
        // Framework errors (validation, 404) are rendered as JSON for the API.
        $request->headers->set('Accept', 'application/json');

        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);
        if (! $response->headers->has('Cache-Control') || $response->headers->get('Cache-Control') === 'no-cache, private') {
            $response->headers->set('Cache-Control', 'no-store');
        }

        // Every request, for the admin (System → API requests).
        ApiRequestLog::record($request, $response, $requestId, $startedAt);

        return $response;
    }
}
