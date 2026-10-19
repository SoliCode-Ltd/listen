<?php

namespace App\Support;

use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\CharPackPurchase;
use App\Models\ManualPaymentRequest;
use App\Models\User;
use App\Services\Billing\CharPackService;
use App\Services\PlanAccessService;

/**
 * A member who pays now or has paid before. Automatic abuse rules never block
 * them by themselves: wrongly blocking a paying customer costs far more than
 * the abuse it would stop, so their cases are left to an admin.
 */
final class PaidMember
{
    public static function is(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $userId = (int) $user->id;

        $plan = app(PlanAccessService::class)->resolveActivePlanForUserId($userId);
        if ($plan && ((int) $plan->price_cents > 0 || $plan->isPack())) {
            return true;
        }

        if (app(CharPackService::class)->activePackPlan($userId) !== null) {
            return true;
        }

        return CharPackPurchase::query()->where('user_id', $userId)->where('status', CharPackPurchase::STATUS_PAID)->exists()
            || ManualPaymentRequest::query()->where('user_id', $userId)->where('status', ManualPaymentRequest::STATUS_APPROVED)->exists()
            || BillingTrial::query()->where('user_id', $userId)->whereNotNull('paid_at')->exists()
            // A card subscription that is live, or that ended after being paid (a
            // cancelled one without a trial was charged at least once).
            || BillingSubscription::query()
                ->where('user_id', $userId)
                ->where(function ($query): void {
                    $query->whereIn('stripe_status', ['active', 'past_due', 'unpaid'])
                        ->orWhere(fn ($cancelled) => $cancelled->where('stripe_status', 'canceled')->whereNull('trial_ends_at'));
                })
                ->exists();
    }
}
