<?php

namespace App\Services\Geo;

use App\Services\LocalizedPricingService;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Saves a member's country the first time they sign in without one, from the
 * same request headers and IP lookup that pick their local prices. Only the
 * two-letter country is kept. A failure never blocks the sign-in.
 */
class UserCountryRecorder
{
    /** Sources that reflect where the request really came from. */
    private const TRUSTED = ['header:', 'ip_lookup'];

    public function __construct(private readonly LocalizedPricingService $pricing) {}

    public function handle(Login $event): void
    {
        $user = $event->user;
        if (! $user || filled($user->country_code ?? null) || ! isset($user->id)) {
            return;
        }

        try {
            $context = $this->pricing->resolveCountryContext(request());
            $source = (string) ($context['source'] ?? '');
            $trusted = collect(self::TRUSTED)->contains(fn (string $prefix) => str_starts_with($source, $prefix));
            $country = strtoupper((string) ($context['country_code'] ?? ''));
            if (! $trusted || strlen($country) !== 2) {
                return;
            }

            // A plain update: no model events, and updated_at is left alone.
            DB::table('users')->where('id', $user->id)->whereNull('country_code')->update(['country_code' => $country]);
            $user->country_code = $country;
        } catch (\Throwable $e) {
            Log::info('Could not record member country.', ['user_id' => $user->id, 'reason' => $e->getMessage()]);
        }
    }
}
