<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Api\OpenApiSpec;
use Illuminate\Http\JsonResponse;

/**
 * GET /v1/openapi.json: the API's OpenAPI 3.1 description (no key needed).
 */
class OpenApiController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(OpenApiSpec::build(), 200, [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=3600',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
