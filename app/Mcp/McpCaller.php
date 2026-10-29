<?php

namespace App\Mcp;

use App\Models\ApiKey;
use App\Models\Plan;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\GenerationSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who is calling the MCP server, as the /mcp route's middleware found them:
 * the member, the key (its permissions and its own credit limit), the plan
 * that grants access and the plan whose credits are used.
 *
 * Tools run the developer API's own controllers through call(), so a tool
 * checks, charges and answers exactly as the matching /v1 endpoint does.
 */
final class McpCaller
{
    /** What the API's middleware puts on a request, copied onto each call. */
    private const ATTRIBUTES = ['api_key', 'api_plan', 'api_quota_plan', 'listen_actor_user_id', 'api_request_id'];

    private function __construct(
        public readonly User $user,
        public readonly ApiKey $apiKey,
        public readonly ?Plan $plan,
        public readonly ?Plan $quotaPlan,
        private readonly Request $request,
    ) {}

    public static function current(): self
    {
        $request = request();
        $apiKey = $request->attributes->get('api_key');
        $user = $request->user();
        if (! $apiKey instanceof ApiKey || ! $user instanceof User) {
            throw ApiError::missingKey();
        }

        return new self($user, $apiKey, $request->attributes->get('api_plan'), $request->attributes->get('api_quota_plan'), $request);
    }

    public function allows(string $scope): bool
    {
        return $this->apiKey->allows($scope);
    }

    /**
     * A request for a /v1 endpoint, made by this caller, with $input as its
     * query (GET) or JSON body.
     *
     * @param  array<string, mixed>  $input
     */
    public function request(string $method, string $path, array $input = []): Request
    {
        $get = strtoupper($method) === 'GET';
        $call = Request::create(url('/v1'.$path), strtoupper($method), $get ? $input : [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => (string) $this->request->ip(),
        ], $get ? null : json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        foreach (self::ATTRIBUTES as $attribute) {
            if ($this->request->attributes->has($attribute)) {
                $call->attributes->set($attribute, $this->request->attributes->get($attribute));
            }
        }
        // So what it makes is recorded as made by an AI app, not the API (App\Support\GenerationSource).
        $call->attributes->set('listen_source', GenerationSource::MCP);
        $user = $this->user;
        $call->setUserResolver(static fn () => $user);

        return $call;
    }

    /**
     * Runs a /v1 controller action for this caller and returns its JSON.
     * The API's errors (ApiError, validation) are thrown as they are.
     *
     * @param  class-string  $controller
     * @param  array<string, mixed>  $input
     * @param  array<int, mixed>  $arguments  route parameters after the request
     * @return array<string, mixed>
     */
    public function call(string $controller, string $action, string $method, string $path, array $input = [], array $arguments = []): array
    {
        $response = app($controller)->{$action}($this->request($method, $path, $input), ...$arguments);

        return $response instanceof JsonResponse ? (array) $response->getData(true) : [];
    }

    /**
     * Notes what a tool call did for the admin's request log (System → API
     * requests): the tool, and the generation and its cost when there is one.
     *
     * @param  array{history_item_id?: int|null, credits?: int|null, characters?: int|null, error_code?: string|null}  $details
     */
    public function log(string $tool, array $details = []): void
    {
        $this->request->attributes->set('api_log', ['endpoint' => 'mcp.'.$tool] + $details);
    }
}
