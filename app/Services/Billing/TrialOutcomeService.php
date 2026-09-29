<?php

namespace App\Services\Billing;

use App\Models\BillingTrial;
use App\Models\User;
use App\Services\Referral\ReferralQualificationService;
use Illuminate\Support\Facades\Log;

class TrialOutcomeService
{
    public function __construct(
        private readonly ReferralQualificationService $referrals,
    ) {}

    public function handleStripeEvent(object $event): void
    {
        $type = (string) ($event->type ?? '');
        $object = $event->data->object ?? null;
        if (! is_object($object)) {
            return;
        }

        match ($type) {
            'invoice.paid', 'invoice.payment_succeeded' => $this->handleInvoicePaid($object),
            'invoice.payment_failed' => $this->handleInvoiceFailed($object),
            'customer.subscription.updated' => $this->handleSubscriptionUpdated($object),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($object),
            default => null,
        };
    }

    private function handleInvoicePaid(object $invoice): void
    {
        $amountPaid = (int) ($invoice->amount_paid ?? 0);
        if ($amountPaid <= 0) {
            return;
        }

        $trial = $this->trialForSubscription($this->subscriptionId($invoice));
        if (! $trial || $trial->status === BillingTrial::STATUS_PAID) {
            return;
        }

        $trial->forceFill([
            'status' => BillingTrial::STATUS_PAID,
            'paid_at' => $trial->paid_at ?? now(),
            'amount_paid_cents' => $amountPaid,
            'stripe_invoice_id' => trim((string) ($invoice->id ?? '')) ?: $trial->stripe_invoice_id,
            'canceled_at' => null,
        ])->save();

        $this->qualifyReferral($trial);
    }

    private function handleInvoiceFailed(object $invoice): void
    {
        $trial = $this->trialForSubscription($this->subscriptionId($invoice));
        if (! $trial || $trial->status === BillingTrial::STATUS_PAID) {
            return;
        }

        $trial->forceFill([
            'status' => BillingTrial::STATUS_CHARGE_FAILED,
            'charge_failed_at' => $trial->charge_failed_at ?? now(),
            'stripe_invoice_id' => trim((string) ($invoice->id ?? '')) ?: $trial->stripe_invoice_id,
        ])->save();
    }

    private function handleSubscriptionUpdated(object $subscription): void
    {
        $trial = $this->trialForSubscription((string) ($subscription->id ?? ''));
        if (! $trial || $trial->status === BillingTrial::STATUS_PAID) {
            return;
        }

        $status = strtolower((string) ($subscription->status ?? ''));
        $cancelAtPeriodEnd = (bool) ($subscription->cancel_at_period_end ?? false);

        if ($status === 'active') {
            // A trial can flip to active while the renewal invoice is still an unpaid draft.
            // Paid is recorded only from invoice.paid with a positive amount.
            return;
        }

        if (in_array($status, ['past_due', 'unpaid', 'incomplete'], true)) {
            $trial->forceFill([
                'status' => BillingTrial::STATUS_CHARGE_FAILED,
                'charge_failed_at' => $trial->charge_failed_at ?? now(),
            ])->save();

            return;
        }

        if ($status === 'trialing' && $cancelAtPeriodEnd) {
            $trial->forceFill([
                'status' => BillingTrial::STATUS_CANCELED,
                'canceled_at' => $trial->canceled_at ?? now(),
            ])->save();

            return;
        }

        if (in_array($status, ['canceled', 'incomplete_expired'], true)) {
            $this->markClosed($trial, $trial->charge_failed_at ? BillingTrial::STATUS_CHARGE_FAILED : BillingTrial::STATUS_CANCELED);
        }
    }

    private function handleSubscriptionDeleted(object $subscription): void
    {
        $trial = $this->trialForSubscription((string) ($subscription->id ?? ''));
        if (! $trial || $trial->status === BillingTrial::STATUS_PAID) {
            return;
        }

        $this->markClosed($trial, $trial->charge_failed_at ? BillingTrial::STATUS_CHARGE_FAILED : BillingTrial::STATUS_CANCELED);
    }

    private function markClosed(BillingTrial $trial, string $status): void
    {
        $trial->forceFill([
            'status' => $status,
            'canceled_at' => $trial->canceled_at ?? now(),
        ])->save();
    }

    private function trialForSubscription(string $subscriptionId): ?BillingTrial
    {
        $subscriptionId = trim($subscriptionId);
        if ($subscriptionId === '') {
            return null;
        }

        return BillingTrial::query()
            ->where('stripe_subscription_id', $subscriptionId)
            ->latest('id')
            ->first();
    }

    private function subscriptionId(object $invoice): string
    {
        $subscription = $this->invoiceValue($invoice, 'subscription');
        $fromTopLevel = $this->idFromMixed($subscription);
        if ($fromTopLevel !== '') {
            return $fromTopLevel;
        }

        $parent = $this->invoiceValue($invoice, 'parent');
        if (! is_object($parent)) {
            return '';
        }

        $details = $this->invoiceValue($parent, 'subscription_details');
        if (! is_object($details)) {
            return '';
        }

        return $this->idFromMixed($this->invoiceValue($details, 'subscription'));
    }

    private function invoiceValue(object $object, string $key): mixed
    {
        if ($object instanceof \ArrayAccess && $object->offsetExists($key)) {
            return $object->offsetGet($key);
        }

        return $object->{$key} ?? null;
    }

    private function idFromMixed(mixed $value): string
    {
        if (is_object($value)) {
            return trim((string) ($value->id ?? ''));
        }

        return trim((string) $value);
    }

    private function qualifyReferral(BillingTrial $trial): void
    {
        $user = $trial->user ?: User::query()->find($trial->user_id);
        if (! $user) {
            return;
        }

        try {
            $this->referrals->evaluate($user, 'paid_plan');
        } catch (\Throwable $exception) {
            Log::warning('Referral qualification failed after a Creator trial converted to paid.', [
                'user_id' => $user->id,
                'billing_trial_id' => $trial->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
