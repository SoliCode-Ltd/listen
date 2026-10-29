<?php

namespace App\Http\Middleware;

use App\Mcp\ConnectedApps;
use App\Models\ApiKey;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ApiPlanAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who is calling the MCP server: a Listen API key (as on /v1), or an AI app
 * the member connected with "Sign in with Listen" (an OAuth token). Either
 * way the request carries a key, its plans and its member afterwards, so
 * the tools work the same. Nothing sent: 401, whose WWW-Authenticate header
 * tells the app where to sign in.
 */
class AuthenticateMcp
{
    public function __construct(private readonly AuthenticateApiKey $apiKeys) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = trim((string) $request->bearerToken());
        if ($bearer === '' || $request->hasHeader('X-Listen-Api-Key') || str_starts_with($bearer, ApiKey::PREFIX)) {
            return $this->apiKeys->handle($request, $next);
        }

        if (! ApiPlanAccess::siteEnabled()) {
            throw new ApiError(503, ApiError::TYPE_API, 'api_paused',
                'The API is paused for maintenance. Please try again later.', null, ['Retry-After' => '300']);
        }

        // The guard keeps the member it found: read this request's token afresh,
        // as a worker that serves many requests must.
        $guard = Auth::guard('api');
        $guard->forgetUser();
        $user = $guard->user();
        $token = $user instanceof User ? $user->token() : null;
        if (! $user instanceof User || ! $token instanceof AccessToken || ! $token->can(Registrar::OAUTH_SCOPE)) {
            $this->apiKeys->refused($request);

            throw new ApiError(401, ApiError::TYPE_AUTHENTICATION, 'invalid_token',
                'The sign-in has expired or was disconnected. Sign in with Listen again.');
        }

        $this->apiKeys->admit($request, $user, ConnectedApps::keyFor($user, (string) $token->oauth_client_id));

        return $next($request);
    }
}
