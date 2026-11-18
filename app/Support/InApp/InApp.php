<?php

namespace App\Support\InApp;

use App\Models\User;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use App\Support\UsageSnapshot;
use Illuminate\Http\Request;

/**
 * "In-app mode": the site as the Listen apps show it (mobile/): the Android
 * app and the iPhone app.
 *
 * Neither store allows an app to sell digital plans with anything but its own
 * billing, or to send people to another way of paying. So inside the app the
 * site is sign-in-and-use only: no price, plan, pack, upgrade, payment method
 * or checkout, and no link to one. Plan limits stay as they are.
 *
 * The app's web view adds "ListenApp/<version> (Android)" or
 * "ListenApp/<version> (iOS)" to its user agent (mobile/capacitor.config.json);
 * no browser sends that. The one other case is the browser tab the app opens
 * to sign a member in (AppSignIn): it is marked in that browser's session for
 * a few minutes, so the sign-in pages there show no prices either.
 *
 * The iPhone app is stricter than the Android one (mobile/ios/APPLE-RULES.md):
 * plans are always hidden there, whatever ANDROID_APP_PLANS says, and nothing
 * leads to the billing page, not even through the browser.
 */
class InApp
{
    /** The user-agent token the app sends, followed by its version. */
    public const UA_TOKEN = 'ListenApp';

    /** Session key: when the app's sign-in tab was opened (a timestamp). */
    /**
     * The app's own mark, kept in its cookie jar from its first page on. The phone's browser has
     * another jar, so only the app carries it: a request the app makes without its name (a page
     * fetched ahead of a tap, a download) is still known to be the app's.
     */
    public const COOKIE = 'listen_app';

    /** What that cookie holds: "1" in the Android app (as it always did), "ios" in the iPhone app. */
    public const COOKIE_ANDROID = '1';

    public const COOKIE_IOS = 'ios';

    public const PLATFORM_ANDROID = 'android';

    public const PLATFORM_IOS = 'ios';

    /** Session key: which app opened the sign-in tab ("ios" or "android"). */
    public const SIGN_IN_PLATFORM = 'app_signin.platform';

    /** The language the member last used in the app, kept past the session. Until they choose, the app follows the phone. */
    public const LANGUAGE_COOKIE = 'listen_app_lang';

    public const DEFAULT_LANGUAGE = 'en';

    public const SIGN_IN_SESSION = 'app_signin.started_at';

    /** How long the sign-in tab counts as the app. */
    public const SIGN_IN_MINUTES = 15;

    /** The purchase pages: asked for in the app, they show the neutral plan page instead. */
    public const PURCHASE_PAGES = [
        'pricing', 'trial.start', 'pack.start', 'manage-subscription.page',
        'billing.page', 'billing.pack', 'billing.manual.instructions',
        'billing.invoice.view', 'billing.invoice.download',
    ];

    /** Routes under billing.* the app may still call (they sell nothing). */
    public const BILLING_ALLOWED = ['billing.sync'];

    /** Marketing pages, which sell: in the app they lead to the studio or to sign-in. */
    public const MARKETING_PAGES = [
        'home', 'seo.*', 'agents-landing', 'browsers-extension.page', 'developers',
    ];

    /** Plans in the app: shown as on the website, and bought in the phone's browser. */
    public const PLANS_ON_WEB = 'web';

    /** Plans in the app: nothing about them is shown at all. */
    public const PLANS_HIDDEN = 'hidden';

    /**
     * How the app treats plans (ANDROID_APP_PLANS in .env):
     *
     * - "hidden" (the default, and anything that is not "web"): no price, plan
     *   or upgrade is shown in the app at all. This is what Google Play's
     *   payment rules ask for in most countries.
     * - "web": a member sees prices and plans as on the website, and choosing
     *   one opens the phone's browser, already signed in, on the billing page
     *   with what they chose. Nothing is ever paid inside the app.
     *
     * Changing it needs no new app version.
     *
     * The iPhone app is always "hidden": Apple does not let an app lead people
     * to pay outside it, and an app that changes what it does after review is
     * removed from the store. There is no setting for it on purpose.
     */
    public static function plansMode(?Request $request = null): string
    {
        if (self::onIphone($request)) {
            return self::PLANS_HIDDEN;
        }

        return config('services.android_app.plans') === self::PLANS_ON_WEB ? self::PLANS_ON_WEB : self::PLANS_HIDDEN;
    }

    /** In the app, and the app shows nothing about plans. */
    public static function hidesPlans(?Request $request = null): bool
    {
        return self::plansMode($request) === self::PLANS_HIDDEN && self::active($request);
    }

