<?php

namespace App\Services\Billing;

use App\Models\CharPackPurchase;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Paying for a pack by card: one charge in US dollars. The pack is added when
 * Stripe says the payment succeeded, whether the member's browser reports it
 * (confirm) or Stripe's webhook does; whichever comes first adds it, once.
 */
class CharPackCardPayment
{
    public const PURPOSE = 'char_pack';

    public function __construct(
        private readonly StripePackGateway $gateway,
        private readonly CharPackService $packs,
    ) {}

    /**
     * @return array{status:string, purchase:CharPackPurchase, client_secret?:?string, message?:string}
     */
    public function start(User $user, Plan $pack, string $paymentMethodId): array
    {
        $amount = max(0, (int) $pack->getEffectivePriceCents());
        $purchase = $this->packs->startPurchase($user, $pack, CharPackPurchase::METHOD_CARD, $amount, 'USD');

        try {
            $customerId = $this->gateway->ensureCustomer($user);
            $intent = $this->gateway->chargeCard(
                $customerId,
                $paymentMethodId,
                $amount,
                'usd',
                $pack->getLocalizedName('en').' ('.number_format((int) $pack->monthly_chars).' characters)',
                [
                    'listen_purpose' => self::PURPOSE,
                    'listen_user_id' => (string) $user->id,
                    'listen_purchase_id' => (string) $purchase->id,
                    'listen_plan_slug' => (string) $pack->slug,
                ],
                'listen-pack-'.$purchase->id,
            );
        } catch (\Throwable $exception) {
            Log::warning('Pack card payment could not start.', [
                'user_id' => $user->id,
                'purchase_id' => $purchase->id,
                'error' => $exception->getMessage(),
            ]);
            $this->packs->markFailed($purchase, $exception->getMessage());

            return ['status' => 'failed', 'purchase' => $purchase->fresh(), 'message' => __('ui.pack.card_failed')];
        }

        if ($intent['id'] !== '') {
            $purchase->forceFill(['stripe_payment_intent_id' => $intent['id']])->save();
        }

        return $this->settle($purchase, $intent);
    }

    /**
     * The browser finished the bank's check: look the payment up and settle it.
     *
     * @return array{status:string, purchase:CharPackPurchase, client_secret?:?string, message?:string}
     */
    public function confirm(CharPackPurchase $purchase): array
    {
        if ($purchase->status === CharPackPurchase::STATUS_PAID) {
            return ['status' => 'paid', 'purchase' => $purchase];
        }
        if (! $purchase->stripe_payment_intent_id || $purchase->status !== CharPackPurchase::STATUS_PENDING) {
            return ['status' => 'failed', 'purchase' => $purchase, 'message' => __('ui.pack.card_failed')];
        }

        return $this->settle($purchase, $this->gateway->retrievePaymentIntent($purchase->stripe_payment_intent_id));
    }

    /**
     * Stripe webhook: payment_intent.succeeded.
     */
    public function handlePaymentSucceeded(object $intent): void
    {
        if ((string) ($intent->metadata->listen_purpose ?? '') !== self::PURPOSE) {
            return;
        }

        $purchase = $this->purchaseForIntent((string) ($intent->id ?? ''), (string) ($intent->metadata->listen_purchase_id ?? ''));
        if (! $purchase) {
            Log::warning('Pack payment succeeded but no purchase matches it.', ['payment_intent' => $intent->id ?? null]);

            return;
        }

        if ((int) ($intent->amount_received ?? $intent->amount ?? 0) < (int) $purchase->amount_minor) {
            Log::warning('Pack payment amount is lower than the price; not added.', ['purchase_id' => $purchase->id]);

            return;
        }

        $this->packs->markPaid($purchase);
    }

    /**
     * Stripe webhook: charge.refunded. A full refund takes back what is unused.
     */
    public function handleChargeRefunded(object $charge): void
    {
        $intentId = (string) ($charge->payment_intent ?? '');
        if ($intentId === '' || ! ($charge->refunded ?? false)) {
            return;
        }

        $purchase = CharPackPurchase::query()->where('stripe_payment_intent_id', $intentId)->first();
        if ($purchase) {
            $this->packs->refund($purchase);
        }
    }

    /**
     * @param  array{id:string,status:string,client_secret:?string,amount:int,currency:string,customer:?string,metadata:array<string, string>,error:?string}  $intent
     * @return array{status:string, purchase:CharPackPurchase, client_secret?:?string, message?:string}
     */
    private function settle(CharPackPurchase $purchase, array $intent): array
    {
        $belongsHere = ($intent['metadata']['listen_purchase_id'] ?? null) === (string) $purchase->id
            || ($purchase->stripe_payment_intent_id && $intent['id'] === $purchase->stripe_payment_intent_id);

        if ($intent['status'] === 'succeeded' && $belongsHere && $intent['amount'] >= (int) $purchase->amount_minor) {
            return ['status' => 'paid', 'purchase' => $this->packs->markPaid($purchase)];
        }

        if (in_array($intent['status'], ['requires_action', 'requires_confirmation'], true) && $intent['client_secret']) {
            return ['status' => 'requires_action', 'purchase' => $purchase, 'client_secret' => $intent['client_secret']];
        }

        if ($intent['status'] === 'processing') {
            // Settles through the webhook.
            return ['status' => 'processing', 'purchase' => $purchase, 'message' => __('ui.pack.card_processing')];
        }

        $this->packs->markFailed($purchase, (string) ($intent['error'] ?? $intent['status']));

        return [
            'status' => 'failed',
            'purchase' => $purchase->fresh(),
            'message' => $intent['error'] ? __('ui.pack.card_declined', ['reason' => $intent['error']]) : __('ui.pack.card_failed'),
        ];
    }

    private function purchaseForIntent(string $intentId, string $purchaseId): ?CharPackPurchase
    {
        if ($intentId !== '') {
            $purchase = CharPackPurchase::query()->where('stripe_payment_intent_id', $intentId)->first();
            if ($purchase) {
                return $purchase;
            }
        }

        if (ctype_digit($purchaseId)) {
            $purchase = CharPackPurchase::query()->whereKey((int) $purchaseId)->first();
            if ($purchase && ! $purchase->stripe_payment_intent_id && $intentId !== '') {
                $purchase->forceFill(['stripe_payment_intent_id' => $intentId])->save();
            }

            return $purchase;
        }

        return null;
    }
}
