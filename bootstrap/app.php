<?php

use App\Console\Commands\EnforceBillingGraceCommand;
use App\Console\Commands\GenerateVoiceSamples;
use App\Console\Commands\ListenSyncExtensionCommand;
use App\Console\Commands\SendBillingRemindersCommand;
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
        EnforceBillingGraceCommand::class,
        GenerateVoiceSamples::class,
        ListenSyncExtensionCommand::class,
        SendBillingRemindersCommand::class,
        SyncArabicTranslationsCommand::class,
        SyncBillingSubscriptionsCommand::class,
        SyncStripePlansCommand::class,
        ValidateVoiceCatalogCommand::class,
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'subscription.sync' => \App\Http\Middleware\SyncSubscriptionStatus::class,
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
