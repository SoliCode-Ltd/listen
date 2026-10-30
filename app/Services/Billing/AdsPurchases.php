<?php

namespace App\Services\Billing;

use App\Http\Middleware\CaptureAdClickId;
use App\Models\AdsPurchase;
use App\Models\BillingTrial;
use App\Models\ManualPaymentRequest;
use App\Models\User;
use App\Support\StripeInvoice;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the purchases that came from a Google Ads click, once they are really
 * paid, for the "Conversions from clicks" upload:
 * - a payment by hand (InstaPay / Vodafone Cash) when the team approves it; the
 *   page never reports it, as the proof can still be refused;
 * - a card payment for a new plan or a plan change when Stripe says its invoice
 *   is paid, also when the bank's check or a phone wallet paid it, or the
 *   member's browser blocked Google's tag. The page reports these too, so they
 *   go to a conversion action of their own (kept secondary in Google Ads).
 * A trial's payments are left to the trial upload (TrialAdsConversionExport).
 */
class AdsPurchases
{
    /** Google Ads takes a click's conversions for 90 days. */
    public const CLICK_DAYS = 90;

    /** The click the member's browser came from, kept on their account for the payment to come. */
    public function rememberClick(User $user, ?Request $request): void
    {
        $gclid = CaptureAdClickId::fromRequest($request);
        if ($gclid === null || $gclid === $user->ads_click_id) {
            return;
        }

        $user->forceFill(['ads_click_id' => $gclid, 'ads_click_at' => now()])->save();
    }

    public function handleStripeEvent(object $event): void
    {
        $type = (string) ($event->type ?? '');
        $invoice = $event->data->object ?? null;
        if (! in_array($type, ['invoice.paid', 'invoice.payment_succeeded'], true) || ! is_object($invoice)) {
            return;
        }

        $this->recordPaidInvoice($invoice);
    }

    public function recordPaidInvoice(object $invoice): ?AdsPurchase
    {
        $invoiceId = trim((string) ($invoice->id ?? ''));
        $amountPaid = (int) ($invoice->amount_paid ?? 0);
        // A new plan's first payment, or what a plan change charged; never a renewal.
        if ($invoiceId === '' || $amountPaid <= 0
            || ! in_array(StripeInvoice::billingReason($invoice), ['subscription_create', 'subscription_update'], true)) {
            return null;
        }

        $subscriptionId = StripeInvoice::subscriptionId($invoice);
        if ($subscriptionId !== '' && BillingTrial::query()->where('stripe_subscription_id', $subscriptionId)->exists()) {
            return null;
        }

        $customerId = trim((string) ($invoice->customer ?? ''));
        $user = $customerId !== '' ? User::query()->where('stripe_id', $customerId)->first() : null;
        $gclid = $user ? $this->recentClick($user) : null;
        if (! $user || $gclid === null) {
            return null;
        }

        $paidAt = (int) ($invoice->status_transitions->paid_at ?? 0);

        return $this->keep($user, AdsPurchase::SOURCE_CARD, 'invoice:'.$invoiceId, $gclid, $amountPaid, (string) ($invoice->currency ?? 'usd'),
            $paidAt > 0 ? Carbon::createFromTimestamp($paidAt) : null);
    }

    public function recordManualPayment(ManualPaymentRequest $request): ?AdsPurchase
    {
        if ($request->status !== ManualPaymentRequest::STATUS_APPROVED || ! $request->user) {
            return null;
        }

        $gclid = trim((string) $request->gclid) ?: $this->recentClick($request->user);
        if ($gclid === null || (int) $request->expected_amount_minor <= 0) {
            return null;
        }

        return $this->keep($request->user, AdsPurchase::SOURCE_MANUAL, 'manual:'.$request->id, $gclid, (int) $request->expected_amount_minor, (string) $request->currency,
            $request->reviewed_at);
    }

    private function recentClick(User $user): ?string
    {
        $gclid = trim((string) $user->ads_click_id);
        $at = $user->ads_click_at;

        return $gclid !== '' && $at !== null && $at->greaterThanOrEqualTo(now()->subDays(self::CLICK_DAYS)) ? $gclid : null;
    }

    private function keep(User $user, string $source, string $reference, string $gclid, int $valueMinor, string $currency, ?CarbonInterface $at = null): ?AdsPurchase
    {
        try {
            return AdsPurchase::query()->firstOrCreate(['reference' => $reference], [
                'user_id' => $user->id,
                'source' => $source,
                'gclid' => $gclid,
                'value_minor' => $valueMinor,
                'currency' => strtoupper($currency ?: 'USD'),
                'purchased_at' => $at ?? now(),
            ]);
        } catch (\Throwable $exception) {
            // The upload is a report: a payment never fails because of it.
            Log::warning('Could not keep a purchase for the Google Ads upload.', ['reference' => $reference, 'error' => $exception->getMessage()]);

            return null;
        }
    }
}
