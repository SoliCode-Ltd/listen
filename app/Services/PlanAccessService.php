<?php

namespace App\Services;

use App\Models\BillingTrial;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\TrialEligibilityService;
use App\Support\VoiceTier;

class PlanAccessService
{
    public function resolveActivePlanForUserId(int $userId): ?Plan
    {
        $subscription = Subscription::query()
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('current_period_end')
                    ->orWhere('current_period_end', '>=', now());
            })
            ->latest('id')
            ->with('plan')
            ->first();

        if ($subscription?->plan) {
            return $subscription->plan;
        }

        return $this->freePlan();
    }

    /**
     * Feature access stays on the active plan. During an unpaid Creator trial,
     * character and minute limits come from the Starter plan and overage is off.
     * The returned model is not persisted.
     */
    public function resolveQuotaPlanForUserId(int $userId, ?Plan $activePlan = null): ?Plan
    {
        $activePlan ??= $this->resolveActivePlanForUserId($userId);
        if (! $this->userHasUnpaidTrialWindow($userId)) {
            return $activePlan;
        }

        $openTrial = $this->unpaidTrialWindowQuery($userId)
            ->latest('id')
            ->first();
        $starter = $openTrial?->quotaPlan
            ?: Plan::query()->where('slug', app(TrialEligibilityService::class)->quotaPlanSlug())->first();
        if (! $starter) {
            return $activePlan;
        }

        $quota = $starter->replicate();
        $quota->allow_overage = false;
        $quota->setAttribute('enforce_trial_minute_cap', true);

        return $quota;
    }

    public function userHasUnpaidTrialWindow(int $userId): bool
    {
        return $this->unpaidTrialWindowQuery($userId)->exists();
    }

    /**
     * Stripe can mark the subscription active up to about an hour before the first
     * renewal invoice is actually charged, so a trial that has not been paid keeps
     * its cap for a short while after trial_ends_at.
     *
     * @return \Illuminate\Database\Eloquent\Builder<BillingTrial>
     */
    private function unpaidTrialWindowQuery(int $userId): \Illuminate\Database\Eloquent\Builder
    {
        return BillingTrial::query()
            ->where('user_id', $userId)
            ->whereNull('paid_at')
            ->where(function ($query) {
                $query->where(function ($trialing) {
                    $trialing->where('status', BillingTrial::STATUS_TRIALING)
                        ->where('trial_ends_at', '>', now()->subDays(3));
                })->orWhere(function ($canceled) {
                    $canceled->where('status', BillingTrial::STATUS_CANCELED)
                        ->where('trial_ends_at', '>', now());
                });
            });
    }

    public function ensureFreePlanForUser(User $user): void
    {
        $hasActive = Subscription::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('current_period_end')
                    ->orWhere('current_period_end', '>=', now());
            })
            ->exists();

        if ($hasActive) {
            return;
        }

        $free = $this->freePlan();
        if (! $free) {
            return;
        }

        $this->assignPlan($user, $free);
    }

    public function assignPlan(User $user, Plan $plan, string $status = 'active', ?\DateTimeInterface $periodEnd = null): Subscription
    {
        $periodStart = now();
        $periodEnd = $periodEnd ?: now()->addMonth();

        // Capture the transition signal BEFORE the subscription row is mutated
        // so we can detect a free->paid upgrade reliably.
        $isFirstPaid = false;
        if ((int) $plan->price_cents > 0 && ! $this->userHasUnpaidTrialWindow((int) $user->id)) {
            try {
                $isFirstPaid = app(\App\Services\Referral\ReferralQualificationService::class)
                    ->isFirstPaidPlanTransition($user, $plan);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Referral: first-paid detection failed.', [
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $subscription = Subscription::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        if (! $subscription) {
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'status' => $status,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
            ]);
        } else {
            $subscription->update([
                'plan_id' => $plan->id,
                'status' => $status,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
            ]);
        }

        if ($isFirstPaid) {
            try {
                app(\App\Services\Referral\ReferralQualificationService::class)
                    ->evaluate($user, 'paid_plan');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Referral qualification evaluation failed on paid-plan assignment.', [
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $subscription;
    }

    public function resolveTierForPlan(?Plan $plan): string
    {
        return VoiceTier::normalize((string) ($plan?->voice_tier ?: VoiceTier::STANDARD));
    }

    public function freePlan(): ?Plan
    {
        return Plan::query()->where('slug', 'free')->first()
            ?? Plan::query()->where('is_active', true)->orderBy('price_cents')->first();
    }
}
