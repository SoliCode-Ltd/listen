<?php

namespace App\Services\Billing;

use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TrialEligibilityService
{
    public const REASON_ALREADY_USED_ACCOUNT = 'already_used_account';

    public const REASON_ALREADY_USED_EMAIL = 'already_used_email';

    public const REASON_ALREADY_USED_PHONE = 'already_used_phone';

    public const REASON_ALREADY_USED_DEVICE = 'already_used_device';

    public const REASON_ALREADY_USED_CARD = 'already_used_card';

    public const REASON_ALREADY_SUBSCRIBED = 'already_subscribed';

    public const CYCLES = ['monthly', 'yearly'];

    /**
     * @return list<array{number:string,label:string}>
     */
    public function adminTestCards(): array
    {
        return [
            ['number' => '4242 4242 4242 4242', 'label' => __('ui.billing.trial_test_visa_success')],
            ['number' => '4000 0566 5566 5556', 'label' => __('ui.billing.trial_test_visa_debit')],
            ['number' => '4000 0025 0000 3155', 'label' => __('ui.billing.trial_test_visa_auth')],
            ['number' => '4000 0000 0000 9995', 'label' => __('ui.billing.trial_test_visa_declined')],
        ];
    }

    public function stripeIsTestMode(): bool
    {
        return str_starts_with((string) config('services.stripe.secret'), 'sk_test_');
    }

    /**
     * @return array<string, mixed>
     */
    public function offerFor(User $user): array
    {
        $starter = $this->starterPlan();
        $plans = $this->trialablePlans();
        $reason = $this->denialReason($user, null);
        $enabled = $this->enabled();
        $available = $enabled
            && $reason === null
            && $starter !== null
            && $plans->isNotEmpty();

        $planQuotas = [];
        foreach ($plans as $plan) {
            $cap = $this->quotaPlanFor($plan);
            if ($cap instanceof Plan) {
                $planQuotas[(string) $plan->slug] = [
                    'name' => $cap->getLocalizedName(),
                    'chars' => (int) $cap->monthly_chars,
                    'per_request' => (int) $cap->max_chars_per_request,
                    'minutes' => (int) $cap->monthly_minutes,
                ];
            }
        }

        return [
            'enabled' => $enabled,
            'available' => $available,
            'reason' => $reason,
            'plans' => $plans->pluck('slug')->values()->all(),
            'plan_cycles' => $this->planCycles($plans),
            'plan_quotas' => $planQuotas,
            'quota_plan_name' => (string) ($starter?->getLocalizedName() ?? ''),
            'days' => $this->trialDays(),
            'is_admin' => (bool) $user->is_admin,
            'stripe_test_mode' => $this->stripeIsTestMode(),
            'show_admin_test_cards' => $enabled && (bool) $user->is_admin,
            'test_cards' => $user->is_admin ? $this->adminTestCards() : [],
            'quota' => [
                'chars' => (int) ($starter->monthly_chars ?? 0),
                'per_request' => (int) ($starter->max_chars_per_request ?? 0),
                'minutes' => (int) ($starter->monthly_minutes ?? 0),
                'premium_minutes' => (int) ($starter->premium_voice_minutes ?? 0),
            ],
        ];
    }

    /**
     * Public callout for the homepage and pricing page.
     *
     * @return array{show:bool,days:int,quota_plan_name:string,chars:int,minutes:int,plans:list<string>,plan_cycles:array<string, list<string>>,all_plans:bool}
     */
    public function promoFor(?User $user): array
    {
        $quota = $this->starterPlan();
        $plans = $this->trialablePlans();
        $enabled = $this->enabled() && $quota !== null && $plans->isNotEmpty();
        $eligible = $user === null || $this->denialReason($user, null) === null;

        return [
            'show' => $enabled && $eligible,
            'days' => $this->trialDays(),
            'quota_plan_name' => (string) ($quota?->getLocalizedName() ?? ''),
            'chars' => (int) ($quota->monthly_chars ?? 0),
            'minutes' => (int) ($quota->monthly_minutes ?? 0),
            'plans' => $plans->pluck('slug')->values()->all(),
            'plan_cycles' => $this->planCycles($plans),
            'all_plans' => $plans->count() === Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)->count(),
        ];
    }

    public function denialReason(User $user, ?string $cardFingerprint): ?string
    {
        if ($user->is_admin) {
            return $this->adminHasProtectedSubscription($user)
                ? self::REASON_ALREADY_SUBSCRIBED
                : null;
        }

        $email = $this->normalizeEmail($user->email);
        $phone = $this->normalizePhone($user->phone_number);
        $deviceIds = $this->deviceIds($user);

        $matches = $this->consumingTrials()
            ->where(function (Builder $query) use ($user, $email, $phone, $deviceIds) {
                $query->where('user_id', $user->id);

                if ($email !== '') {
                    $query->orWhere('email_normalized', $email);
                }

                if ($phone !== '') {
                    $query->orWhere('phone_normalized', $phone);
                }

                if ($deviceIds !== []) {
                    $query->orWhereIn('device_id', $deviceIds);
                }
            })
            ->get(['user_id', 'email_normalized', 'phone_normalized', 'device_id']);

        foreach ($matches as $trial) {
            if ((int) $trial->user_id === (int) $user->id) {
                return self::REASON_ALREADY_USED_ACCOUNT;
            }
            if ($email !== '' && $trial->email_normalized === $email) {
                return self::REASON_ALREADY_USED_EMAIL;
            }
            if ($phone !== '' && $trial->phone_normalized === $phone) {
                return self::REASON_ALREADY_USED_PHONE;
            }
            if ($deviceIds !== [] && in_array((string) $trial->device_id, $deviceIds, true)) {
                return self::REASON_ALREADY_USED_DEVICE;
            }
        }

        $cardFingerprint = trim((string) $cardFingerprint);
        if ($cardFingerprint !== '' && $this->consumingTrials()->where('card_fingerprint', $cardFingerprint)->exists()) {
            return self::REASON_ALREADY_USED_CARD;
        }

        if ($this->hasProtectedSubscription($user)) {
            return self::REASON_ALREADY_SUBSCRIBED;
        }

        return null;
    }

    public function enabled(): bool
    {
        return (string) SiteSetting::get('billing_trial_enabled', config('listen.billing.creator_trial.enabled', true) ? '1' : '0') === '1';
    }

    public function trialPlanSlug(): string
    {
        $stored = strtolower(trim((string) SiteSetting::get('billing_trial_plan_slug', '')));
        if ($stored !== '') {
            return $stored;
        }

        return strtolower((string) config('listen.billing.creator_trial.plan_slug', 'creator'));
    }

    public function quotaPlanSlug(): string
    {
        $stored = strtolower(trim((string) SiteSetting::get('billing_trial_quota_plan_slug', '')));
        if ($stored !== '') {
            return $stored;
        }

        return strtolower((string) config('listen.billing.creator_trial.quota_plan_slug', 'starter'));
    }

    public function trialDays(): int
    {
        $stored = SiteSetting::get('billing_trial_days', null);
        $days = $stored === null || $stored === ''
            ? (int) config('listen.billing.creator_trial.days', 7)
            : (int) $stored;

        return max(1, min(90, $days));
    }

    public function creatorPlan(): ?Plan
    {
        return Plan::query()->subscriptionPlans()
            ->where('slug', $this->trialPlanSlug())
            ->where('is_active', true)
            ->where('price_cents', '>', 0)
            ->first();
    }

    public function starterPlan(): ?Plan
    {
        return Plan::query()
            ->where('slug', $this->quotaPlanSlug())
            ->where('is_active', true)
            ->first();
    }

    public function creatorMonthlyPriceId(): string
    {
        $plan = $this->creatorPlan();

        return $plan ? $this->monthlyPriceId($plan) : '';
    }

    /**
     * The trial never allows more than the plan the customer picked.
     */
    public function quotaPlanFor(Plan $chosen): ?Plan
    {
        $cap = $this->starterPlan();
        if (! $cap instanceof Plan) {
            return null;
        }

        return (int) $cap->monthly_chars > (int) $chosen->monthly_chars ? $chosen : $cap;
    }

    public function paidMonthlyPlan(string $slug): ?Plan
    {
        return $this->trialPlan($slug, 'monthly');
    }

    /**
     * Plans the admin picked for the trial. Null means every paid plan (never configured).
     *
     * @return list<string>|null
     */
    public function allowedPlanSlugs(): ?array
    {
        $stored = SiteSetting::get('billing_trial_plan_slugs', null);
        if ($stored === null || $stored === '') {
            return null;
        }

        $slugs = json_decode((string) $stored, true);

        return is_array($slugs)
            ? array_values(array_unique(array_map(fn ($slug): string => strtolower(trim((string) $slug)), $slugs)))
            : null;
    }

    /**
     * @return list<string>
     */
    public function allowedCycles(): array
    {
        $stored = SiteSetting::get('billing_trial_cycles', null);
        if ($stored === null || $stored === '') {
            return self::CYCLES;
        }

        $cycles = json_decode((string) $stored, true);

        return is_array($cycles)
            ? array_values(array_intersect(self::CYCLES, $cycles))
            : self::CYCLES;
    }

    public function planAllowed(Plan $plan): bool
    {
        $allowed = $this->allowedPlanSlugs();

        return $allowed === null || in_array(strtolower((string) $plan->slug), $allowed, true);
    }

    public function trialPlan(string $slug, string $cycle): ?Plan
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || ! in_array($cycle, $this->allowedCycles(), true)) {
            return null;
        }

        $plan = Plan::query()->subscriptionPlans()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->where('price_cents', '>', 0)
            ->first();

        if (! $plan instanceof Plan || ! $this->planAllowed($plan) || $this->priceIdFor($plan, $cycle) === '') {
            return null;
        }

        return $plan;
    }

    public function monthlyPriceId(Plan $plan): string
    {
        return $this->priceIdFor($plan, 'monthly');
    }

    public function priceIdFor(Plan $plan, string $cycle): string
    {
        $cycle = $cycle === 'yearly' ? 'yearly' : 'monthly';
        $priceId = trim((string) $plan->getStripePriceId($cycle));
        if ($priceId !== '') {
            return $priceId;
        }

        return trim((string) config('services.stripe.prices.'.$plan->slug.'.'.$cycle, ''));
    }

    /**
     * @return list<string>
     */
    public function cyclesFor(Plan $plan): array
    {
        if (! $this->planAllowed($plan)) {
            return [];
        }

        return array_values(array_filter($this->allowedCycles(), fn (string $cycle): bool => $this->priceIdFor($plan, $cycle) !== ''));
    }

    /**
     * @return Collection<int, Plan>
     */
    public function trialablePlans(): Collection
    {
        return Plan::query()->subscriptionPlans()
            ->where('is_active', true)
            ->where('price_cents', '>', 0)
            ->orderBy('price_cents')
            ->get()
            ->filter(fn (Plan $plan): bool => $this->cyclesFor($plan) !== [])
            ->values();
    }

    /**
     * @param  Collection<int, Plan>  $plans
     * @return array<string, list<string>>
     */
    private function planCycles(Collection $plans): array
    {
        return $plans->mapWithKeys(fn (Plan $plan): array => [(string) $plan->slug => $this->cyclesFor($plan)])->all();
    }

    public function normalizeEmail(?string $email): string
    {
        return strtolower(trim((string) $email));
    }

    public function normalizePhone(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?? '';
    }

    /**
     * @return list<string>
     */
    public function deviceIds(User $user): array
    {
        return array_values(array_unique(array_filter([
            trim((string) ($user->last_seen_device_id ?? '')),
            trim((string) ($user->registration_device_id ?? '')),
        ])));
    }

    public function primaryDeviceId(User $user): ?string
    {
        return $this->deviceIds($user)[0] ?? null;
    }

    /**
     * @return Builder<BillingTrial>
     */
    private function consumingTrials(): Builder
    {
        return BillingTrial::query()
            ->where('is_admin_test', false)
            ->where('status', '!=', BillingTrial::STATUS_BLOCKED);
    }

    private function hasProtectedSubscription(User $user): bool
    {
        return BillingSubscription::query()
            ->where('user_id', $user->id)
            ->whereIn('stripe_status', ['active', 'trialing', 'past_due', 'unpaid'])
            ->exists();
    }

    private function adminHasProtectedSubscription(User $user): bool
    {
        $adminTestSubscriptionIds = BillingTrial::query()
            ->where('user_id', $user->id)
            ->where('is_admin_test', true)
            ->whereNotNull('stripe_subscription_id')
            ->pluck('stripe_subscription_id');

        return BillingSubscription::query()
            ->where('user_id', $user->id)
            ->whereIn('stripe_status', ['active', 'past_due', 'unpaid'])
            ->when($adminTestSubscriptionIds->isNotEmpty(), function ($query) use ($adminTestSubscriptionIds) {
                $query->whereNotIn('stripe_id', $adminTestSubscriptionIds->all());
            })
            ->exists();
    }
}
