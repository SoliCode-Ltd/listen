<?php

namespace App\Http\Controllers;

use App\Models\AppDevice;
use App\Models\User;
use App\Services\Auth\AuthSettingsService;
use App\Services\BlockedUserService;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use App\Support\InApp\InApp;
use App\Support\UsageSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the Listen Android app (mobile/) needs from the site:
 *
 * - where it starts (the studio for a member, sign-in for a guest);
 * - the neutral plan page that stands in for every purchase page;
 * - signing in through the phone's browser (Google refuses to sign people in
 *   inside an app's web view), handed back to the app with a one-time code;
 * - the phone's messaging token, for "your voice is ready";
 * - the file that lets links to the site open in the app.
 */
class AppShellController extends Controller
{
    /** The scheme the app answers to (mobile/android/app/src/main/AndroidManifest.xml). */
    public const APP_SCHEME = 'studio.listenai.app';

    private const SIGN_IN_CHALLENGE = 'app_signin.challenge';

    private const CODE_MINUTES = 3;

    public function __construct(private PlanAccessService $plans) {}

    /** The app's first page. */
    public function start(Request $request): RedirectResponse
    {
        $locale = $this->locale($request);
        $user = $request->user();

        return redirect()->to($user ? $this->memberHome($user, $locale) : $this->localized('login', $locale));
    }

    /** In the app, every purchase page is this one: the plan as it is, and nothing to buy. */
    public function plan(Request $request): View|RedirectResponse
    {
        // A link made inside the app that a browser opens (from an email, say): the real page.
        if (! InApp::active($request)) {
            return redirect()->route('billing.page', $request->only(['plan', 'billing_cycle']));
        }

        $user = $request->user();
        $plan = $this->plans->resolveAccessPlanForUserId((int) $user->id);
        $quotaPlan = $this->plans->resolveQuotaPlanForUserId((int) $user->id, $plan);
        $usage = UsageSnapshot::for((int) $user->id, $quotaPlan, CreditPeriod::counter((int) $user->id));

        return view('app.plan', [
            'plan' => $plan,
            'usage' => $usage,
            'studioUrl' => $this->memberHome($user, app()->getLocale()),
        ]);
    }

    /**
     * The browser tab the app opens to sign a member in. It carries the app's
     * challenge (the hash of a secret only that app holds), so the code this
     * ends with is of use to that app alone.
     */
    public function signInStart(Request $request, AuthSettingsService $authSettings): RedirectResponse
    {
        $challenge = (string) $request->query('challenge', '');
        abort_unless(preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge) === 1, 400);

        $locale = $this->locale($request);
        $request->session()->put(self::SIGN_IN_CHALLENGE, $challenge);
        $request->session()->put(InApp::SIGN_IN_SESSION, now()->getTimestamp());

        $done = $this->localized('app.signin.done', $locale);
        if ($request->user()) {
            return redirect()->to($done);
        }

        // After any way of signing in, the member lands on the hand-back page.
        $request->session()->put('url.intended', $done);