    /** In the app's own web view, where plans are shown and bought in the browser. */
    public static function buysOnWeb(?Request $request = null): bool
    {
        return self::plansMode($request) === self::PLANS_ON_WEB && self::fromApp($request);
    }

    /**
     * Which app this is: "ios", "android", or null in a browser. The app says so
     * in its name; a request without the name is known by the app's cookie, and
     * the sign-in tab by what the app said when it opened it.
     */
    public static function platform(?Request $request = null): ?string
    {
        $request ??= request();

        if (preg_match('/(?:^|[\s;(])'.self::UA_TOKEN.'\/\d[\w.\-]*(?: \((iOS|Android)\))?/', (string) $request->userAgent(), $m) === 1) {
            return ($m[1] ?? '') === 'iOS' ? self::PLATFORM_IOS : self::PLATFORM_ANDROID;
        }

        $cookie = $request->cookies->get(self::COOKIE);
        if ($cookie === self::COOKIE_IOS) {
            return self::PLATFORM_IOS;
        }
        if ($cookie === self::COOKIE_ANDROID) {
            return self::PLATFORM_ANDROID;
        }

        if (self::signInTab($request)) {
            return $request->session()->get(self::SIGN_IN_PLATFORM) === self::PLATFORM_IOS ? self::PLATFORM_IOS : self::PLATFORM_ANDROID;
        }

        return null;
    }

    /** The iPhone app (its web view, or the sign-in sheet it opened). */
    public static function onIphone(?Request $request = null): bool
    {
        return self::platform($request) === self::PLATFORM_IOS;
    }

    /**
     * "Upgrade" on the app's account page, which opens the billing page in the
     * phone's browser: on Android only. On iPhone it would be a call to pay
     * outside the app, which Apple does not allow.
     */
    public static function offersUpgradeOnWeb(?Request $request = null): bool
    {
        return self::fromApp($request) && ! self::onIphone($request);
    }

    /**
     * "Continue with Google" in the app. On iPhone, Apple asks for its own
     * sign-in beside any other company's (guideline 4.8), so there the two are
     * offered together (offersAppleSignIn) or, with IOS_APP_SOCIAL_SIGN_IN off,
     * not at all: the iPhone app then signs in with email only.
     */
    public static function offersSocialSignIn(?Request $request = null): bool
    {
        return self::fromApp($request) && (! self::onIphone($request) || (bool) config('services.ios_app.social_sign_in', true));
    }

    /** "Sign in with Apple": in the iPhone app's own web view only, where the phone can do it. */
    public static function offersAppleSignIn(?Request $request = null): bool
    {
        return self::onIphone($request) && self::fromApp($request) && (bool) config('services.ios_app.social_sign_in', true);
    }

    /** Pages and requests that take a payment or change a plan: never served inside the app. */
    public static function paysRoute(string $name): bool
    {
        return $name !== 'pricing' && self::sellsRoute($name);
    }

    public static function active(?Request $request = null): bool
    {
        $request ??= request();

        $known = $request->attributes->get('in_app');
        if (is_bool($known)) {
            return $known;
        }

        $active = self::fromApp($request) || self::signInTab($request);
        // Before the session has started the sign-in tab cannot be known yet: ask again later.
        if ($active || $request->hasSession()) {
            $request->attributes->set('in_app', $active);
        }

        return $active;
    }

    /** The request comes from the app's own web view. */
    public static function fromApp(?Request $request = null): bool
    {
        $request ??= request();

        return self::namedApp($request) || in_array($request->cookies->get(self::COOKIE), [self::COOKIE_ANDROID, self::COOKIE_IOS], true);
    }

    /** The request says it is the app's, in its own name ("ListenApp/1.0.0"). */
    public static function namedApp(?Request $request = null): bool
    {
        $request ??= request();

        return preg_match('/(?:^|[\s;(])'.self::UA_TOKEN.'\/\d[\w.\-]*/', (string) $request->userAgent()) === 1;
    }

    /** The app's version, as its user agent gives it ("1.0.0"), or null outside the app. */
    public static function version(?Request $request = null): ?string
    {
        $request ??= request();

        return preg_match('/(?:^|[\s;(])'.self::UA_TOKEN.'\/(\d[\w.\-]{0,20})/', (string) $request->userAgent(), $m) === 1 ? $m[1] : null;
    }

    private static function signInTab(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }
        $startedAt = (int) $request->session()->get(self::SIGN_IN_SESSION, 0);

