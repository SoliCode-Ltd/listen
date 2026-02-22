<?php

namespace App\Providers;

use App\Models\BillingSubscription;
use App\Models\BillingSubscriptionItem;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Cashier::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
            $apiBase = rtrim($appUrl, '/');
            $configFile = public_path('extensions/chrome-listen-reader/config.js');

            if (file_exists($configFile)) {
                $currentContent = file_get_contents($configFile);
                if (! str_contains($currentContent, "apiBase: '{$apiBase}'")) {
                    try {
                        \Illuminate\Support\Facades\Artisan::call('listen:sync-extension');
                    } catch (\Throwable $e) {
                        // Suppress error during early boot.
                    }
                }
            }
        }
    }
}
