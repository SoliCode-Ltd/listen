<?php

namespace App\Services\Billing;

use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionCancellation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Keeps Billing → Cancellations complete: one row per cancellation of a Stripe subscription,
 * whether the member cancelled in the cancel dialog, in Stripe, or before the dialog asked why
 * (cancellations:import). Rows are matched on the Stripe subscription, so a cancellation told
 * by both the site and Stripe is counted once; turning renewal back on marks it resumed.
 */
class CancellationLog
{
    /** Stripe ended these itself (a payment that failed or was disputed): not a cancellation. */
    public const NOT_CANCELLATIONS = ['payment_failed', 'payment_disputed'];

    /**
     * The site is ending this subscription itself (a renewal left unpaid past its grace days,
     * a free trial an admin or a blocked account ends, an account being deleted, an
     * unfinished checkout replaced). Stripe calls each of those "cancellation requested",
     * the same as a member cancelling, so the notice it sends next must not count it.
     */
    public static function siteEnds(string $stripeSubscriptionId): void
    {
        $id = trim($stripeSubscriptionId);
        if ($id !== '') {
            rescue(fn () => Cache::put(self::siteEndsKey($id), true, now()->addDays(3)), null, false);
        }
    }

    public static function siteEnded(string $stripeSubscriptionId): bool
    {
        $id = trim($stripeSubscriptionId);

        return $id !== '' && (bool) rescue(fn () => Cache::get(self::siteEndsKey($id)), false, false);
    }

    private static function siteEndsKey(string $id): string
    {
        return 'cancellations:site-ends:'.$id;
    }

    /**
     * Whether a subscription that ended at once (not at the end of its period) was ended by
     * the site rather than by someone cancelling: its last invoice was never paid (a renewal
     * left unpaid, an unfinished checkout), or it was a free trial the site ended.
     * $latestInvoice is the expanded invoice when Stripe sent one.
     */
    public function siteEndedAtOnce(string $stripeSubscriptionId, mixed $latestInvoice): bool
    {
        if (self::siteEnded($stripeSubscriptionId)) {
            return true;
        }
        if (is_object($latestInvoice) && strtolower((string) ($latestInvoice->status ?? '')) !== 'paid') {
            return true;
        }

        return BillingTrial::query()
            ->where('stripe_subscription_id', $stripeSubscriptionId)
            ->whereIn('status', [BillingTrial::STATUS_CANCELED, BillingTrial::STATUS_BLOCKED])
            ->exists();
    }

    /**
     * Rows the cancel dialog saved before they carried the Stripe subscription: given the
     * member's subscription that is set to end (else their latest), so a later notice from
     * Stripe, the import or Keep my plan finds them. Returns how many were filled in.
     */
    public static function fillMissingSubscriptionIds(): int
    {
        $filled = 0;
        $rows = DB::table('subscription_cancellations')->whereNull('stripe_subscription_id')->get(['id', 'user_id']);
        foreach ($rows as $row) {
            $subscriptions = DB::table('billing_subscriptions')
                ->where('user_id', $row->user_id)
                ->whereNotNull('stripe_id')
                ->where('stripe_id', '!=', '');
            $stripeId = (clone $subscriptions)->whereNotNull('ends_at')->orderByDesc('id')->value('stripe_id')
                ?: $subscriptions->orderByDesc('id')->value('stripe_id');
            if ($stripeId) {
                DB::table('subscription_cancellations')->where('id', $row->id)->update(['stripe_subscription_id' => $stripeId]);
                $filled++;
            }
        }

        return $filled;
    }

    /** The cancellation of this subscription that still stands, if any. */
    public function open(string $stripeSubscriptionId): ?SubscriptionCancellation
    {
        if ($stripeSubscriptionId === '') {
            return null;
        }

        return SubscriptionCancellation::query()
            ->where('stripe_subscription_id', $stripeSubscriptionId)
            ->whereNull('resumed_at')
            // One the cancel dialog saved a moment ago is still on its way to Stripe (marked
            // cancelled once Stripe has it), and Stripe's own notice can arrive first.
            ->where(fn ($q) => $q->whereNotNull('cancelled_at')->orWhere('created_at', '>=', now()->subMinutes(10)))
            ->latest('id')
            ->first();
    }

