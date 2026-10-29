<?php

namespace App\Http\Middleware;

use App\Mcp\McpAccess;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\BillingSyncService;
use App\Services\BlockedUserService;
use App\Services\PlanAccessService;
use App\Support\Api\ApiError;
use App\Support\Api\ApiPlanAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a developer API request by its key (X-Listen-Api-Key header,
 * or "Authorization: Bearer"), and refuses keys that are revoked, members who
 * are blocked, plans that do not include the API and unpaid trials.
 *
 * Sets on the request: api_key (ApiKey), api_plan (?Plan, the access plan),
 * api_quota_plan (?Plan) and the user resolver.
 */
class AuthenticateApiKey
{
    /** Wrong keys one address may send a minute before they are answered with 429. */
    private const FAILED_ATTEMPTS_PER_MINUTE = 30;

    public function __construct(
        private readonly PlanAccessService $planAccessService,
        private readonly BillingSyncService $billingSyncService,
        private readonly BlockedUserService $blockedUserService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The admin's switch for the whole API (Admin → API keys → Pause the API).
        if (! ApiPlanAccess::siteEnabled()) {
            throw new ApiError(503, ApiError::TYPE_API, 'api_paused',
                'The API is paused for maintenance. Please try again later.', null, ['Retry-After' => '300']);
        }

        $plain = self::providedKey($request);
        if ($plain === '') {
            $this->refused($request);

            throw ApiError::missingKey();
        }

        $apiKey = ApiKey::findActiveByPlain($plain);
        $user = $apiKey?->user;
        if (! $apiKey || ! $user instanceof User) {
            $this->refused($request);

            throw ApiError::invalidKey();
        }

        $this->admit($request, $user, $apiKey);

        return $next($request);
    }

    /**
     * Lets the member's key in, or refuses it: a blocked member, a plan that
     * does not include it, an unpaid trial. On the way it brings the plan up
     * to date and puts the key, the plans and the member on the request.
     * An AI app connected with "Sign in with Listen" (App\Mcp\ConnectedApps)
     * comes in the same way, under the MCP server's plan rule.
     */
    public function admit(Request $request, User $user, ApiKey $apiKey): void
    {
        if ($this->blockedUserService->isBlocked($user)) {
            $this->refused($request);

            throw ApiError::accountBlocked();
        }

        try {
            $this->billingSyncService->syncIfStale($user);
        } catch (\Throwable $e) {
            // A billing provider hiccup must not take the API down; the last known plan applies.
            Log::warning('API billing sync failed; using the last known plan.', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        $plan = $this->planAccessService->resolveAccessPlanForUserId((int) $user->id);
        $connectedApp = $apiKey->isConnectedApp();
        $status = $connectedApp ? McpAccess::statusFor((int) $user->id, $plan) : ApiPlanAccess::statusFor((int) $user->id, $plan);
        if ($status !== ApiPlanAccess::OK) {
            $this->refused($request);

            throw match (true) {
                $status === ApiPlanAccess::TRIAL => ApiError::notDuringTrial(),
                $connectedApp => McpAccess::notInPlan(),
                default => ApiError::notInPlan(),
            };
        }
        $quotaPlan = $this->planAccessService->resolveQuotaPlanForUserId((int) $user->id, $plan);

        $apiKey->touchLastUsed($request->ip());

        $request->attributes->set('api_key', $apiKey);
        $request->attributes->set('api_plan', $plan);
        $request->attributes->set('api_quota_plan', $quotaPlan);
        $request->attributes->set('listen_actor_user_id', (int) $user->id);
        $request->setUserResolver(static fn () => $user);
    }

    /**
     * Counts a refused request (no key, a wrong or revoked key, or a key the
     * API will not serve) against its address, and answers 429 once there are
     * too many in a minute. Only refused requests count: a valid key from the
     * same server keeps working while an old one is still being sent.
     */
    public function refused(Request $request): void
    {
        $failedKey = 'api-auth-failed:'.$request->ip();
        if (RateLimiter::tooManyAttempts($failedKey, self::FAILED_ATTEMPTS_PER_MINUTE)) {
            throw new ApiError(429, ApiError::TYPE_RATE_LIMIT, 'too_many_failed_attempts',
                'Too many refused requests from this address (no key, a wrong key, or a key the API cannot serve). Try again in a minute.', null,
                ['Retry-After' => (string) max(1, RateLimiter::availableIn($failedKey))]);
        }
        RateLimiter::hit($failedKey, 60);
    }

    public static function providedKey(Request $request): string
    {
        $key = $request->header('X-Listen-Api-Key');
        if (! is_string($key) || trim($key) === '') {
            $key = (string) $request->bearerToken();
        }

        return trim((string) $key);
    }
}
