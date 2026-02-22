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

        $subscription = Subscription::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        if (! $subscription) {
            return Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'status' => $status,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
            ]);
        }

        $subscription->update([
            'plan_id' => $plan->id,
            'status' => $status,
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
        ]);

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
