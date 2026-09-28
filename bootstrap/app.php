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

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
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
        ]);

        $middleware->alias([
            'subscription.sync' => \App\Http\Middleware\SyncSubscriptionStatus::class,
            'demo.free' => \App\Http\Middleware\EnsureFreeDemoAccess::class,
            'user.not_blocked' => \App\Http\Middleware\EnsureUserIsNotBlocked::class,
            'auth.method' => \App\Http\Middleware\EnsureAuthMethodEnabled::class,
            'referral.capture' => \App\Http\Middleware\CaptureReferralVisit::class,
            'paid.plan' => \App\Http\Middleware\EnsurePaidPlan::class,
            'localize' => \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRoutes::class,
            'localizationRedirect' => \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            'localeSessionRedirect' => \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            'localeCookieRedirect' => \Mcamara\LaravelLocalization\Middleware\LocaleCookieRedirect::class,
            'localeViewPath' => \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
