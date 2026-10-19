<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Whether this server may act on real customers by itself: cancel or change
 * Stripe subscriptions and prices, and send reminder or welcome emails from
 * scheduled jobs. Staging runs the live Stripe keys on a copy of live
 * members, so it must never do these (config listen.live_actions).
 */
final class LiveServer
{
    public static function allowsCustomerActions(): bool
    {
        $explicit = config('listen.live_actions');
        if ($explicit !== null && $explicit !== '') {
            return filter_var($explicit, FILTER_VALIDATE_BOOLEAN);
        }

        // The test suite runs these actions against faked Stripe and mail.
        if (app()->runningUnitTests()) {
            return true;
        }

        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $live = strtolower(trim((string) config('listen.live_host', 'listenai.studio')));

        return $live !== '' && ($host === $live || $host === 'www.'.$live);
    }

    /**
     * Notes that an action was left out because this is not the live server.
     *
     * @param  array<string, mixed>  $context
     */
    public static function skipped(string $action, array $context = []): void
    {
        Log::info('Left out on this server (not the live site): '.$action, $context + [
            'app_url' => (string) config('app.url'),
        ]);
    }
}
