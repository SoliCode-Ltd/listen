<?php

use App\Console\Commands\DiagnoseVoiceCloningCommand;
use App\Console\Commands\EnforceBillingGraceCommand;
use App\Console\Commands\GenerateVoiceSamples;
use App\Console\Commands\ListenSyncExtensionCommand;
use App\Console\Commands\SendBillingRemindersCommand;
use App\Console\Commands\SendNewsletterBatchCommand;
use App\Console\Commands\SendQueuedNewslettersCommand;
use App\Console\Commands\SyncArabicTranslationsCommand;
use App\Console\Commands\SyncBillingSubscriptionsCommand;
use App\Console\Commands\SyncStripePlansCommand;
use App\Console\Commands\ValidateVoiceCatalogCommand;
use App\Http\Middleware\AgentCors;
use App\Http\Middleware\ApiRequestContext;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\AuthenticateMcp;
use App\Http\Middleware\CanonicalHost;
use App\Http\Middleware\CaptureAdClickId;
use App\Http\Middleware\CaptureReferralVisit;
use App\Http\Middleware\EnsureAuthMethodEnabled;
use App\Http\Middleware\EnsureDemoEmailVerified;
use App\Http\Middleware\EnsureFreeDemoAccess;
use App\Http\Middleware\EnsurePaidPlan;
use App\Http\Middleware\EnsureUserIsNotBlocked;
use App\Http\Middleware\InAppMode;
use App\Http\Middleware\RequireApiScope;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ServePagesToAgents;
use App\Http\Middleware\SyncSubscriptionStatus;
use App\Mcp\McpErrors;
use App\Support\Api\ApiError;
use App\Support\Api\ApiErrorRenderer;
use App\Support\InApp\InApp;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRoutes;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleCookieRedirect;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Developer API: https://<site>/v1/... (see routes/v1.php).
            Route::middleware(['api', ApiRequestContext::class])
                ->prefix('v1')
                ->group(base_path('routes/v1.php'));
            // Listen Agents' calls, from the websites that carry a call button (see routes/agents.php).
            Route::middleware(['api', AgentCors::class])
                ->prefix('agent-api')
                ->group(base_path('routes/agents.php'));
        },
    )
    ->withCommands([
        DiagnoseVoiceCloningCommand::class,
        EnforceBillingGraceCommand::class,
        GenerateVoiceSamples::class,
        ListenSyncExtensionCommand::class,
        SendBillingRemindersCommand::class,
        SendNewsletterBatchCommand::class,
        SendQueuedNewslettersCommand::class,
        SyncArabicTranslationsCommand::class,
        SyncBillingSubscriptionsCommand::class,
        SyncStripePlansCommand::class,
        ValidateVoiceCatalogCommand::class,
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // The listen_demo_fp cookie is set by client-side JavaScript (unencrypted).
        // It must be excluded from Laravel's cookie encryption so the backend
        // can read the fingerprint components on GET requests (page loads).
        $middleware->encryptCookies(except: ['listen_demo_fp', InApp::COOKIE]);

        // www. pages move to the main address first, so a page and its requests are one site.
        $middleware->prepend(CanonicalHost::class);

        $middleware->web(append: [
            EnsureUserIsNotBlocked::class,
            CaptureAdClickId::class,
            // The Listen app: nothing is sold inside it (App\Support\InApp\InApp).
            InAppMode::class,
            SecurityHeaders::class,
            ServePagesToAgents::class,
        ]);

        $middleware->alias([
            'subscription.sync' => SyncSubscriptionStatus::class,
            'demo.free' => EnsureFreeDemoAccess::class,
            'demo.verified' => EnsureDemoEmailVerified::class,
            'user.not_blocked' => EnsureUserIsNotBlocked::class,
            'auth.method' => EnsureAuthMethodEnabled::class,
            'referral.capture' => CaptureReferralVisit::class,
            'paid.plan' => EnsurePaidPlan::class,
            'localize' => LaravelLocalizationRoutes::class,
            'localizationRedirect' => LaravelLocalizationRedirectFilter::class,
            'localeSessionRedirect' => LocaleSessionRedirect::class,
            'localeCookieRedirect' => LocaleCookieRedirect::class,
            'localeViewPath' => LaravelLocalizationViewPath::class,
            'api.key' => AuthenticateApiKey::class,
            'api.scope' => RequireApiScope::class,
        ]);

        // The developer API's rate limit is per key and per plan, so the key
        // must be known before the limiter runs.
        $middleware->prependToPriorityList(
            before: ThrottleRequests::class,
            prepend: AuthenticateApiKey::class,
        );
        // And the request id and the admin's request log come first of all,
        // so refused keys are logged too.
        $middleware->prependToPriorityList(
            before: AuthenticateApiKey::class,
            prepend: ApiRequestContext::class,
        );
        // The MCP server (routes/ai.php) signs in with a key or an OAuth token, in the same place.
        $middleware->prependToPriorityList(
            before: AuthenticateApiKey::class,
            prepend: AuthenticateMcp::class,
        );
        // The Listen app: a page that pays is handed to the phone's browser (or left out) before
        // 'auth' can send a visitor to sign in inside the app.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: InAppMode::class,
        );
        // A friend opening a referral link is not signed in yet: the visit is recorded
        // (and the referral cookie set) before 'auth' sends them to sign in. Without
        // this, Laravel's priority order runs 'auth' first and the referral is lost.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: CaptureReferralVisit::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Developer API (/v1): every error in one JSON shape, never an HTML page.
        $exceptions->dontReport(ApiError::class);
        $exceptions->render(function (Throwable $e, Request $request) {
            return ApiErrorRenderer::appliesTo($request)
                ? ApiErrorRenderer::render($e, $request)
                : null;
        });
        // The MCP server (/mcp): the same refusals, as JSON-RPC errors.
        $exceptions->render(function (Throwable $e, Request $request) {
            return McpErrors::appliesTo($request)
                ? McpErrors::render($e, $request)
                : null;
        });

        // Stale CSRF tokens (mobile tab restored, back button, idle page) get a
        // retry instead of the bare "419 Page Expired" screen.
        $exceptions->render(function (HttpException $e, Request $request) {
            if (! $e->getPrevious() instanceof TokenMismatchException) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => __('ui.auth.session_expired'),
                ], 419);
            }

            if ($request->user() && $request->routeIs('login.submit', 'register.submit')) {
                return redirect()->route('dashboard');
            }

            return back()
                ->withInput($request->except(['password', 'password_confirmation', '_token']))
                ->withErrors(['auth' => __('ui.auth.session_expired')]);
        });
    })->create();
