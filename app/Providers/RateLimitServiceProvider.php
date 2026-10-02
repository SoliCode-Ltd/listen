<?php

namespace App\Providers;

use App\Models\User;
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
