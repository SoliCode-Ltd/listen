<?php

namespace App\Support;

use App\Models\Plan;
use Carbon\CarbonInterface;

/**
 * What a discount really means for the buyer, said the same way on the
 * pricing page and at checkout: how long it lasts and what they pay after it.
 */
class DiscountTerms
{
    /**
     * The offer itself, e.g. "30% off your first month".
     *
     * @param  int  $percent  the discount shown for this cycle
     */
    public static function offer(Plan $plan, string $cycle, int $percent): string
    {
        if ($plan->discountDuration() === Plan::DISCOUNT_FOREVER) {
            return __('ui.pricing.discount_offer_forever', ['percent' => $percent]);
        }

        // A yearly plan is paid once a year, so any discount shorter than a year covers its first payment.
        if ($cycle === 'yearly') {
            return __('ui.pricing.discount_offer_first_year', ['percent' => $percent]);
        }

        if ($plan->discountDuration() === Plan::DISCOUNT_FIRST_MONTHS) {
            $months = (int) $plan->discountMonths();

            return trans_choice('ui.pricing.discount_offer_first_months', $months, ['percent' => $percent, 'months' => $months]);
        }

        return __('ui.pricing.discount_offer_first_month', ['percent' => $percent]);
    }

    /**
     * The regular price that follows, e.g. "Then $9.00/month", or null when
     * the discount never ends.
     *
     * @param  string  $fullPrice  the price after the discount, formatted, for one cycle
     */
    public static function then(Plan $plan, string $cycle, string $fullPrice): ?string
    {
        if ($plan->discountDuration() === Plan::DISCOUNT_FOREVER) {
            return null;
        }

        $key = $cycle === 'yearly' ? 'ui.pricing.discount_then_year' : 'ui.pricing.discount_then_month';

        return __($key, ['price' => self::isolate($fullPrice)]);
    }

    /**
     * Both in one sentence, e.g. "30% off your first month, then $9.00/month".
     */
    public static function line(Plan $plan, string $cycle, int $percent, string $fullPrice): string
    {
        $offer = self::offer($plan, $cycle, $percent);
        $then = self::then($plan, $cycle, $fullPrice);

        return $then === null ? $offer : $offer.__('ui.pricing.discount_terms_join').mb_strtolower(mb_substr($then, 0, 1)).mb_substr($then, 1);
    }

    /**
     * The day the member first pays the full price when buying now, or null
     * when the discount never ends.
     */
    public static function fullPriceFrom(Plan $plan, string $cycle, ?CarbonInterface $from = null): ?CarbonInterface
    {
        if ($plan->discountDuration() === Plan::DISCOUNT_FOREVER) {
            return null;
        }

        $from = $from ? $from->copy() : now();

        if ($cycle === 'yearly') {
            return $from->addYearNoOverflow();
        }

        return $plan->discountDuration() === Plan::DISCOUNT_FIRST_MONTHS
            ? $from->addMonthsNoOverflow((int) $plan->discountMonths())
            : $from->addMonthNoOverflow();
    }

    /** Keeps a price like "$9.00" in one piece inside Arabic text. */
    private static function isolate(string $price): string
    {
        return "\u{2066}".$price."\u{2069}";
    }
}
