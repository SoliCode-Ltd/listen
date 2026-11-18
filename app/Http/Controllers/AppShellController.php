<?php

namespace App\Http\Controllers;

use App\Models\AppDevice;
use App\Models\User;
use App\Services\Auth\AuthSettingsService;
use App\Services\BlockedUserService;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use App\Support\InApp\AiConsent;
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
 * What the Listen apps (mobile/: Android and iPhone) need from the site:
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

    /** Places the app can start at by name (/app/start?to=voices), and the page each one is. */
    private const START_PLACES = ['voices' => 'samples', 'agents' => 'voice-agents.index'];

    /** Session key: the page a member was on the way to when the iPhone app asked for their agreement. */
    public const CONSENT_INTENDED = 'app_consent.intended';

    private const SIGN_IN_CHALLENGE = 'app_signin.challenge';

    private const CODE_MINUTES = 3;

    /** How long a code still signs the same app in after its first use (a repeated request). */
    private const CODE_REUSE_SECONDS = 60;

    /** How long the app's link into the browser signs the member in. */
    private const WEB_MINUTES = 5;

    public function __construct(private PlanAccessService $plans) {}

    /** The app's first page. */
    public function start(Request $request): RedirectResponse
    {
        $locale = $this->locale($request);
        $user = $request->user();

        // A shortcut on the app's icon names a place ("voices", "agents"): the site knows its
        // address in the member's language. Anything else is the usual first page.
        $place = self::START_PLACES[(string) $request->query('to', '')] ?? null;
        if ($user && $place !== null) {
            return redirect()->to($this->localized($place, $locale));
        }

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
            'renewsOn' => InApp::renewsOn((int) $user->id),
        ]);
    }

    /**
     * The iPhone app's question before a member's first voice: what is sent to the AI
     * provider, to whom, and for what (App\Support\InApp\AiConsent). Later, the same page
     * says when they agreed and lets them take it back.
     */
    public function consent(Request $request): View|RedirectResponse
    {
        // The question belongs to the iPhone app; anywhere else there is nothing to ask.
        if (! InApp::onIphone($request)) {
            return redirect()->to($this->memberHome($request->user(), app()->getLocale()));
        }

        $agreedAt = $request->user()->getAttribute(AiConsent::COLUMN);

        return view('app.consent', [
            'agreedOn' => $agreedAt ? \Illuminate\Support\Carbon::parse($agreedAt)->locale(app()->getLocale())->translatedFormat('j F Y') : null,
        ]);
    }

    public function consentAgree(Request $request): RedirectResponse
    {
        $user = $request->user();
        AiConsent::give($user);

        $to = (string) $request->session()->pull(self::CONSENT_INTENDED, '');
        // Only a page of this site: a path, never an address.
        if (preg_match('#^/(?![/\\\\])[^\s\\\\]*$#', $to) !== 1) {
            $to = '';
        }

        return redirect()->to($to !== '' ? url($to) : $this->memberHome($user, app()->getLocale()));
    }

    public function consentWithdraw(Request $request): RedirectResponse
    {
        AiConsent::withdraw($request->user());

        return redirect()->route('app.consent');
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
        // Which app opened this tab: the iPhone app says so, and its rules then hold here too.
        $request->session()->put(InApp::SIGN_IN_PLATFORM, $request->query('platform') === InApp::PLATFORM_IOS ? InApp::PLATFORM_IOS : InApp::PLATFORM_ANDROID);

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
        // Made only when it is needed: a redirect with an error puts the error in the session at
        // once, so one made in advance would show after a sign-in that worked.
        $failed = fn (): RedirectResponse => redirect()->to($this->localized('login', $locale))->withErrors(['auth' => __('app.signin_failed')]);

        if (preg_match('/^[A-Za-z0-9]{64}$/', $code) !== 1 || preg_match('/^[A-Za-z0-9_-]{43,128}$/', $verifier) !== 1) {
            return $this->signedInAlready($request, $locale) ?? $failed();
        }

        // Some phones ask for this address twice (the web view's own second request for a page it
        // was told to load): the code stays good for a minute after its first use, for the same
        // secret only, so the second ask signs in the same member instead of failing.
        $key = self::codeKey($code);
        $held = Cache::get($key);
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (! is_array($held) || ! hash_equals((string) ($held['challenge'] ?? ''), $expected)) {
            // A wrong secret uses the code up.
            Cache::forget($key);

            return $this->signedInAlready($request, $locale) ?? $failed();
        }
        if (empty($held['used'])) {
            Cache::put($key, $held + ['used' => true], now()->addSeconds(self::CODE_REUSE_SECONDS));
        }

        $user = User::query()->find((int) ($held['user_id'] ?? 0));
        if (! $user) {
            return $failed();
        }
        if ($blocked->isBlocked($user)) {
            return redirect()->to($this->localized('login', $locale))->with('blocked_notice', $blocked->noticePayload($user));
        }
        if ((int) $request->user()?->id === (int) $user->id) {
            return redirect()->to($this->memberHome($user, $locale));
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->to($this->memberHome($user, $locale));
    }

    /**
     * A page that takes a payment or changes a plan, asked for inside the app:
     * it opens in the phone's browser instead, on the same address, so what
     * the member chose (the plan, monthly or yearly) goes with them. A member
     * signed in to the app is signed in there too, with a link that works
     * once and for a few minutes; a visitor meets the site's own sign-in,
     * which already leads on to the page they asked for.
     */
    public function openOnWeb(Request $request, ?string $to = null): Response
    {
        $to ??= '/'.ltrim($request->getRequestUri(), '/');
        $user = $request->user();
        $webUrl = url($to);

        if ($user) {
            $code = Str::random(64);
            Cache::put(self::webKey($code), (int) $user->id, now()->addMinutes(self::WEB_MINUTES));
            $webUrl = url('/app/continue').'?'.http_build_query(['code' => $code, 'to' => $to]);
        }

        return response()->view('app.open-web', [
            'webUrl' => $webUrl,
            'backUrl' => $user ? $this->memberHome($user, app()->getLocale()) : $this->localized('login', $this->locale($request)),
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * "Upgrade" on the app's account page: the billing page, opened in the
     * phone's browser with the member already signed in. Nothing is bought
     * inside the app.
     */
    public function upgrade(Request $request): Response|RedirectResponse
    {
        if (! InApp::fromApp($request)) {
            return redirect()->route('billing.page');
        }
        // The iPhone app never leads to a place to pay (InApp::offersUpgradeOnWeb).
        if (! InApp::offersUpgradeOnWeb($request)) {
            return redirect()->to(InApp::planUrl());
        }

        // The plan the button was for (the demo's, as on the website), when it names one.
        $plan = (string) $request->query('plan', '');
        $query = preg_match('/^[a-z0-9_-]{1,40}$/', $plan) === 1 ? '?'.http_build_query(['plan' => $plan]) : '';

        return $this->openOnWeb($request, '/'.app()->getLocale().'/billing'.$query);
    }

    /**
     * The browser's side of openOnWeb: the member is signed in, when the link
     * still holds, and sent on to the page. A link that has run out simply
     * leads to the page, which asks them to sign in and then goes on.
     */
    public function continueOnWeb(Request $request, BlockedUserService $blocked): RedirectResponse
    {
        $to = (string) $request->query('to', '');
        // Only a page of this site: a path, never an address.
        if (preg_match('#^/(?![/\\\\])[^\s\\\\]*$#', $to) !== 1 || str_starts_with($to, '/app/continue')) {
            $to = '/';
        }
        // The link belongs in a browser; the app itself goes back to where it starts.
        if (InApp::fromApp($request)) {
            return redirect()->to(InApp::homeUrl());
        }

        $code = (string) $request->query('code', '');
        $userId = preg_match('/^[A-Za-z0-9]{64}$/', $code) === 1 ? (int) Cache::pull(self::webKey($code), 0) : 0;
        $user = $userId > 0 ? User::query()->find($userId) : null;

        if ($user && ! $blocked->isBlocked($user) && (int) $request->user()?->id !== (int) $user->id) {
            Auth::login($user, true);
            $request->session()->regenerate();
        }
        // This browser is now on the website itself, not the app's sign-in tab.
        $request->session()->forget([InApp::SIGN_IN_SESSION, InApp::SIGN_IN_PLATFORM]);

        return redirect()->to(url($to));
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
            'platform' => InApp::onIphone($request) ? InApp::PLATFORM_IOS : InApp::PLATFORM_ANDROID,
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

    /**
     * Apple's version of the same: which iPhone app may open the site's links, and which links.
     * The same pages as the Android app opens (mobile/BRIDGE.md). Names no app until the team id is set.
     */
    public function appleAppSiteAssociation(): Response
    {
        $team = strtoupper(trim((string) config('services.apns.team_id', '')));
        $bundle = trim((string) config('services.apns.bundle_id', ''));
        $details = [];

        if (preg_match('/^[A-Z0-9]{10}$/', $team) === 1 && preg_match('/^[A-Za-z0-9.\-]{3,155}$/', $bundle) === 1) {
            $components = [['/' => '/app/start']];
            foreach (['ar', 'en'] as $locale) {
                foreach (['dashboard', 'demo', 'agents', 'dub-my-video'] as $section) {
                    $components[] = ['/' => "/{$locale}/{$section}*"];
                }
                $components[] = ['/' => "/{$locale}/email/verify/*"];
                $components[] = ['/' => "/{$locale}/start"];
            }
            $details[] = ['appIDs' => [$team.'.'.$bundle], 'components' => $components];
        }

        return response()->json(['applinks' => ['details' => $details]], 200, ['Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_SLASHES);
    }

    /** A sign-in that cannot be finished, asked by an app that is signed in: no error, just the studio. */
    private function signedInAlready(Request $request, string $locale): ?RedirectResponse
    {
        return $request->user() ? redirect()->to($this->memberHome($request->user(), $locale)) : null;
    }

    /** The studio for a paying member (or the admin), the free demo for the rest, as after any sign-in. */
    private function memberHome(User $user, string $locale): string
    {
        $plan = $this->plans->resolveAccessPlanForUserId((int) $user->id);
        $paid = $plan !== null && ($plan->isPack() || (int) $plan->price_cents > 0);

        return $this->localized($paid || $user->is_admin ? 'dashboard' : 'demo.page', $locale);
    }

    /** The language asked for; in the app, else the member's last choice, else the phone's (InApp::language); in a browser, the browser's. */
    private function locale(Request $request): string
    {
        $asked = (string) $request->query('locale', '');
        if (in_array($asked, ['ar', 'en'], true)) {
            return $asked;
        }
        if (InApp::fromApp($request)) {
            return InApp::language($request);
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

    private static function webKey(string $code): string
    {
        return 'app-web:'.hash('sha256', $code);
    }

    private static function codeKey(string $code): string
    {
        return 'app-signin:'.hash('sha256', $code);
    }
}
