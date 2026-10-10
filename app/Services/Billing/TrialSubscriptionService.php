<?php

namespace App\Services\Billing;

use App\Exceptions\TrialStartException;
use App\Http\Middleware\CaptureAdClickId;
use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanAccessService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TrialSubscriptionService
{
    public function __construct(
        private readonly StripeTrialGateway $gateway,
        private readonly TrialEligibilityService $eligibility,
        private readonly PlanAccessService $planAccess,
        private readonly SavedPaymentMethodRecorder $cards,
    ) {}

    /**
     * @param  array{plan:string,billing_cycle:string}  $input
     * @return array{client_secret:string,setup_intent_id:string}
     */
    public function beginSetup(User $user, array $input): array
    {
        [$plan, $cycle] = $this->resolveCheckoutPlan($input);

        $reason = $this->eligibility->denialReason($user, null);
        if ($reason !== null) {
            $this->recordBlocked($user, $reason, null, null, $plan, $cycle);
            throw new TrialStartException($this->reasonKey($reason), 422, true, $reason);
        }

        try {
            $customerId = $this->gateway->ensureCustomer($user);
            $intent = $this->gateway->createSetupIntent($customerId, [
                'listen_purpose' => 'card_trial',
                'listen_plan_slug' => (string) $plan->slug,
                'listen_billing_cycle' => $cycle,
            ]);
        } catch (TrialStartException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::warning('Creator trial setup intent failed.', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            throw new TrialStartException('ui.backend.trial_card_setup_failed');
        }

        if (trim($intent['client_secret']) === '' || trim($intent['id']) === '') {
            throw new TrialStartException('ui.backend.trial_card_setup_failed');
        }

        return [
            'client_secret' => $intent['client_secret'],
            'setup_intent_id' => $intent['id'],
        ];
    }

    /**
     * @return array{trial_ends_at:string,plan:string,amount_cents:int}
     */
    public function confirm(User $user, string $setupIntentId): array
    {
        $setupIntentId = trim($setupIntentId);
        if ($setupIntentId === '') {
            throw new TrialStartException('ui.backend.trial_card_not_verified');
        }

        $existing = BillingTrial::query()->where('setup_intent_id', $setupIntentId)->first();
        if ($existing && (int) $existing->user_id === (int) $user->id && $existing->status === BillingTrial::STATUS_TRIALING) {
            return $this->successPayload($existing);
        }

        if (! $this->eligibility->enabled()) {
            throw new TrialStartException('ui.backend.trial_disabled');
        }

        try {
            $intent = $this->gateway->retrieveSetupIntent($setupIntentId);
        } catch (\Throwable $exception) {
            Log::warning('Creator trial setup intent could not be loaded.', [
                'user_id' => $user->id,
                'setup_intent_id' => $setupIntentId,
                'error' => $exception->getMessage(),
            ]);

            throw new TrialStartException('ui.backend.trial_card_not_verified');
        }

        $this->assertIntentVerified($user, $intent);
        [$creator, $cycle] = $this->resolveStoredPlan($intent);
        $starter = $this->eligibility->quotaPlanFor($creator);
        if (! $starter instanceof Plan) {
            throw new TrialStartException('ui.backend.trial_plan_not_ready');
        }
        $priceId = $this->eligibility->priceIdFor($creator, $cycle);

        try {
            $card = $this->gateway->retrievePaymentMethod($intent['payment_method']);
        } catch (\Throwable $exception) {
            Log::warning('Creator trial payment method could not be loaded.', [
                'user_id' => $user->id,
                'setup_intent_id' => $setupIntentId,
                'error' => $exception->getMessage(),
            ]);

            throw new TrialStartException('ui.backend.trial_card_not_verified');
        }

        $this->assertCardAccepted($card, (string) $user->stripe_id);

        $reason = $this->eligibility->denialReason($user, (string) ($card['fingerprint'] ?? ''));
        if ($reason !== null) {
            $this->recordBlocked($user, $reason, $card, $setupIntentId, $creator, $cycle);
            $this->cards->rememberCard($user, $card, 'trial', false, null, $setupIntentId, true);
            throw new TrialStartException($this->reasonKey($reason), 422, true, $reason);
        }

        try {
            return $this->confirmInsideLock($user, $creator, $cycle, $starter, $priceId, $card, $setupIntentId);
        } catch (TrialStartException $exception) {
            if ($exception->blocked && $exception->reason !== null && ! $this->blockedMomentAgo($user, $exception->reason)) {
                $this->recordBlocked($user, $exception->reason, $card, $setupIntentId, $creator, $cycle);
            }

            throw $exception;
        }
    }

    /**
     * @param  array{id:string,brand?:string,last4?:string,exp_month?:int,exp_year?:int,fingerprint?:string,funding?:string,country?:string,cvc_check?:string}  $card
     * @return array{trial_ends_at:string,plan:string,amount_cents:int}
     */
    private function confirmInsideLock(User $user, Plan $creator, string $cycle, Plan $starter, string $priceId, array $card, string $setupIntentId): array
    {
        return DB::transaction(function () use ($user, $creator, $cycle, $starter, $priceId, $card, $setupIntentId) {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $again = BillingTrial::query()->where('setup_intent_id', $setupIntentId)->first();
            if ($again && (int) $again->user_id === (int) $locked->id && $again->status === BillingTrial::STATUS_TRIALING) {
                return $this->successPayload($again);
            }

            $reason = $this->eligibility->denialReason($locked, (string) ($card['fingerprint'] ?? ''));
            if ($reason !== null) {
                throw new TrialStartException($this->reasonKey($reason), 422, true, $reason);
            }

            if ($locked->is_admin) {
                $this->retireAdminTestTrials($locked);
            }

            try {
                $this->gateway->setDefaultPaymentMethod((string) $locked->stripe_id, (string) $card['id']);
                $stripeSubscription = $this->gateway->createTrialSubscription(
                    (string) $locked->stripe_id,
                    $priceId,
                    (string) $card['id'],
                    $this->eligibility->trialDays(),
                    [
                        'listen_trial' => '1',
                        'listen_plan_slug' => (string) $creator->slug,
                        'listen_billing_cycle' => $cycle,
                        'listen_user_id' => (string) $locked->id,
                        'listen_admin_test' => $locked->is_admin ? '1' : '0',
                    ]
                );
            } catch (TrialStartException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                Log::warning('Creator trial subscription was not created.', [
                    'user_id' => $locked->id,
                    'error' => $exception->getMessage(),
                ]);

                throw new TrialStartException('ui.backend.trial_subscription_failed');
            }

            try {
                $this->assertSubscriptionIsTrial($stripeSubscription, $priceId, (string) $card['id']);
            } catch (TrialStartException $exception) {
                $this->cancelQuietly((string) ($stripeSubscription['id'] ?? ''));
                throw $exception;
            }

            try {
                $trial = $this->persistTrial($locked, $creator, $cycle, $starter, $priceId, $card, $setupIntentId, $stripeSubscription);
            } catch (\Throwable $exception) {
                $this->cancelQuietly((string) ($stripeSubscription['id'] ?? ''));
                Log::warning('Creator trial local record failed after Stripe created the subscription. The Stripe subscription was canceled.', [
                    'user_id' => $locked->id,
                    'stripe_subscription_id' => $stripeSubscription['id'] ?? null,
                    'error' => $exception->getMessage(),
                ]);

                throw new TrialStartException('ui.backend.trial_subscription_failed');
            }

            return $this->successPayload($trial);
        });
    }

    /**
     * @param  array{plan?:string,billing_cycle?:string}  $input
     * @return array{0:Plan,1:string}
     */
    private function resolveCheckoutPlan(array $input): array
    {
        if (! $this->eligibility->enabled()) {
            throw new TrialStartException('ui.backend.trial_disabled');
        }

        if ($this->eligibility->starterPlan() === null) {
            throw new TrialStartException('ui.backend.trial_plan_not_ready');
        }

        $cycle = strtolower(trim((string) ($input['billing_cycle'] ?? '')));
        $plan = $this->eligibility->trialPlan((string) ($input['plan'] ?? ''), $cycle);
        if (! $plan instanceof Plan) {
            throw new TrialStartException('ui.backend.trial_creator_monthly_only');
        }

        return [$plan, $cycle];
    }

    /**
     * @param  array{metadata?:array<string, mixed>}  $intent
     * @return array{0:Plan,1:string}
     */
    private function resolveStoredPlan(array $intent): array
    {
        $slug = strtolower(trim((string) ($intent['metadata']['listen_plan_slug'] ?? '')));
        if ($slug === '') {
            throw new TrialStartException('ui.backend.trial_plan_not_ready');
        }

        $cycle = strtolower(trim((string) ($intent['metadata']['listen_billing_cycle'] ?? '')));
        $cycle = $cycle === '' ? 'monthly' : $cycle;
        $plan = $this->eligibility->trialPlan($slug, $cycle);
        if (! $plan instanceof Plan) {
            throw new TrialStartException('ui.backend.trial_creator_monthly_only');
        }

        return [$plan, $cycle];
    }

    /**
     * @param  array{id:string,status:string,customer:string,payment_method:string}  $intent
     */
    private function assertIntentVerified(User $user, array $intent): void
    {
        if (strtolower($intent['status']) !== 'succeeded') {
            throw new TrialStartException('ui.backend.trial_card_not_verified');
        }

        if ($intent['customer'] === '' || $intent['customer'] !== (string) $user->stripe_id) {
            throw new TrialStartException('ui.backend.trial_card_not_verified');
        }

        if ($intent['payment_method'] === '') {
            throw new TrialStartException('ui.backend.trial_card_not_verified');
        }
    }

    /**
     * @param  array{id:string,type:string,customer:string,exp_month:int,exp_year:int,cvc_check:string}  $card
     */
    private function assertCardAccepted(array $card, string $customerId): void
    {
        if (strtolower((string) ($card['type'] ?? '')) !== 'card') {
            throw new TrialStartException('ui.backend.billing_only_cards_supported');
        }

        $attachedCustomer = trim((string) ($card['customer'] ?? ''));
        if ($attachedCustomer !== '' && $attachedCustomer !== $customerId) {
            throw new TrialStartException('ui.backend.trial_card_not_verified');
        }

        if (strtolower((string) ($card['cvc_check'] ?? '')) === 'fail') {
            throw new TrialStartException('ui.backend.trial_card_not_verified');
        }

        $month = (int) ($card['exp_month'] ?? 0);
        $year = (int) ($card['exp_year'] ?? 0);
        if ($month < 1 || $month > 12 || $year < 2000) {
            throw new TrialStartException('ui.backend.trial_card_not_verified');
        }

        $expires = Carbon::createFromDate($year, $month, 1)->endOfMonth();
        if ($expires->isPast()) {
            throw new TrialStartException('ui.backend.trial_card_expired');
        }
    }

    /**
     * @param  array{id:string,status:string,trial_end:int,default_payment_method:string,price_id:string}  $subscription
     */
    private function assertSubscriptionIsTrial(array $subscription, string $priceId, string $paymentMethodId): void
    {
        if (strtolower((string) ($subscription['status'] ?? '')) !== 'trialing') {
            throw new TrialStartException('ui.backend.trial_subscription_failed');
        }

        if ((string) ($subscription['price_id'] ?? '') !== $priceId) {
            throw new TrialStartException('ui.backend.trial_subscription_failed');
        }

        if ((string) ($subscription['default_payment_method'] ?? '') !== $paymentMethodId) {
            throw new TrialStartException('ui.backend.trial_subscription_failed');
        }

        $trialEnd = (int) ($subscription['trial_end'] ?? 0);
        $earliest = now()->addDays($this->eligibility->trialDays() - 1)->getTimestamp();
        $latest = now()->addDays($this->eligibility->trialDays() + 1)->getTimestamp();
        if ($trialEnd < $earliest || $trialEnd > $latest) {
            throw new TrialStartException('ui.backend.trial_subscription_failed');
        }
    }

    /**
     * @param  array{id:string,brand?:string,last4?:string,exp_month?:int,exp_year?:int,fingerprint?:string,funding?:string,country?:string,cvc_check?:string}  $card
     * @param  array{id:string,trial_end:int,item_id?:string,product_id?:string}  $stripeSubscription
     */
    private function persistTrial(User $user, Plan $creator, string $cycle, Plan $starter, string $priceId, array $card, string $setupIntentId, array $stripeSubscription): BillingTrial
    {
        $endsAt = Carbon::createFromTimestamp((int) $stripeSubscription['trial_end']);

        $trial = BillingTrial::query()->create([
            'user_id' => $user->id,
            'plan_id' => $creator->id,
            'quota_plan_id' => $starter->id,
            'billing_cycle' => $cycle,
            'status' => BillingTrial::STATUS_TRIALING,
            'is_admin_test' => (bool) $user->is_admin,
            'stripe_customer_id' => $user->stripe_id,
            'stripe_subscription_id' => $stripeSubscription['id'],
            'setup_intent_id' => $setupIntentId,
            'stripe_payment_method_id' => $card['id'],
            'card_brand' => $card['brand'] ?? null,
            'card_last4' => $card['last4'] ?? null,
            'card_exp_month' => (int) ($card['exp_month'] ?? 0) ?: null,
            'card_exp_year' => (int) ($card['exp_year'] ?? 0) ?: null,
            'card_fingerprint' => $this->blankToNull($card['fingerprint'] ?? null),
            'card_funding' => $card['funding'] ?? null,
            'card_country' => $card['country'] ?? null,
            'cvc_check' => $card['cvc_check'] ?? null,
            'off_session_ready' => true,
            'amount_cents' => $this->cycleAmountCents($creator, $cycle),
            'currency' => 'usd',
            'chars_limit' => (int) $starter->monthly_chars,
            'minutes_limit' => (int) $starter->monthly_minutes,
            'max_chars_per_request' => (int) $starter->max_chars_per_request,
            'premium_minutes_limit' => (int) $starter->premium_voice_minutes,
            'email_normalized' => $this->eligibility->normalizeEmail($user->email),
            'phone_normalized' => $this->eligibility->normalizePhone($user->phone_number) ?: null,
            'device_id' => $this->eligibility->primaryDeviceId($user),
            'registration_ip' => $user->registration_ip,
            'gclid' => CaptureAdClickId::fromRequest(request()),
            'started_at' => now(),
            'trial_ends_at' => $endsAt,
        ]);

        $billingSubscription = $this->storeTrialSubscription($user, $priceId, $endsAt, $stripeSubscription);

        if (trim((string) ($stripeSubscription['item_id'] ?? '')) !== '') {
            $billingSubscription->items()->create([
                'stripe_id' => $stripeSubscription['item_id'],
                'stripe_product' => (string) ($stripeSubscription['product_id'] ?? 'prod_creator'),
                'stripe_price' => $priceId,
                'quantity' => 1,
            ]);
        }

        $this->cards->rememberCard($user, $card, 'trial', true, $trial->id, $setupIntentId, true);

        $user->forceFill([
            'pm_type' => $card['brand'] ?? $user->pm_type,
            'pm_last_four' => $card['last4'] ?? $user->pm_last_four,
        ])->save();

        $this->planAccess->assignPlan($user, $creator, 'active', $endsAt);

        return $trial;
    }

    /**
     * @param  array{id?:string,brand?:string,last4?:string,exp_month?:int,exp_year?:int,fingerprint?:string,funding?:string,country?:string,cvc_check?:string}|null  $card
     */
    private function recordBlocked(User $user, string $reason, ?array $card, ?string $setupIntentId, ?Plan $creator = null, string $cycle = 'monthly'): void
    {
        $starter = $this->eligibility->starterPlan();
        if (! $creator instanceof Plan) {
            return;
        }

        BillingTrial::query()->create([
            'user_id' => $user->id,
            'plan_id' => $creator->id,
            'quota_plan_id' => $starter?->id,
            'billing_cycle' => $cycle,
            'status' => BillingTrial::STATUS_BLOCKED,
            'is_admin_test' => false,
            'stripe_customer_id' => $user->stripe_id,
            'setup_intent_id' => null,
            'stripe_payment_method_id' => $card['id'] ?? null,
            'card_brand' => $card['brand'] ?? null,
            'card_last4' => $card['last4'] ?? null,
            'card_exp_month' => (int) ($card['exp_month'] ?? 0) ?: null,
            'card_exp_year' => (int) ($card['exp_year'] ?? 0) ?: null,
            'card_fingerprint' => $this->blankToNull($card['fingerprint'] ?? null),
            'card_funding' => $card['funding'] ?? null,
            'card_country' => $card['country'] ?? null,
            'cvc_check' => $card['cvc_check'] ?? null,
            'off_session_ready' => $card !== null,
            'amount_cents' => $this->cycleAmountCents($creator, $cycle),
            'currency' => 'usd',
            'chars_limit' => (int) ($starter->monthly_chars ?? 0),
            'minutes_limit' => (int) ($starter->monthly_minutes ?? 0),
            'max_chars_per_request' => (int) ($starter->max_chars_per_request ?? 0),
            'premium_minutes_limit' => (int) ($starter->premium_voice_minutes ?? 0),
            'email_normalized' => $this->eligibility->normalizeEmail($user->email),
            'phone_normalized' => $this->eligibility->normalizePhone($user->phone_number) ?: null,
            'device_id' => $this->eligibility->primaryDeviceId($user),
            'registration_ip' => $user->registration_ip,
            'blocked_reason' => $reason,
            'started_at' => now(),
        ]);

        unset($setupIntentId);
    }

    /**
     * A blocked member must not be charged when their free trial ends for an
     * account they can no longer use. Cancels their open (unpaid) trials in
     * Stripe and here. A trial Stripe would not cancel is left as it is, so
     * the admin still sees it running.
     *
     * @return int how many trials were cancelled
     */
    public function cancelOpenTrialsForBlockedUser(User $user): int
    {
        $openTrials = BillingTrial::query()
            ->where('user_id', $user->id)
            ->where('status', BillingTrial::STATUS_TRIALING)
            ->whereNull('paid_at')
            ->get();

        $cancelled = 0;
        foreach ($openTrials as $trial) {
            $subscriptionId = trim((string) $trial->stripe_subscription_id);

            if ($subscriptionId !== '') {
                try {
                    $this->gateway->cancelSubscription($subscriptionId);
                } catch (\Throwable $exception) {
                    Log::error('Could not cancel the free trial of a blocked member; it may still be charged.', [
                        'user_id' => $user->id,
                        'billing_trial_id' => $trial->id,
                        'stripe_subscription_id' => $subscriptionId,
                        'error' => $exception->getMessage(),
                    ]);

                    continue;
                }

                BillingSubscription::query()
                    ->where('stripe_id', $subscriptionId)
                    ->update([
                        'stripe_status' => 'canceled',
                        'ends_at' => now(),
                    ]);
            }

            $trial->forceFill([
                'status' => BillingTrial::STATUS_CANCELED,
                'canceled_at' => now(),
                'blocked_reason' => 'account_blocked',
            ])->save();
            $cancelled++;

            Log::info('Free trial cancelled because the member was blocked.', [
                'user_id' => $user->id,
                'billing_trial_id' => $trial->id,
            ]);
        }

        return $cancelled;
    }

    private function retireAdminTestTrials(User $user): void
    {
        $openTests = BillingTrial::query()
            ->where('user_id', $user->id)
            ->where('is_admin_test', true)
            ->where('status', BillingTrial::STATUS_TRIALING)
            ->get();

        foreach ($openTests as $trial) {
            $this->cancelQuietly((string) $trial->stripe_subscription_id);
            BillingSubscription::query()
                ->where('stripe_id', $trial->stripe_subscription_id)
                ->update([
                    'stripe_status' => 'canceled',
                    'ends_at' => now(),
                ]);
            $trial->forceFill([
                'status' => BillingTrial::STATUS_CANCELED,
                'canceled_at' => now(),
                'blocked_reason' => 'replaced_by_admin_retest',
            ])->save();
        }
    }

    /**
     * @param  array{id:string,item_id?:string,product_id?:string}  $stripeSubscription
     */
    private function storeTrialSubscription(User $user, string $priceId, Carbon $endsAt, array $stripeSubscription): BillingSubscription
    {
        $payload = [
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => $stripeSubscription['id'],
            'stripe_status' => 'trialing',
            'stripe_price' => $priceId,
            'quantity' => 1,
            'trial_ends_at' => $endsAt,
            'ends_at' => null,
        ];

        $existing = BillingSubscription::query()
            ->where('user_id', $user->id)
            ->where('type', 'default')
            ->first();

        if ($existing && ! in_array((string) $existing->stripe_status, ['canceled', 'incomplete', 'incomplete_expired'], true)) {
            throw new \RuntimeException('A live billing subscription already exists for this user.');
        }

        if ($existing) {
            $existing->items()->delete();
            $existing->forceFill($payload)->save();

            return $existing;
        }

        return BillingSubscription::query()->create($payload);
    }

    private function cancelQuietly(string $subscriptionId): void
    {
        if (trim($subscriptionId) === '') {
            return;
        }

        try {
            $this->gateway->cancelSubscription($subscriptionId);
        } catch (\Throwable $exception) {
            Log::warning('Unable to cancel a Creator trial subscription that failed verification.', [
                'stripe_subscription_id' => $subscriptionId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array{trial_ends_at:string,plan:string,amount_cents:int}
     */
    private function successPayload(BillingTrial $trial): array
    {
        return [
            'trial_ends_at' => optional($trial->trial_ends_at)->toIso8601String() ?? '',
            'plan' => (string) ($trial->plan?->slug ?: ''),
            'amount_cents' => (int) $trial->amount_cents,
        ];
    }

    private function blockedMomentAgo(User $user, string $reason): bool
    {
        return BillingTrial::query()
            ->where('user_id', $user->id)
            ->where('status', BillingTrial::STATUS_BLOCKED)
            ->where('blocked_reason', $reason)
            ->where('created_at', '>=', now()->subMinute())
            ->exists();
    }

    private function cycleAmountCents(Plan $plan, string $cycle): int
    {
        return $cycle === 'yearly'
            ? $plan->getEffectiveYearlyPriceCents()
            : (int) $plan->price_cents;
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function reasonKey(string $reason): string
    {
        return match ($reason) {
            TrialEligibilityService::REASON_ALREADY_SUBSCRIBED => 'ui.backend.trial_already_subscribed',
            default => 'ui.backend.trial_already_used',
        };
    }
}
