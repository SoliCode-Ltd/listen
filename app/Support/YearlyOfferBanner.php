<?php

namespace App\Support;

use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Billing\PlanChanges;
use App\Services\PlanAccessService;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The yearly offer banner: a slim bar above the header of the public pages that
 * announces the yearly discount (Admin → Billing Settings → Yearly offer banner).
 *
 * It is off until the admin turns it on. The discount it shows is the yearly
 * discount itself (Billing Settings → Yearly Billing Discount), so the bar and the
 * prices can never disagree; it hides when that discount is 0, after its last day,
 * inside the app, and for a member who already pays yearly.
 */
final class YearlyOfferBanner
{
    public const ENABLED = 'offer_banner_enabled';

    public const ENDS_ON = 'offer_banner_ends_on';

    public const LABEL_EN = 'offer_banner_label_en';

    public const LABEL_AR = 'offer_banner_label_ar';

    public const BUTTON_EN = 'offer_banner_button_en';

    public const BUTTON_AR = 'offer_banner_button_ar';

    public const GROUP = 'billing';

    /**
     * The public pages the bar appears on, by route name (a name ending in "*" covers the
     * routes that start with it). Only these: sign-in, sign-up, password and email pages, the
     * studio and the account pages never show it.
     */
    public const ROUTES = [
        'home', 'pricing', 'samples', 'demo.page', 'agents-landing', 'browsers-extension.page',
        'contact', 'developers', 'seo.*',
    ];

    public static function enabled(): bool
    {
        return (string) SiteSetting::get(self::ENABLED, '0') === '1';
    }

    /** The offer's last day, or null when it has none. */
    public static function endsOn(): ?CarbonImmutable
    {
        $value = trim((string) SiteSetting::get(self::ENDS_ON, ''));
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, (string) config('app.timezone', 'UTC'))->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /** Whether the offer's last day has passed: it runs to the end of that day. */
    public static function ended(?CarbonImmutable $now = null): bool
    {
        $endsOn = self::endsOn();

        return $endsOn !== null && ($now ?? CarbonImmutable::now())->greaterThan($endsOn->endOfDay());
    }

    public static function percent(): int
    {
        return SiteSetting::yearlyDiscountPercent();
    }

    /** Whether the bar shows on the site at all (on, a discount to show, not past its last day). */
    public static function live(): bool
    {
        return self::enabled() && self::percent() > 0 && ! self::ended();
    }

    public static function showsOnRoute(?string $route): bool
    {
        $route = (string) $route;
        foreach (self::ROUTES as $pattern) {
            if ($pattern === $route || (str_ends_with($pattern, '*') && str_starts_with($route, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the bar says on this page, or null when it is not shown here.
     *
     * @return array{percent:int, above:?int, below:?int, label:string, before:string, after:string, ends:?string, button:string, url:string, aria:string}|null
     */
    public static function forPage(?string $route, ?User $user, string $locale): ?array
    {
        if (! self::live() || ! self::showsOnRoute($route) || self::paysYearly($user)) {
            return null;
        }

        $arabic = $locale === 'ar';
        $percent = self::percent();
        $endsOn = self::endsOn();
        $custom = fn (string $key): string => trim((string) SiteSetting::get($key, ''));
        $label = $custom($arabic ? self::LABEL_AR : self::LABEL_EN);
        $button = $custom($arabic ? self::BUTTON_AR : self::BUTTON_EN);

        return [
            'percent' => $percent,
            // The numbers around the chosen one on the picker: ten either side, while they make sense.
            'above' => $percent - 10 >= 5 ? $percent - 10 : null,
            'below' => $percent + 10 <= 90 ? $percent + 10 : null,
            'label' => $label !== '' ? $label : (string) __('ui.offer_banner.label'),
            'before' => (string) __('ui.offer_banner.before'),
            'after' => (string) __('ui.offer_banner.after'),
            'ends' => $endsOn ? (string) __('ui.offer_banner.ends', ['date' => $endsOn->locale($arabic ? 'ar' : 'en')->translatedFormat('j F')]) : null,
            'button' => $button !== '' ? $button : (string) __('ui.offer_banner.button'),
            'url' => route('pricing', ['billing' => 'yearly']),
            'aria' => (string) __('ui.offer_banner.aria', ['percent' => $percent]),
        ];
    }

    /** A member who already pays yearly has nothing to gain from the bar. */
    private static function paysYearly(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        try {
            $plan = app(PlanAccessService::class)->resolveActivePlanForUserId((int) $user->id);
            if (! $plan || (int) $plan->price_cents <= 0 || $plan->isPack()) {
                return false;
            }

            return app(PlanChanges::class)->currentCycle($user) === 'yearly';
        } catch (Throwable) {
            return false;
        }
    }
}
