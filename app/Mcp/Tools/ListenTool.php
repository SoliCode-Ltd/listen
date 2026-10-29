<?php

namespace App\Mcp\Tools;

use App\Mcp\McpCaller;
use App\Support\Api\ApiError;
use App\Support\Api\ApiErrorRenderer;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * A Listen MCP tool. Each one needs one of the API key's permissions: a key
 * without it does not see the tool. The API's errors come back as the tool's
 * error, with the API's error code first so an agent can act on it, and the
 * call is noted in the admin's request log.
 */
abstract class ListenTool extends Tool
{
    /** The key permission (App\Support\Api\ApiScopes) the tool needs. */
    abstract protected function scope(): string;

    abstract protected function run(Request $request, McpCaller $caller): Response|ResponseFactory;

    public function shouldRegister(): bool
    {
        $apiKey = request()->attributes->get('api_key');

        return $apiKey !== null && $apiKey->allows($this->scope());
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $caller = McpCaller::current();
        $caller->log($this->name());

        try {
            if (! $caller->allows($this->scope())) {
                throw ApiError::missingScope($this->scope());
            }

            return $this->run($request, $caller);
        } catch (ApiError|ValidationException $e) {
            $error = ApiErrorRenderer::toApiError($e);
            $caller->log($this->name(), ['error_code' => $error->errorCode] + self::historyOf($error));

            return Response::error("{$error->errorCode}: {$error->getMessage()}");
        }
    }

    /** @return array{history_item_id?: int} */
    private static function historyOf(ApiError $error): array
    {
        $id = $error->extra['history_item_id'] ?? null;

        return is_int($id) ? ['history_item_id' => $id] : [];
    }
}