        $google = $authSettings->googleEnabled()
            && filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));

        return redirect()->to($this->localized($google ? 'auth.social.redirect' : 'login', $locale));
    }

    /** Signed in, in the browser: hand the member back to the app. */
    public function signInDone(Request $request): View|RedirectResponse
    {
        $challenge = (string) $request->session()->get(self::SIGN_IN_CHALLENGE, '');
        if ($challenge === '' || ! InApp::active($request)) {
            return redirect()->route('dashboard');
        }

        $code = Str::random(64);
        Cache::put(self::codeKey($code), [
            'user_id' => (int) $request->user()->id,
            'challenge' => $challenge,
        ], now()->addMinutes(self::CODE_MINUTES));

        return view('app.signin-done', [
            'appUrl' => self::APP_SCHEME.'://signin?code='.$code,
            'member' => $request->user(),
        ]);
    }

    /**
     * The app, back from the browser with the code and its secret: the same
     * member is signed in inside the app. A code works once, for a few
     * minutes, and only with the secret whose hash started the sign-in.
     */
    public function signInComplete(Request $request, BlockedUserService $blocked): RedirectResponse
    {
        abort_unless(InApp::fromApp($request), 403);

        $locale = $this->locale($request);
        $code = (string) $request->query('code', '');
        $verifier = (string) $request->query('verifier', '');
        $failed = redirect()->to($this->localized('login', $locale))->withErrors(['auth' => __('app.signin_failed')]);

        if (preg_match('/^[A-Za-z0-9]{64}$/', $code) !== 1 || preg_match('/^[A-Za-z0-9_-]{43,128}$/', $verifier) !== 1) {
            return $failed;
        }

        $held = Cache::pull(self::codeKey($code));
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (! is_array($held) || ! hash_equals((string) ($held['challenge'] ?? ''), $expected)) {
            return $failed;
        }

        $user = User::query()->find((int) ($held['user_id'] ?? 0));
        if (! $user) {
            return $failed;
        }
        if ($blocked->isBlocked($user)) {
            return redirect()->to($this->localized('login', $locale))->with('blocked_notice', $blocked->noticePayload($user));
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->to($this->memberHome($user, $locale));
    }

    /** The phone's messaging token, kept for the member who is signed in on it. */
    public function registerDevice(Request $request): JsonResponse
    {
        abort_unless(InApp::fromApp($request), 403);

        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:4096', 'regex:/^[A-Za-z0-9_\-:.]+$/'],
            'locale' => ['nullable', 'in:ar,en'],
        ]);

        $user = $request->user();
        $hash = AppDevice::hashOf($data['token']);
        $device = AppDevice::query()->updateOrCreate(['token_hash' => $hash], [
            'user_id' => $user->id,
            'platform' => 'android',
            'token' => $data['token'],
            'locale' => $data['locale'] ?? (app()->getLocale() === 'en' ? 'en' : 'ar'),
            'app_version' => InApp::version($request),
            'last_seen_at' => now(),
        ]);
        // Signing out on this phone forgets it (AppServiceProvider).
        $request->session()->put('app_device_id', $device->id);

        $keep = AppDevice::query()->where('user_id', $user->id)->latest('last_seen_at')->latest('id')->limit(AppDevice::PER_MEMBER)->pluck('id');
        AppDevice::query()->where('user_id', $user->id)->whereNotIn('id', $keep)->delete();

        return response()->json(['ok' => true]);
    }

    /** Notifications switched off on the phone: its token is forgotten. */
    public function forgetDevice(Request $request): JsonResponse
    {
        abort_unless(InApp::fromApp($request), 403);

        $data = $request->validate(['token' => ['required', 'string', 'max:4096']]);
        AppDevice::query()->where('user_id', $request->user()->id)->where('token_hash', AppDevice::hashOf($data['token']))->delete();
        $request->session()->forget('app_device_id');

        return response()->json(['ok' => true]);
    }

    /** Digital Asset Links: which Android app may open the site's links. Empty until the fingerprints are set. */
    public function assetLinks(): Response
    {
        $fingerprints = collect(explode(',', (string) config('services.android_app.cert_fingerprints', '')))
            ->map(fn (string $f): string => strtoupper(trim($f)))
            ->filter(fn (string $f): bool => preg_match('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $f) === 1)
            ->unique()->values();

        $statements = $fingerprints->isEmpty() ? [] : [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => (string) config('services.android_app.package', self::APP_SCHEME),
                'sha256_cert_fingerprints' => $fingerprints->all(),
            ],
        ]];

        return response()->json($statements, 200, ['Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_SLASHES);
    }

    /** The studio for a paying member (or the admin), the free demo for the rest, as after any sign-in. */
    private function memberHome(User $user, string $locale): string
    {
        $plan = $this->plans->resolveAccessPlanForUserId((int) $user->id);
        $paid = $plan !== null && ($plan->isPack() || (int) $plan->price_cents > 0);

        return $this->localized($paid || $user->is_admin ? 'dashboard' : 'demo.page', $locale);
    }

    /** The language asked for, else the one this browser used last, else the phone's. */
    private function locale(Request $request): string
    {
        $asked = (string) $request->query('locale', '');
        if (in_array($asked, ['ar', 'en'], true)) {
            return $asked;
        }
        $session = $request->hasSession() ? (string) $request->session()->get('locale', '') : '';
        if (in_array($session, ['ar', 'en'], true)) {
            return $session;
        }

        return $request->getPreferredLanguage(['ar', 'en']) ?: 'ar';
    }

    private function localized(string $route, string $locale): string
    {
        return (string) LaravelLocalization::getLocalizedURL($locale, route($route, [], true), [], true);
    }

    private static function codeKey(string $code): string
    {
        return 'app-signin:'.hash('sha256', $code);
    }
}
