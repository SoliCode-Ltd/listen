<?php

namespace App\Mcp;

use App\Support\Api\ApiErrorRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * What the MCP endpoint answers when a request is refused before any tool
 * runs (no key, a revoked key, a plan without the API, too many requests):
 * a JSON-RPC error with the HTTP status the API uses for it, and the API's
 * error code in its data. Errors inside a tool are the tool's own answer
 * (App\Mcp\Tools\ListenTool).
 */
final class McpErrors
{
    public static function appliesTo(Request $request): bool
    {
        return $request->is('mcp');
    }

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        $error = ApiErrorRenderer::toApiError($e);
        $message = $error->errorCode === 'missing_api_key'
            ? 'Sign in with Listen, or send a Listen API key as "Authorization: Bearer <key>".'
            : $error->getMessage();
        $requestId = ApiErrorRenderer::requestId($request);
        $request->attributes->set('api_log', ['endpoint' => 'mcp', 'error_code' => $error->errorCode]);

        $body = json_decode((string) $request->getContent(), true);
        $id = is_array($body) && (is_int($body['id'] ?? null) || is_string($body['id'] ?? null)) ? $body['id'] : null;

        return new JsonResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => match (true) {
                    in_array($error->status, [401, 403], true) => -32001,
                    $error->status >= 500 => -32603,
                    default => -32000,
                },
                'message' => $message,
                'data' => ['type' => $error->type, 'code' => $error->errorCode, 'request_id' => $requestId] + $error->extra,
            ],
        ], $error->status, $error->headers + [
            'X-Request-Id' => $requestId,
            'Cache-Control' => 'no-store',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
