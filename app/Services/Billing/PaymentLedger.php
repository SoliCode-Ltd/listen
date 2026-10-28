<?php

namespace App\Services\Billing;

use App\Models\ManualPaymentRequest;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;

/**
 * Keeps every payment a member made and how they paid (App\Models\Payment):
 * - card, Apple Pay, Google Pay: each paid Stripe charge of a member's Stripe
 *   customer, read when Stripe says an invoice is paid or a charge refunded, and
 *   when a pack is paid by card;
 * - InstaPay / Vodafone Cash: when the team approves the payment.
 * The Stripe account is shared with other businesses: only charges of a Stripe
 * customer one of our members has are kept.
 */
class PaymentLedger
{
    /** Stripe's charges list goes back to before the first member at most. */
    private const IMPORT_MAX_CHARGES = 5000;

    public function handleStripeEvent(object $event): void
    {
        $type = (string) ($event->type ?? '');
        $object = $event->data->object ?? null;
        if (! is_object($object) || ! in_array($type, ['invoice.paid', 'invoice.payment_succeeded', 'payment_intent.succeeded', 'charge.refunded'], true)) {
            return;
        }
        // An invoice with nothing to pay (a trial's start) has no payment.
        if (str_starts_with($type, 'invoice.') && (int) ($object->amount_paid ?? 0) <= 0) {
            return;
        }

        $this->syncStripeCustomer((string) ($object->customer ?? ''));
    }

    /** The member's latest Stripe charges: new ones are kept, refunds of kept ones noted. */
    public function syncStripeCustomer(string $customerId, int $limit = 20): int
    {
        $customerId = trim($customerId);
        $userId = $customerId !== '' && self::stripeReady()
            ? User::query()->where('stripe_id', $customerId)->value('id')
            : null;
        if (! $userId) {
            return 0;
        }

        try {
            $kept = 0;
            foreach (Cashier::stripe()->charges->all(['customer' => $customerId, 'limit' => $limit])->data as $charge) {
                $kept += $this->keepCharge($charge, (int) $userId) ? 1 : 0;
            }

            return $kept;
        } catch (\Throwable $exception) {
            // The list is a record: a payment never fails because of it.
            Log::warning('Could not read a member\'s Stripe payments.', ['customer' => $customerId, 'error' => $exception->getMessage()]);

            return 0;
        }
    }

    /**
     * Every paid charge of our members on the Stripe account, and every approved
     * payment by hand: the payments made before this list began.
     *
     * @return array{stripe:int, manual:int}
     */
    public function importAll(): array
    {
        $manual = 0;
        ManualPaymentRequest::query()->where('status', ManualPaymentRequest::STATUS_APPROVED)->orderBy('id')
            ->each(function (ManualPaymentRequest $request) use (&$manual): void {
                $manual += $this->recordManualPayment($request)?->wasRecentlyCreated ? 1 : 0;
            });

        return ['stripe' => $this->importStripe(), 'manual' => $manual];
    }

    public function recordManualPayment(ManualPaymentRequest $request): ?Payment
    {
        if ($request->status !== ManualPaymentRequest::STATUS_APPROVED || (int) $request->expected_amount_minor <= 0) {
            return null;
        }

        return $this->keep('manual:'.$request->id, [
            'user_id' => $request->user_id,
            'method' => (string) $request->payment_method ?: Payment::METHOD_INSTAPAY,
            'card_brand' => null,
            'card_last4' => null,
            'card_country' => null,
            'amount_minor' => (int) $request->expected_amount_minor,
            'refunded_minor' => 0,
            'currency' => strtoupper((string) $request->currency ?: 'EGP'),
            'description' => $request->plan?->name,
            'paid_at' => $request->reviewed_at ?? $request->submitted_at ?? now(),
        ]);
    }

    private function importStripe(): int
    {
        if (! self::stripeReady()) {
            return 0;
        }

        $members = User::query()->whereNotNull('stripe_id')->where('stripe_id', '!=', '')->pluck('id', 'stripe_id');
        if ($members->isEmpty()) {
            return 0;
        }
        $since = User::query()->whereNotNull('stripe_id')->where('stripe_id', '!=', '')->min('created_at');

        $kept = 0;
        $seen = 0;
        $params = ['limit' => 100] + ($since ? ['created' => ['gte' => Carbon::parse($since)->subDay()->timestamp]] : []);
        foreach (Cashier::stripe()->charges->all($params)->autoPagingIterator() as $charge) {
            if (++$seen > self::IMPORT_MAX_CHARGES) {
                break;
            }
            $userId = $members[(string) ($charge->customer ?? '')] ?? null;
            if ($userId !== null) {
                $kept += $this->keepCharge($charge, (int) $userId) ? 1 : 0;
            }
        }

        return $kept;
    }

    /** True when the charge was not kept before. */
    private function keepCharge(object $charge, int $userId): bool
    {
        $id = trim((string) ($charge->id ?? ''));
        if ($id === '' || ($charge->status ?? '') !== 'succeeded' || ! ($charge->paid ?? false)) {
            return false;
        }

        $details = $charge->payment_method_details ?? null;
        $type = (string) ($details->type ?? '');
        $card = $type === 'card' ? ($details->card ?? null) : null;
        // A phone wallet pays with a card too: the wallet is how the member paid.
        $wallet = (string) ($card->wallet->type ?? '');
        $method = $wallet !== '' ? $wallet : ($type !== '' ? $type : Payment::METHOD_CARD);

        $payment = $this->keep('charge:'.$id, [
            'user_id' => $userId,
            'method' => $method,
            'card_brand' => isset($card->brand) ? (string) $card->brand : null,
            'card_last4' => isset($card->last4) ? (string) $card->last4 : null,
            'card_country' => isset($card->country) ? strtoupper((string) $card->country) : null,
            'amount_minor' => (int) ($charge->amount ?? 0),
            'refunded_minor' => (int) ($charge->amount_refunded ?? 0),
            'currency' => strtoupper((string) ($charge->currency ?? 'usd')),
            'description' => isset($charge->description) ? mb_substr((string) $charge->description, 0, 255) : null,
            'paid_at' => Carbon::createFromTimestamp((int) ($charge->created ?? time())),
        ]);

        return (bool) $payment?->wasRecentlyCreated;
    }

    /** @param  array<string, mixed>  $values */
    private function keep(string $reference, array $values): ?Payment
    {
        try {
            return Payment::query()->updateOrCreate(['reference' => $reference], $values);
        } catch (\Throwable $exception) {
            Log::warning('Could not keep a payment.', ['reference' => $reference, 'error' => $exception->getMessage()]);

            return null;
        }
    }

    private static function stripeReady(): bool
    {
        return trim((string) config('cashier.secret', '')) !== '';
    }
}
