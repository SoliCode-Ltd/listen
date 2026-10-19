<?php

namespace App\Providers;

use App\Models\ApiKey;
use App\Models\User;
use App\Support\Api\ApiPlanAccess;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Named rate limiters for the public entry points that had none: sign-in and
 * sign-up (password guessing, bot signups), the contact form (inbox flooding)
 * and the browser-extension API (key guessing, runaway clients).
 *
 * Limits are generous for real people; a blocked request gets the same kind of
 * response the page already handles (form error or JSON message), not a bare 429 page.
 */
class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => [
            // Guessing one account's password from many places.
            Limit::perMinute(10)->by('login-email:'.strtolower(trim((string) $request->input('email'))))
                ->response($this->formResponse()),
            // One place trying many accounts.
            Limit::perMinute(30)->by('login-ip:'.$request->ip())->response($this->formResponse()),
        ]);

        RateLimiter::for('register', fn (Request $request) => [
            Limit::perMinute(5)->by('register-ip:'.$request->ip())->response($this->formResponse()),
            Limit::perHour(30)->by('register-ip-hour:'.$request->ip())->response($this->formResponse()),
        ]);

        // Phone and Firebase/Google sign-in endpoints (JSON).
        RateLimiter::for('auth-bridge', fn (Request $request) => [
            Limit::perMinute(15)->by('auth-bridge:'.$request->ip())->response($this->formResponse()),
        ]);

        RateLimiter::for('contact', fn (Request $request) => [
            Limit::perMinutes(10, 5)->by('contact:'.$request->ip())->response($this->formResponse()),
            Limit::perDay(30)->by('contact-day:'.$request->ip())->response($this->formResponse()),
        ]);

        // Browser extension API: keyed by the API key's hash when present, else the IP.
        // Generous enough for polling job status from several tabs.
        RateLimiter::for('extension-api', function (Request $request) {
            $key = $request->header('X-Listen-Key') ?? $request->header('X-API-Key') ?? $request->input('api_key');
            $by = is_string($key) && trim($key) !== ''
                ? 'ext-key:'.User::hashApiKey(trim($key))
                : 'ext-ip:'.$request->ip();

            return [
                Limit::perMinute(240)->by($by),
                Limit::perMinute(600)->by('ext-ip-all:'.$request->ip()),
            ];
        });

        // The studio's Generate (dashboard.queue and dashboard.generate together): far
        // more than a person clicks in a minute, so it only stops scripts and runaway
        // retries from piling up generations. The page counts down from the seconds
        // in the message, as for any refusal it shows.
        RateLimiter::for('studio-generate', fn (Request $request) => [
            Limit::perMinute(20)->by('studio-generate:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
                ->response(function (Request $request, array $headers) {
                    $message = __('ui.backend.generate_too_many_requests', ['seconds' => (int) ($headers['Retry-After'] ?? 60)]);

                    return $request->expectsJson()
                        ? response()->json(['ok' => false, 'message' => $message], 429, $headers)
                        : redirect()->route('dashboard')->with('error', $message);
                }),
        ]);

        // Developer API (/v1): for the member's whole account (all their keys
        // together, so more keys never mean more requests), at their plan's rate.
        // Generations and everything else (status checks, voices, history) have
        // separate allowances, so polling never uses up the generations. Runs
        // after the key is checked, so api_key and api_plan are on the request.
        RateLimiter::for('public-api', function (Request $request) {
            $apiKey = $request->attributes->get('api_key');
            if (! $apiKey instanceof ApiKey) {
                return Limit::perMinute(60)->by('v1-ip:'.$request->ip());
            }

            $plan = $request->attributes->get('api_plan');

            return in_array($request->route()?->getName(), ['v1.tts', 'v1.tts.async'], true)
                ? Limit::perMinute(ApiPlanAccess::requestsPerMinute($plan))->by('v1-generate:'.$apiKey->user_id)
                : Limit::perMinute(ApiPlanAccess::readsPerMinute($plan))->by('v1-read:'.$apiKey->user_id);
        });
    }

    private function formResponse(): \Closure
    {
        return function (Request $request, array $headers) {
            $seconds = (int) ($headers['Retry-After'] ?? 60);
            $message = __('ui.backend.auth_too_many_attempts', ['seconds' => $seconds]);

            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $message], 429, $headers);
            }

            return back()
                ->withInput($request->except(['password', 'password_confirmation', '_token']))
                ->withErrors(['email' => $message, 'auth' => $message]);
        };
    }
}
