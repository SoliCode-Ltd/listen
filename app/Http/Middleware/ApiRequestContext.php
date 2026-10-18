<?php

namespace App\Http\Middleware;

use App\Support\Api\ApiErrorRenderer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First stop for every developer API request: gives it a request id (sent
 * back as X-Request-Id on every answer, errors included) and answers in
 * English, whatever the site's language, so messages are stable for code.
 */
class ApiRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = ApiErrorRenderer::requestId($request);
        app()->setLocale('en');
        // Framework errors (validation, 404) are rendered as JSON for the API.
        $request->headers->set('Accept', 'application/json');

        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);
        if (! $response->headers->has('Cache-Control') || $response->headers->get('Cache-Control') === 'no-cache, private') {
            $response->headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }
}
