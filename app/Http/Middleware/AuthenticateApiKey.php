<?php

namespace App\Http\Middleware;

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
        $plain = self::providedKey($request);
        if ($plain === '') {
            throw ApiError::missingKey();
        }

        $apiKey = ApiKey::findActiveByPlain($plain);
        $user = $apiKey?->user;
        if (! $apiKey || ! $user instanceof User) {
            // Only wrong keys are slowed down: a valid key from the same server
            // keeps working while an old, revoked key is still being sent.
            $failedKey = 'api-auth-failed:'.$request->ip();
            if (RateLimiter::tooManyAttempts($failedKey, self::FAILED_ATTEMPTS_PER_MINUTE)) {
                throw new ApiError(429, ApiError::TYPE_RATE_LIMIT, 'too_many_failed_attempts',
                    'Too many requests with a wrong API key from this address. Try again in a minute.', null,
                    ['Retry-After' => (string) max(1, RateLimiter::availableIn($failedKey))]);
            }
            RateLimiter::hit($failedKey, 60);

            throw ApiError::invalidKey();
        }

        if ($this->blockedUserService->isBlocked($user)) {
            throw ApiError::accountBlocked();
        }

        try {
            $this->billingSyncService->syncIfStale($user);
        } catch (\Throwable $e) {
            // A billing provider hiccup must not take the API down; the last known plan applies.
            Log::warning('API billing sync failed; using the last known plan.', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        $plan = $this->planAccessService->resolveAccessPlanForUserId((int) $user->id);
        $status = ApiPlanAccess::statusFor((int) $user->id, $plan);
        if ($status === ApiPlanAccess::TRIAL) {
            throw ApiError::notDuringTrial();
        }
        if ($status !== ApiPlanAccess::OK) {
            throw ApiError::notInPlan();
        }
        $quotaPlan = $this->planAccessService->resolveQuotaPlanForUserId((int) $user->id, $plan);

        $apiKey->touchLastUsed($request->ip());

        $request->attributes->set('api_key', $apiKey);
        $request->attributes->set('api_plan', $plan);
        $request->attributes->set('api_quota_plan', $quotaPlan);
        $request->attributes->set('listen_actor_user_id', (int) $user->id);
        $request->setUserResolver(static fn () => $user);

        return $next($request);
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
