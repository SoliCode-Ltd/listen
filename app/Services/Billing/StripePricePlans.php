<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;

/**
 * Which plan a Stripe price belongs to.
 *
 * A plan's price can change at any time, and members who bought before keep
 * paying their old price. An old price must still mean the same plan, or the
 * member loses what they pay for. Checked in this order:
 *   1. the plans' current prices,
 *   2. every price a plan has had before (remembered in a setting),
 *   3. the plan tag and product on the price itself in Stripe.
 */
class StripePricePlans
{
    /** price id => [plan_id, slug, cycle], for every price a plan has ever had. */
    public const HISTORY_SETTING = 'stripe_price_plan_history';

    /** @var array<string, ?object> */
    private array $fetched = [];

    /**
     * @param  object|string|null  $price  a Stripe price object, or only its id
     * @param  bool  $askStripe  when only an unknown id is given, read the price from Stripe
     * @return array{plan: Plan, cycle: string}|null
     */
    public function planFor(object|string|null $price, bool $askStripe = false): ?array
    {
        $id = trim((string) (is_object($price) ? ($price->id ?? '') : $price));
        if ($id === '') {
            return null;
        }

        $entry = $this->currentPrices()[$id] ?? $this->history()[$id] ?? null;

        if ($entry === null) {
            $object = is_object($price) ? $price : ($askStripe ? $this->fetch($id) : null);
            $entry = $object ? $this->fromStripeTags($object) : null;
            if ($entry !== null) {
                $this->remember($id, $entry);
            }
        }

        if ($entry === null) {
            return null;
        }

        $plan = Plan::query()->find($entry['plan_id']) ?? Plan::query()->where('slug', $entry['slug'])->first();

        return $plan ? ['plan' => $plan, 'cycle' => $entry['cycle']] : null;
    }

    /**
     * Remember a plan's price, so it keeps meaning that plan after the plan
     * moves to a new price.
     */
    public function rememberPlanPrice(Plan $plan, ?string $priceId, string $cycle): void
    {
        $priceId = trim((string) $priceId);
        if ($priceId !== '' && $plan->id) {
            $this->remember($priceId, ['plan_id' => (int) $plan->id, 'slug' => (string) $plan->slug, 'cycle' => $cycle === 'yearly' ? 'yearly' : 'monthly']);
        }
    }

    /**
     * @return array<string, array{plan_id: int, slug: string, cycle: string}>
     */
    private function currentPrices(): array
    {
        $map = [];
        $add = function (string $priceId, Plan $plan, string $cycle) use (&$map): void {
            $priceId = trim($priceId);
            if ($priceId === '') {
                return;
            }
            if (isset($map[$priceId])) {
                if ($map[$priceId]['slug'] !== (string) $plan->slug) {
                    Log::error('The same Stripe price is set on two plans. The first plan keeps it.', [
                        'price_id' => $priceId,
                        'kept_plan' => $map[$priceId]['slug'],
                        'ignored_plan' => (string) $plan->slug,
                    ]);
                }

                return;
            }
            $map[$priceId] = ['plan_id' => (int) $plan->id, 'slug' => (string) $plan->slug, 'cycle' => $cycle];
        };

        $plans = Plan::query()->orderBy('id')->get(['id', 'slug', 'stripe_price_monthly', 'stripe_price_yearly']);
        foreach ($plans as $plan) {
            $add((string) $plan->stripe_price_monthly, $plan, 'monthly');
            $add((string) $plan->stripe_price_yearly, $plan, 'yearly');
        }

        // Older places a price could be set: admin settings, then .env config.
        foreach ($plans as $plan) {
            foreach (['monthly', 'yearly'] as $cycle) {
                $add((string) SiteSetting::get("stripe_price_{$plan->slug}_{$cycle}", ''), $plan, $cycle);
                $add((string) config("services.stripe.prices.{$plan->slug}.{$cycle}", ''), $plan, $cycle);
            }
        }

        // Every price that is a plan's price today is remembered, so it still
        // counts after the plan's price changes.
        $history = $this->history();
        foreach ($map as $priceId => $entry) {
            if (! isset($history[$priceId])) {
                $this->remember($priceId, $entry);
            }
        }

        return $map;
    }

    /**
     * @return array<string, array{plan_id: int, slug: string, cycle: string}>
     */
    private function history(): array
    {
        $decoded = json_decode((string) SiteSetting::get(self::HISTORY_SETTING, '[]'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array{plan_id: int, slug: string, cycle: string}  $entry
     */
    private function remember(string $priceId, array $entry): void
    {
        $history = $this->history();
        if (($history[$priceId] ?? null) === $entry) {
            return;
        }

        $history[$priceId] = $entry;
        SiteSetting::set(self::HISTORY_SETTING, json_encode($history, JSON_UNESCAPED_SLASHES), 'billing');
    }

    /**
     * Our own Stripe prices carry the plan's id and slug, and each plan has its
     * own Stripe product, so a price nobody remembered can still be placed.
     *
     * @return array{plan_id: int, slug: string, cycle: string}|null
     */
    private function fromStripeTags(object $price): ?array
    {
        $metadata = $price->metadata ?? null;
        $interval = (string) ($metadata->listen_interval ?? $price->recurring->interval ?? 'month');
        $cycle = $interval === 'year' ? 'yearly' : 'monthly';

        $planId = (int) ($metadata->listen_plan_id ?? 0);
        $slug = trim((string) ($metadata->listen_plan_slug ?? ''));
        $product = $price->product ?? null;
        $productId = trim((string) (is_object($product) ? ($product->id ?? '') : $product));

        $plan = null;
        if ($slug !== '') {
            $plan = Plan::query()->where('slug', $slug)->first();
        }
        if (! $plan && $planId > 0) {
            $plan = Plan::query()->find($planId);
        }
        if (! $plan && $productId !== '') {
            $plan = Plan::query()->where('stripe_product_id', $productId)->orderBy('id')->first();
        }

        return $plan ? ['plan_id' => (int) $plan->id, 'slug' => (string) $plan->slug, 'cycle' => $cycle] : null;
    }

    private function fetch(string $priceId): ?object
    {
        if (! array_key_exists($priceId, $this->fetched)) {
            try {
                $this->fetched[$priceId] = Cashier::stripe()->prices->retrieve($priceId, []);
            } catch (\Throwable $exception) {
                Log::warning('Could not read a Stripe price to find its plan.', [
                    'price_id' => $priceId,
                    'error' => $exception->getMessage(),
                ]);
                $this->fetched[$priceId] = null;
            }
        }

        return $this->fetched[$priceId];
    }
}