        return $startedAt > 0 && $startedAt > now()->subMinutes(self::SIGN_IN_MINUTES)->getTimestamp();
    }

    /** Whether a route (by name) is a purchase page or a purchase request. */
    public static function sellsRoute(string $name): bool
    {
        if ($name === '') {
            return false;
        }
        if (in_array($name, self::PURCHASE_PAGES, true)) {
            return true;
        }

        return str_starts_with($name, 'billing.') && ! in_array($name, self::BILLING_ALLOWED, true);
    }

    /** The plan page's path, in the language of the purchase page's own path ("/ar/billing" → "/ar/app/plan"). */
    public static function planPath(string $purchasePath): string
    {
        $locale = preg_match('#^/?(ar|en)(?=/|$)#', $purchasePath, $m) === 1 ? '/'.$m[1] : '';

        return $locale.'/app/plan';
    }

    /**
     * What a member out of credits reads in the app: that they are out, and when
     * credits come back. Nothing about buying.
     */
    public static function noCreditsLine(?int $userId): string
    {
        // A plan that gives no credits each month (the free plan, when the admin has it so): nothing
        // was used up and nothing comes back, so it says so, and the demo is the way to try.
        if ($userId && self::planGivesNoCredits($userId)) {
            return __('app.no_credits_on_plan');
        }
        $renews = $userId ? self::renewsOn($userId) : null;

        return $renews ? __('app.no_credits_until', ['date' => $renews]) : __('app.no_credits');
    }

    /**
     * Where the app's Studio tab leads: the demo for a member on the free plan with nothing to
     * spend in the studio (the demo is their free try, as after signing in), else the studio.
     */
    public static function studioUrl(?User $user): string
    {
        return $user && self::studioIsDemo($user) ? route('demo.page') : route('dashboard');
    }

    /** A member on the free plan (who may use the demo: EnsureFreeDemoAccess) with no credits to spend in the studio. */
    public static function studioIsDemo(User $user): bool
    {
        if ($user->is_admin) {
            return false;
        }

        return rescue(function () use ($user): bool {
            $plans = app(PlanAccessService::class);
            $active = $plans->resolveActivePlanForUserId((int) $user->id);
            if (strtolower(trim((string) ($active?->slug ?? 'free'))) !== 'free') {
                return false;
            }
            $snapshot = UsageSnapshot::for((int) $user->id, $plans->resolveQuotaPlanForUserId((int) $user->id), CreditPeriod::counter((int) $user->id));

            return (int) ($snapshot['remaining_chars'] ?? 0) <= 0;
        }, false, false);
    }

    /** The member's plan gives no credits each month and none are left from a gift or pack. */
    public static function planGivesNoCredits(int $userId): bool
    {
        return rescue(function () use ($userId): bool {
            $plans = app(PlanAccessService::class);
            $snapshot = UsageSnapshot::for($userId, $plans->resolveQuotaPlanForUserId($userId), CreditPeriod::counter($userId));

            return ! empty($snapshot['no_free_chars']) && (int) ($snapshot['plan_chars'] ?? 0) <= 0 && (int) ($snapshot['remaining_chars'] ?? 0) <= 0;
        }, false, false);
    }

    /** The day the member's credits are given again ("1 December"), or null when that is not known. */
    public static function renewsOn(int $userId): ?string
    {
        return rescue(function () use ($userId): ?string {
            $period = CreditPeriod::current($userId);
            $end = $period['plan_ends_at'] ?? null ? null : ($period['ends_at'] ?? null);

            return $end && $end->isFuture() ? $end->copy()->locale(app()->getLocale())->translatedFormat('j F') : null;
        }, null, false);
    }

    /** Where the app starts and where its dead ends lead: the studio for a member, sign-in for a guest. */
    public static function homeUrl(): string
    {
        // In the language of the page the member is on: an Arabic page never leads to an English studio.
        $locale = self::pageLanguage();

        return url('/app/start').($locale ? '?locale='.$locale : '');
    }

    /** The language in the page's own address ("/ar/dashboard" → "ar"), or null when it names none. */
    public static function pageLanguage(?Request $request = null): ?string
    {
        $first = strtolower((string) ($request ?? request())->segment(1));

        return in_array($first, ['ar', 'en'], true) ? $first : null;
    }

    /**
     * The app's language when a page names none: the member's last choice, else the phone's own
     * language (Arabic or English), else English.
     */
    public static function language(?Request $request = null): string
    {
        $request ??= request();
        $kept = (string) $request->cookies->get(self::LANGUAGE_COOKIE, '');
        if (in_array($kept, ['ar', 'en'], true)) {
            return $kept;
        }
        // The phone's first language only: "fr, ar" is a French phone, and gets English.
        $phone = strtolower(substr((string) ($request->getLanguages()[0] ?? ''), 0, 2));

        return in_array($phone, ['ar', 'en'], true) ? $phone : self::DEFAULT_LANGUAGE;
    }

    /** The neutral page that stands in for every purchase page. */
    public static function planUrl(): string
    {
        return route('app.plan');
    }
}
