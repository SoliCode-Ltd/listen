<?php

namespace App\Http\Middleware;

use App\Models\ApiRequestLog;
use App\Support\Api\ApiError;
use App\Support\Api\ApiErrorRenderer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * First stop for every developer API request: gives it a request id (sent
 * back as X-Request-Id on every answer, errors included) and answers in
 * English, whatever the site's language, so messages are stable for code.
 * Each request is written to the admin's request log on the way out.
 */
class ApiRequestContext
{
    /**
     * Requests one address may send to /v1 a minute, whatever they are (keys
     * or not, any path): far above any plan's own limits, it only stops floods
     * before they reach the database.
     */
    public const PER_ADDRESS_PER_MINUTE = 2000;

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        $requestId = ApiErrorRenderer::requestId($request);
        app()->setLocale('en');
        // Framework errors (validation, 404) are rendered as JSON for the API.
        $request->headers->set('Accept', 'application/json');

        $addressKey = 'v1-address:'.$request->ip();
        if (RateLimiter::tooManyAttempts($addressKey, self::PER_ADDRESS_PER_MINUTE)) {
            // Answered at once and not logged: a flood never fills the request log.
            return ApiErrorRenderer::respond(new ApiError(429, ApiError::TYPE_RATE_LIMIT, 'too_many_requests',
                'Too many requests from this address. Try again in a minute.', null,
                ['Retry-After' => (string) max(1, RateLimiter::availableIn($addressKey))]), $request);
        }
        RateLimiter::hit($addressKey, 60);

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
