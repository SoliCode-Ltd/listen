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
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Developer API: https://<site>/v1/... (see routes/v1.php).
            \Illuminate\Support\Facades\Route::middleware(['api', \App\Http\Middleware\ApiRequestContext::class])
                ->prefix('v1')
                ->group(base_path('routes/v1.php'));
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
        $middleware->encryptCookies(except: ['listen_demo_fp']);

        $middleware->web(append: [
            \App\Http\Middleware\EnsureUserIsNotBlocked::class,
            \App\Http\Middleware\CaptureAdClickId::class,
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\ServePagesToAgents::class,
        ]);

        $middleware->alias([
            'subscription.sync' => \App\Http\Middleware\SyncSubscriptionStatus::class,
            'demo.free' => \App\Http\Middleware\EnsureFreeDemoAccess::class,
            'demo.verified' => \App\Http\Middleware\EnsureDemoEmailVerified::class,
            'user.not_blocked' => \App\Http\Middleware\EnsureUserIsNotBlocked::class,
            'auth.method' => \App\Http\Middleware\EnsureAuthMethodEnabled::class,
            'referral.capture' => \App\Http\Middleware\CaptureReferralVisit::class,
            'paid.plan' => \App\Http\Middleware\EnsurePaidPlan::class,
            'localize' => \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRoutes::class,
            'localizationRedirect' => \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            'localeSessionRedirect' => \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            'localeCookieRedirect' => \Mcamara\LaravelLocalization\Middleware\LocaleCookieRedirect::class,
            'localeViewPath' => \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
            'api.key' => \App\Http\Middleware\AuthenticateApiKey::class,
            'api.scope' => \App\Http\Middleware\RequireApiScope::class,
        ]);

        // The developer API's rate limit is per key and per plan, so the key
        // must be known before the limiter runs.
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\ThrottleRequests::class,
            prepend: \App\Http\Middleware\AuthenticateApiKey::class,
        );
        // And the request id and the admin's request log come first of all,
        // so refused keys are logged too.
        $middleware->prependToPriorityList(
            before: \App\Http\Middleware\AuthenticateApiKey::class,
            prepend: \App\Http\Middleware\ApiRequestContext::class,
        );
        // A friend opening a referral link is not signed in yet: the visit is recorded
        // (and the referral cookie set) before 'auth' sends them to sign in. Without
        // this, Laravel's priority order runs 'auth' first and the referral is lost.
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \App\Http\Middleware\CaptureReferralVisit::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Developer API (/v1): every error in one JSON shape, never an HTML page.
        $exceptions->dontReport(\App\Support\Api\ApiError::class);
        $exceptions->render(function (\Throwable $e, Request $request) {
            return \App\Support\Api\ApiErrorRenderer::appliesTo($request)
                ? \App\Support\Api\ApiErrorRenderer::render($e, $request)
                : null;
        });
        // The MCP server (/mcp): the same refusals, as JSON-RPC errors.
        $exceptions->render(function (\Throwable $e, Request $request) {
            return \App\Mcp\McpErrors::appliesTo($request)
                ? \App\Mcp\McpErrors::render($e, $request)
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
