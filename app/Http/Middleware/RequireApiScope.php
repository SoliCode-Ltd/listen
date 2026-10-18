<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Support\Api\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the request through only when its API key holds the permission
 * (route middleware: api.scope:text_to_speech).
 */
class RequireApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $apiKey = $request->attributes->get('api_key');
        if (! $apiKey instanceof ApiKey) {
            throw ApiError::missingKey();
        }

        if (! $apiKey->allows($scope)) {
            throw ApiError::missingScope($scope);
        }

        return $next($request);
    }
}
