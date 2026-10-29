<?php

namespace App\Mcp;

use App\Models\User;
use App\Services\PlanAccessService;
use Carbon\CarbonInterval;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Client;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Sign in with Listen": OAuth 2.1 for AI apps on the MCP server, run by
 * Laravel Passport, with the discovery documents and app registration of
 * laravel/mcp (routes/ai.php). An app registers itself, the member signs in
 * and allows it on Listen's own page (resources/views/oauth/authorize), and
 * the app gets a token for /mcp only. What it may do there is its connected
 * app key's permissions (App\Mcp\ConnectedApps).
 */
final class SignInWithListen
{
    /** Before Passport registers its routes: AI apps sign in in the browser, never with a device code. */
    public static function register(): void
    {
        Passport::$deviceCodeGrantEnabled = false;
    }

    public static function configure(): void
    {
        // Short-lived access, renewed by the app in the background; a month of
        // not using the app at all and the member signs in again.
        Passport::tokensExpireIn(CarbonInterval::hour());
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));
        Passport::tokensCan([Registrar::OAUTH_SCOPE => 'Use Listen from an AI app']);
        // An app that asks for no scope still gets the one the MCP server needs.
        Passport::setDefaultScope([Registrar::OAUTH_SCOPE]);
        Passport::authorizationView(static fn (array $parameters): Response => self::consent($parameters));

        // The app shows in the dashboard as soon as the member allows it.
        Event::listen(AccessTokenCreated::class, static function (AccessTokenCreated $event): void {
            $user = $event->userId !== null ? User::query()->find($event->userId) : null;
            if ($user instanceof User) {
                ConnectedApps::keyFor($user, $event->clientId);
            }
        });
    }

    /**
     * The page where the member allows an app, or learns that their plan does
     * not include it yet.
     *
     * @param  array{client: Client, user: User, scopes: array<int, mixed>, request: Request, authToken: string}  $parameters
     */
    public static function consent(array $parameters): Response
    {
        $request = $parameters['request'];
        $user = $parameters['user'];
        $locale = self::locale($request);
        app()->setLocale($locale);

        $plans = app(PlanAccessService::class);
        $plan = $plans->resolveAccessPlanForUserId((int) $user->id);

        return response()->view('oauth.authorize', [
            'client' => $parameters['client'],
            'user' => $user,
            'authToken' => $parameters['authToken'],
            // The same request in the other language (the page's own switch: it has no /en or /ar address).
            'switchUrl' => $request->fullUrlWithQuery(['hl' => $locale === 'ar' ? 'en' : 'ar']),
            'returnsTo' => self::returnsTo((string) $request->query('redirect_uri', '')),
            'status' => McpAccess::statusFor((int) $user->id, $plan),
            'planName' => $plan ? ($plan->getLocalizedName($locale) ?: (string) $plan->name) : __('ui.dashboard.free_plan'),
        ])->withHeaders(['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    /**
     * The page's language: the one chosen on it (kept for the next app), else
     * the browser's. The page sits outside the site's /en and /ar addresses.
     */
    private static function locale(Request $request): string
    {
        $chosen = (string) $request->query('hl', '');
        if (in_array($chosen, ['ar', 'en'], true)) {
            $request->session()->put('connect_locale', $chosen);

            return $chosen;
        }

        $kept = (string) $request->session()->get('connect_locale', '');
        if (in_array($kept, ['ar', 'en'], true)) {
            return $kept;
        }

        return str_starts_with(strtolower(trim((string) $request->header('Accept-Language'))), 'ar') ? 'ar' : 'en';
    }

    /**
     * Where the member goes back to after allowing: the address's host (or,
     * for an app on the computer, its scheme), so a look-alike name cannot
     * hide where the access really goes.
     */
    public static function returnsTo(string $redirectUri): string
    {
        $scheme = strtolower((string) parse_url($redirectUri, PHP_URL_SCHEME));
        $host = (string) parse_url($redirectUri, PHP_URL_HOST);

        return in_array($scheme, ['http', 'https'], true) ? $host : $scheme.'://'.$host;
    }
}
