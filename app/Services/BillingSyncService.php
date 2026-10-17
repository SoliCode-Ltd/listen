<?php

namespace App\Services;

use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\StripePricePlans;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;

class BillingSyncService
{
    public function __construct(
        private readonly PlanAccessService $planAccessService,
        private readonly StripePricePlans $stripePrices,
    ) {}

    public function syncIfStale(User $user, bool $force = false): void
    {
        $ttlMinutes = max(1, (int) config('listen.billing.subscription_sync_ttl_minutes', 10));
        $lastCheckedAt = $user->subscription_status_checked_at;

        if (! $force && $lastCheckedAt && $lastCheckedAt->gt(now()->subMinutes($ttlMinutes))) {
            return;
        }

        $this->syncUser($user);
    }

    public function syncUser(User $user): void
    {
        $freePlan = $this->planAccessService->freePlan();
        if (! $freePlan) {
            return;
        }

        // Admin can explicitly lock a user's plan by setting
        // billing_payment_state=manual_override on an active subscription.
        if ($this->hasManualPlanOverride($user)) {
            $user->forceFill([
                'subscription_status_checked_at' => now(),
            ])->save();

            return;
        }

        $currentPlan = $this->planAccessService->resolveActivePlanForUserId((int) $user->id);
        $targetPlan = $currentPlan ?: $freePlan;
        $periodEnd = now()->addMonth();
        $periodStart = null;
        $keepPlanAsIs = false;

        if ($user->stripe_id) {
            try {
                $customer = Cashier::stripe()->customers->retrieve($user->stripe_id, []);
                if ($this->isDeletedStripeCustomer($customer)) {
                    $this->resetBillingStateForMissingCustomer($user);
                    $targetPlan = $freePlan;
                    $periodEnd = now()->addMonth();

                    $user->forceFill([
                        'stripe_id' => null,
                        'pm_type' => null,
                        'pm_last_four' => null,
                    ])->save();
                } else {
                    [$stripeSubscription, $unknownPaid] = $this->resolveRelevantStripeSubscription($user->stripe_id);
                    $selectedPaid = in_array((string) ($stripeSubscription->status ?? ''), ['active', 'trialing'], true);

                    if ($unknownPaid !== [] && ! $selectedPaid) {
                        // Stripe is still charging for a subscription no plan owns. Never
                        // take a paying member's plan away for that: keep it and flag it.
                        Log::error('A member pays for a Stripe subscription whose price no plan knows. Their plan was kept.', [
                            'user_id' => $user->id,
                            'stripe_subscriptions' => $unknownPaid,
                        ]);
                        $keepPlanAsIs = true;
                    } elseif ($stripeSubscription) {
                        $selectedStatus = (string) ($stripeSubscription->status ?? '');
                        $mappedSlug = $this->mappedPlanSlugForSubscription($stripeSubscription);
                        $mappedPlan = $mappedSlug ? Plan::query()->where('slug', $mappedSlug)->first() : null;
                        $targetPlan = $freePlan;

                        if ($mappedPlan && in_array($selectedStatus, ['active', 'trialing'], true)) {
                            $targetPlan = $mappedPlan;
                            $periodEndTs = (int) ($stripeSubscription->current_period_end
                                ?? $stripeSubscription->items->data[0]->current_period_end
                                ?? 0);
                            $periodEnd = $periodEndTs > 0
                                ? Carbon::createFromTimestamp($periodEndTs)
                                : now()->addMonth();
                            // Stripe's own period start, so a refresh never moves it to "now".
                            $periodStartTs = (int) ($stripeSubscription->current_period_start
                                ?? $stripeSubscription->items->data[0]->current_period_start
                                ?? 0);
                            $periodStart = $periodStartTs > 0 ? Carbon::createFromTimestamp($periodStartTs) : null;
                        }

                        $this->persistBillingSubscription($user, $stripeSubscription);
                    } else {
                        $targetPlan = $freePlan;
                        $billingSubscription = BillingSubscription::query()
                            ->where('user_id', $user->id)
                            ->where('type', 'default')
                            ->latest('id')
                            ->first();

                        if ($billingSubscription) {
                            BillingSubscription::query()
                                ->where('user_id', $user->id)
                                ->where('type', 'default')
                                ->where('stripe_status', '!=', 'canceled')
                                ->update([
                                    'stripe_status' => 'canceled',
                                    'ends_at' => now(),
                                ]);
                        }
                    }

                    $defaultPaymentMethodId = $this->extractDefaultPaymentMethodIdFromCustomer($customer);
                    $defaultPaymentMethod = $this->resolveCustomerDefaultPaymentMethod($defaultPaymentMethodId);

                    $user->forceFill([
                        'pm_type' => $defaultPaymentMethod['pm_type'],
                        'pm_last_four' => $defaultPaymentMethod['pm_last_four'],
                    ])->save();
                }
            } catch (ApiErrorException $exception) {
                if ($this->isMissingStripeCustomerError($exception)) {
                    $this->resetBillingStateForMissingCustomer($user);
                    $targetPlan = $freePlan;
                    $periodEnd = now()->addMonth();

                    $user->forceFill([
                        'stripe_id' => null,
                        'pm_type' => null,
                        'pm_last_four' => null,
                    ])->save();
                } else {
                    Log::warning('Billing sync failed when contacting Stripe API.', [
                        'user_id' => $user->id,
                        'stripe_id' => $user->stripe_id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            } catch (\Throwable $exception) {
                Log::warning('Billing sync failed unexpectedly.', [
                    'user_id' => $user->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (! $keepPlanAsIs) {
            $this->planAccessService->assignPlan($user, $targetPlan, 'active', $periodEnd, $periodStart);
        }

        $user->forceFill([
            'subscription_status_checked_at' => now(),
        ])->save();
    }

    private function hasManualPlanOverride(User $user): bool
    {
        return Subscription::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('billing_payment_state', 'manual_override')
            ->exists();
    }

    public function syncActiveSubscribers(): int
    {
        $count = 0;

        User::query()
            ->whereNotNull('stripe_id')
            ->chunkById(100, function ($users) use (&$count) {
                foreach ($users as $user) {
                    try {
                        $this->syncUser($user);
                    } catch (\Throwable $exception) {
                        Log::warning('Scheduled billing sync failed for user.', [
                            'user_id' => $user->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }

                    $count++;
                }
            });

        return $count;
    }

    /**
     * The member's subscription that decides their plan, and the ids of any live
     * subscriptions Stripe charges for that no plan owns.
     *
     * @return array{0: ?object, 1: list<string>}
     */
    private function resolveRelevantStripeSubscription(string $stripeCustomerId): array
    {
        $subscriptions = Cashier::stripe()->subscriptions->all([
            'customer' => $stripeCustomerId,
            'status' => 'all',
            'limit' => 20,
            'expand' => ['data.items.data.price'],
        ]);

        $statusPriority = [
            'active' => 1,
            'trialing' => 2,
            'past_due' => 3,
            'unpaid' => 4,
            'incomplete' => 5,
            'incomplete_expired' => 6,
            'canceled' => 7,
        ];

        $all = collect($subscriptions->data ?? []);
        $unknownPaid = $all
            ->filter(fn ($sub) => in_array((string) ($sub->status ?? ''), ['active', 'trialing', 'past_due', 'unpaid'], true))
            ->filter(fn ($sub) => $this->mappedPlanSlugForSubscription($sub) === null)
            ->map(fn ($sub) => (string) $sub->id)
            ->values()
            ->all();

        $sorted = $all
            ->filter(fn ($sub) => $this->mappedPlanSlugForSubscription($sub) !== null)
            ->sort(function ($left, $right) use ($statusPriority) {
                $leftPriority = $statusPriority[(string) ($left->status ?? '')] ?? 99;
                $rightPriority = $statusPriority[(string) ($right->status ?? '')] ?? 99;

                if ($leftPriority !== $rightPriority) {
                    return $leftPriority <=> $rightPriority;
                }

                return (int) ($right->created ?? 0) <=> (int) ($left->created ?? 0);
            })
            ->values();

        return [$sorted->first(), $unknownPaid];
    }

    public function persistBillingSubscription(User $user, object $stripeSubscription): void
    {
        $stripeItems = collect($stripeSubscription->items->data ?? []);
        $primaryItem = $stripeItems->first();
        $primaryPrice = (string) ($primaryItem?->price?->id ?? '');

        $subscription = BillingSubscription::query()->updateOrCreate(
            ['stripe_id' => (string) $stripeSubscription->id],
            [
                'user_id' => $user->id,
                'type' => 'default',
                'stripe_id' => (string) $stripeSubscription->id,
                'stripe_status' => (string) ($stripeSubscription->status ?? 'incomplete'),
                'stripe_price' => $primaryPrice !== '' ? $primaryPrice : null,
                'quantity' => (int) ($primaryItem?->quantity ?? 1),
                'trial_ends_at' => $this->timestampToDateTime((int) ($stripeSubscription->trial_end ?? 0)),
                'ends_at' => $this->timestampToDateTime((int) ($stripeSubscription->ended_at ?? $stripeSubscription->cancel_at ?? 0)),
            ]
        );

        $seenItemIds = [];
        foreach ($stripeItems as $item) {
            $stripeItemId = (string) ($item->id ?? '');
            if ($stripeItemId === '') {
                continue;
            }

            $seenItemIds[] = $stripeItemId;

            $subscription->items()->updateOrCreate(
                ['stripe_id' => $stripeItemId],
                [
                    'stripe_product' => (string) ($item->price->product ?? ''),
                    'stripe_price' => (string) ($item->price->id ?? ''),
                    'quantity' => (int) ($item->quantity ?? 1),
                ]
            );
        }

        if (! empty($seenItemIds)) {
            $subscription->items()->whereNotIn('stripe_id', $seenItemIds)->delete();
        }
    }

    private function mappedPlanSlugForSubscription(object $stripeSubscription): ?string
    {
        foreach ($stripeSubscription->items->data ?? [] as $item) {
            $match = $this->stripePrices->planFor($item->price ?? null);
            if ($match !== null) {
                return (string) $match['plan']->slug;
            }
        }

        return null;
    }

    /**
     * @return array{pm_type:?string,pm_last_four:?string}
     */
    private function resolveCustomerDefaultPaymentMethod(mixed $paymentMethod): array
    {
        if (! is_string($paymentMethod) || trim($paymentMethod) === '') {
            return ['pm_type' => null, 'pm_last_four' => null];
        }

        try {
            $pm = Cashier::stripe()->paymentMethods->retrieve($paymentMethod, []);
        } catch (\Throwable) {
            return ['pm_type' => null, 'pm_last_four' => null];
        }

        $cardType = is_object($pm->card ?? null) ? (string) ($pm->card->brand ?? 'card') : 'card';
        $cardLast4 = is_object($pm->card ?? null) ? (string) ($pm->card->last4 ?? '') : '';

        return [
            'pm_type' => $cardType !== '' ? $cardType : null,
            'pm_last_four' => $cardLast4 !== '' ? $cardLast4 : null,
        ];
    }

    private function timestampToDateTime(int $timestamp): ?Carbon
    {
        if ($timestamp <= 0) {
            return null;
        }

        return Carbon::createFromTimestamp($timestamp);
    }

    private function isMissingStripeCustomerError(ApiErrorException $exception): bool
    {
        $httpStatus = (int) ($exception->getHttpStatus() ?? 0);
        $stripeCode = strtolower((string) ($exception->getStripeCode() ?? ''));
        $message = strtolower(trim((string) $exception->getMessage()));

        if ($httpStatus === 404 && str_contains($message, 'customer')) {
            return true;
        }

        return $stripeCode === 'resource_missing' && str_contains($message, 'customer');
    }

    private function resetBillingStateForMissingCustomer(User $user): void
    {
        BillingSubscription::query()
            ->where('user_id', $user->id)
            ->where('type', 'default')
            ->where('stripe_status', '!=', 'canceled')
            ->update([
                'stripe_status' => 'canceled',
                'ends_at' => now(),
            ]);
    }

    private function isDeletedStripeCustomer(mixed $customer): bool
    {
        if (! is_object($customer)) {
            return false;
        }

        return (bool) ($customer->deleted ?? false);
    }

    private function extractDefaultPaymentMethodIdFromCustomer(mixed $customer): mixed
    {
        if (! is_object($customer)) {
            return null;
        }

        $invoiceSettings = $customer->invoice_settings ?? null;
        if (! is_object($invoiceSettings)) {
            return null;
        }

        return $invoiceSettings->default_payment_method ?? null;
    }
}
