<?php

namespace App\Support\InApp;

use App\Support\CreditPeriod;
use Illuminate\Http\Request;

/**
 * "In-app mode": the site as the Listen Android app shows it (mobile/).
 *
 * Google Play does not allow an app to sell digital plans with anything but
 * its own billing, or to send people to another way of paying. So inside the
 * app the site is sign-in-and-use only: no price, plan, pack, upgrade,
 * payment method or checkout, and no link to one. Plan limits stay as they are.
 *
 * The app's web view adds "ListenApp/<version>" to its user agent
 * (mobile/capacitor.config.ts); no browser sends that. The one other case is
 * the browser tab the app opens to sign a member in (AppSignIn): it is marked
 * in that browser's session for a few minutes, so the sign-in pages there show
 * no prices either.
 */
class InApp
{
    /** The user-agent token the app sends, followed by its version. */
    public const UA_TOKEN = 'ListenApp';

    /** Session key: when the app's sign-in tab was opened (a timestamp). */
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
     */
    public static function plansMode(): string
    {
        return config('services.android_app.plans') === self::PLANS_ON_WEB ? self::PLANS_ON_WEB : self::PLANS_HIDDEN;
    }

    /** In the app, and the app shows nothing about plans. */
    public static function hidesPlans(?Request $request = null): bool
    {
        return self::plansMode() === self::PLANS_HIDDEN && self::active($request);
    }

    /** In the app's own web view, where plans are shown and bought in the browser. */
    public static function buysOnWeb(?Request $request = null): bool
    {
        return self::plansMode() === self::PLANS_ON_WEB && self::fromApp($request);
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
        $renews = $userId ? self::renewsOn($userId) : null;

        return $renews ? __('app.no_credits_until', ['date' => $renews]) : __('app.no_credits');
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
        $locale = app()->getLocale();

        return url('/app/start').(in_array($locale, ['ar', 'en'], true) ? '?locale='.$locale : '');
    }

    /** The neutral page that stands in for every purchase page. */
    public static function planUrl(): string
    {
        return route('app.plan');
    }
}
