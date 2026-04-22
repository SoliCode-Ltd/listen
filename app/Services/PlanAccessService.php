<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
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
        if ((int) $plan->price_cents > 0) {
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
