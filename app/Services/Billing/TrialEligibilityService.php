<?php

namespace App\Services\Billing;

use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class TrialEligibilityService
{
    public const REASON_ALREADY_USED_ACCOUNT = 'already_used_account';

    public const REASON_ALREADY_USED_EMAIL = 'already_used_email';

    public const REASON_ALREADY_USED_PHONE = 'already_used_phone';

    public const REASON_ALREADY_USED_DEVICE = 'already_used_device';

    public const REASON_ALREADY_USED_CARD = 'already_used_card';

    public const REASON_ALREADY_SUBSCRIBED = 'already_subscribed';

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
        $creator = $this->creatorPlan();
        $starter = $this->starterPlan();
        $reason = $this->denialReason($user, null);
        $available = $reason === null && $creator !== null && $starter !== null && $this->creatorMonthlyPriceId() !== '';

        return [
            'available' => $available,
            'reason' => $reason,
            'plan' => (string) config('listen.billing.creator_trial.plan_slug', 'creator'),
            'cycle' => 'monthly',
            'days' => $this->trialDays(),
            'is_admin' => (bool) $user->is_admin,
            'stripe_test_mode' => $this->stripeIsTestMode(),
            'show_admin_test_cards' => (bool) $user->is_admin,
            'test_cards' => $user->is_admin ? $this->adminTestCards() : [],
            'quota' => [
                'chars' => (int) ($starter->monthly_chars ?? 0),
                'per_request' => (int) ($starter->max_chars_per_request ?? 0),
                'minutes' => (int) ($starter->monthly_minutes ?? 0),
                'premium_minutes' => (int) ($starter->premium_voice_minutes ?? 0),
            ],
            'amount_cents' => (int) ($creator->price_cents ?? 0),
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

    public function trialDays(): int
    {
        return max(1, (int) config('listen.billing.creator_trial.days', 7));
    }

    public function creatorPlan(): ?Plan
    {
        return Plan::query()
            ->where('slug', (string) config('listen.billing.creator_trial.plan_slug', 'creator'))
            ->where('is_active', true)
            ->where('price_cents', '>', 0)
            ->first();
    }

    public function starterPlan(): ?Plan
    {
        return Plan::query()
            ->where('slug', (string) config('listen.billing.creator_trial.quota_plan_slug', 'starter'))
            ->where('is_active', true)
            ->first();
    }

    public function creatorMonthlyPriceId(): string
    {
        $plan = $this->creatorPlan();
        if (! $plan) {
            return '';
        }

        $priceId = trim((string) $plan->getStripePriceId('monthly'));
        if ($priceId !== '') {
            return $priceId;
        }

        return trim((string) config('services.stripe.prices.creator.monthly', ''));
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
