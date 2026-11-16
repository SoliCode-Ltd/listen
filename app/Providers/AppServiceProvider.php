<?php

namespace App\Providers;

use App\Console\Commands\EnforceBillingGraceCommand;
use App\Console\Commands\GenerateVoiceSamples;
use App\Console\Commands\ListenSyncExtensionCommand;
use App\Console\Commands\SendBillingRemindersCommand;
use App\Console\Commands\SyncArabicTranslationsCommand;
use App\Console\Commands\SyncBillingSubscriptionsCommand;
use App\Console\Commands\SyncStripePlansCommand;
use App\Console\Commands\ValidateVoiceCatalogCommand;
use App\Contracts\TtsProviderInterface;
use App\Mcp\SignInWithListen;
use App\Models\AppDevice;
use App\Models\BillingSubscription;
use App\Models\BillingSubscriptionItem;
use App\Models\ClonedVoice;
use App\Models\SiteSetting;
use App\Policies\ClonedVoicePolicy;
use App\Services\Billing\CashierStripeCardUpdateGateway;
use App\Services\Billing\CashierStripePackGateway;
use App\Services\Billing\CashierStripeTrialGateway;
use App\Services\Billing\StripeCardUpdateGateway;
use App\Services\Billing\StripePackGateway;
use App\Services\Billing\StripeTrialGateway;
use App\Services\DemoSamePerson;
use App\Services\Geo\UserCountryRecorder;
use App\Services\Tts\ArabicVoiceMapper;
use App\Services\Tts\GeminiTtsProvider;
use App\Services\Tts\GoogleCloudTtsProvider;
use App\Services\Tts\TtsRoutingService;
use App\Services\TtsService;
use App\Support\GeminiSpend;
use App\Support\InApp\InApp;
use App\Support\TitanMailbox;
use Filament\Tables\Table;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Mcamara\LaravelLocalization\Traits\LoadsTranslatedCachedRoutes;

class AppServiceProvider extends ServiceProvider
{
    use LoadsTranslatedCachedRoutes;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        Cashier::ignoreRoutes();
        SignInWithListen::register();
        $this->app->bind(StripeTrialGateway::class, CashierStripeTrialGateway::class);
        $this->app->bind(StripePackGateway::class, CashierStripePackGateway::class);
        $this->app->bind(StripeCardUpdateGateway::class, CashierStripeCardUpdateGateway::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                EnforceBillingGraceCommand::class,
                GenerateVoiceSamples::class,
                ListenSyncExtensionCommand::class,
                SendBillingRemindersCommand::class,
                SyncArabicTranslationsCommand::class,
                SyncBillingSubscriptionsCommand::class,
                SyncStripePlansCommand::class,
                ValidateVoiceCatalogCommand::class,
            ]);
        }

        // One for each request, so a demo request works out who is the same person once.
        $this->app->scoped(DemoSamePerson::class);

        // Register TTS providers as singletons
        $this->app->singleton(GoogleCloudTtsProvider::class);
        $this->app->singleton(GeminiTtsProvider::class);
        $this->app->singleton(ArabicVoiceMapper::class);
        $this->app->singleton(TtsRoutingService::class, function ($app) {
            return new TtsRoutingService(
                $app->make(GoogleCloudTtsProvider::class),
                $app->make(GeminiTtsProvider::class),
            );
        });

        // Inject routing service into TtsService (backward compatible)
        $this->app->singleton(TtsService::class, function ($app) {
            $service = new TtsService;
            $service->setRoutingService($app->make(TtsRoutingService::class));
            $service->setArabicMapper($app->make(ArabicVoiceMapper::class));

            return $service;
        });

        // Bind the interface to Google Cloud by default
        $this->app->bind(TtsProviderInterface::class, GoogleCloudTtsProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(Login::class, UserCountryRecorder::class);

        // The Listen app (App\Support\InApp\InApp): @inapp ... @else ... @endinapp in the pages,
        // and signing out on a phone stops that phone's notifications.
        Blade::if('inapp', fn (): bool => InApp::hidesPlans());
        // Inside the app every link the site makes to a purchase page is the neutral plan page's
        // link instead, wherever it is made (a page, a script's settings, a JSON reply), so no
        // page has to remember. Browsers get the links as they always were.
        URL::formatPathUsing(function (string $path, $route = null): string {
            if ($route === null || ! InApp::sellsRoute((string) $route->getName()) || ! InApp::hidesPlans()) {
                return $path;
            }

            return InApp::planPath($path);
        });
        Event::listen(Logout::class, function (): void {
            $deviceId = request()->hasSession() ? (int) request()->session()->get('app_device_id', 0) : 0;
            if ($deviceId > 0) {
                AppDevice::query()->whereKey($deviceId)->delete();
            }
        });

        // Every Gemini reply's token counts, for what each voice really cost (App\Support\GeminiSpend).
        Http::globalMiddleware(GeminiSpend::middleware());

        // "Sign in with Listen" for AI apps on the MCP server.
        SignInWithListen::configure();

        // No "All" in admin tables: 5,000 audio rows on one page is ~160 MB of
        // HTML and runs PHP out of memory. A saved "All" choice is dropped by
        // Filament because it is no longer an option.
        Table::configureUsing(
            fn (Table $table) => $table->paginationPageOptions([10, 25, 50, 100]),
        );

        if (! $this->app->environment('testing')) {
            TitanMailbox::preferOverBrokenResend();
        }

        RouteServiceProvider::loadCachedRoutesUsing(fn () => $this->loadCachedRoutes());

        if (! $this->app->runningInConsole()) {
            $mainLanguage = strtolower(trim((string) SiteSetting::get('site_main_language', config('app.locale', 'ar'))));
            if (! in_array($mainLanguage, ['ar', 'en'], true)) {
                $mainLanguage = config('app.locale', 'ar');
            }

            config([
                'app.locale' => $mainLanguage,
                'laravellocalization.hideDefaultLocaleInURL' => false,
            ]);

            if (! request()->route()?->parameter('locale')) {
                $this->app->setLocale($mainLanguage);
            }
        }

        // Register policies
        Gate::policy(ClonedVoice::class, ClonedVoicePolicy::class);

        Cashier::useSubscriptionModel(BillingSubscription::class);
        Cashier::useSubscriptionItemModel(BillingSubscriptionItem::class);

        $appUrl = config('app.url');

        if ($appUrl) {
            URL::forceRootUrl($appUrl);

            if (str_starts_with($appUrl, 'https://')) {
                URL::forceScheme('https');
            }
        }

        if ($this->app->runningInConsole() && $appUrl) {
            $configFile = public_path('extensions/chrome-listen-reader/config.js');

            if (file_exists($configFile)) {
                $currentContent = file_get_contents($configFile);
                $apiBase = rtrim($appUrl, '/');
                if (! str_contains($currentContent, "\"apiBase\":\"{$apiBase}\"")
                    && ! str_contains($currentContent, "apiBase: '{$apiBase}'")
                    && ! str_contains($currentContent, "\"apiBase\":\\\"{$apiBase}\\\"")
                ) {
                    // Run once the app has booted: calling Artisan during provider boot builds
                    // the console before bootstrap/app.php registers its commands, which then
                    // silently disappear (plans:expire-discounts, newsletter:work, ...).
                    $this->app->booted(function (): void {
                        if ($this->app->environment('testing')) {
                            return;
                        }

                        try {
                            Artisan::call('listen:sync-extension');
                        } catch (\Throwable $e) {
                            // Never block the command that is actually running.
                        }
                    });
                }
            }
        }
    }
}