    /**
     * A cancellation Stripe tells about (made in Stripe, or the site's own arriving first):
     * recorded unless one for this subscription already stands. The reason is the one
     * Stripe's survey recorded, if any; the note is what the member wrote there.
     */
    public function recordFromStripe(User $user, object $stripeSubscription, string $source = SubscriptionCancellation::SOURCE_STRIPE): ?SubscriptionCancellation
    {
        $id = trim((string) ($stripeSubscription->id ?? ''));
        $details = $stripeSubscription->cancellation_details ?? null;
        if ($id === '' || in_array((string) ($details->reason ?? ''), self::NOT_CANCELLATIONS, true)) {
            return null;
        }
        if ($this->open($id)) {
            return null;
        }

        $cancelledAt = (int) ($stripeSubscription->canceled_at ?? 0);
        $endsAt = (int) ($stripeSubscription->cancel_at ?? 0)
            ?: (int) ($stripeSubscription->ended_at ?? 0)
            ?: (int) ($stripeSubscription->current_period_end ?? 0)
            ?: (int) ($stripeSubscription->items->data[0]->current_period_end ?? 0);
        $comment = trim((string) ($details->comment ?? ''));
        $plan = $this->planFor($user, $id);

        return SubscriptionCancellation::create([
            'user_id' => $user->id,
            'plan_id' => $plan?->id,
            'plan_name' => $plan?->name,
            'stripe_subscription_id' => $id,
            'source' => $source,
            'reason' => SubscriptionCancellation::reasonFromStripeFeedback(isset($details->feedback) ? (string) $details->feedback : null),
            'note' => $comment !== '' ? mb_substr($comment, 0, SubscriptionCancellation::NOTE_MAX) : null,
            'ended_now' => false,
            'cancelled_at' => $cancelledAt > 0 ? Carbon::createFromTimestamp($cancelledAt) : now(),
            'ends_at' => $endsAt > 0 ? Carbon::createFromTimestamp($endsAt) : null,
        ]);
    }

    /**
     * What Stripe's subscription events say about cancellations: one scheduled, one taken
     * back, or one that ended at once on request. A subscription Stripe ended itself (a
     * failed payment) is not a cancellation.
     */
    public function fromStripeEvent(string $type, object $subscription, bool $cancellationChanged, ?User $user): void
    {
        if ($user === null) {
            return;
        }
        $id = trim((string) ($subscription->id ?? ''));
        $scheduled = (bool) ($subscription->cancel_at_period_end ?? false) || ! empty($subscription->cancel_at);

        if ($type === 'customer.subscription.updated' && $cancellationChanged) {
            $scheduled ? $this->recordFromStripe($user, $subscription) : $this->markResumed($id);
        }
        if ($type === 'customer.subscription.deleted'
            && (string) ($subscription->cancellation_details->reason ?? '') === 'cancellation_requested'
            // Set to end at its period's end, then ended: a cancellation (already counted, unless
            // it was made somewhere that sent no notice). Ended at once: only when the site did
            // not end it itself.
            && ($scheduled || ! $this->siteEndedAtOnce($id, $subscription->latest_invoice ?? null))) {
            $this->recordFromStripe($user, $subscription);
        }
    }

    /** The member turned renewal back on: the cancellation no longer counts. */
    public function markResumed(string $stripeSubscriptionId): void
    {
        $this->open($stripeSubscriptionId)?->update(['resumed_at' => now()]);
    }

    /** The plan this subscription is for: by its Stripe price, else the member's latest plan. */
    public function planFor(User $user, string $stripeSubscriptionId): ?Plan
    {
        $price = (string) BillingSubscription::query()->where('stripe_id', $stripeSubscriptionId)->value('stripe_price');
        if ($price !== '') {
            $plan = Plan::query()->where('stripe_price_monthly', $price)->orWhere('stripe_price_yearly', $price)->first();
            if ($plan) {
                return $plan;
            }
        }

        return Subscription::query()->with('plan')->where('user_id', $user->id)->latest('id')->first()?->plan;
    }
}
