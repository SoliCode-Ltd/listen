<?php

namespace App\Services\Billing;

use App\Models\CharPackPurchase;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Paying by card for a one-time purchase: a character pack, or extra credits
 * (ExtraCredits). One charge in US dollars, never a subscription. What was
 * bought is added when Stripe says the payment succeeded, whether the member's
 * browser reports it (confirm) or Stripe's webhook does; whichever comes first
 * adds it, once.
 */
class CharPackCardPayment
{
    public const PURPOSE = 'char_pack';

    public function __construct(
        private readonly StripePackGateway $gateway,
        private readonly CharPackService $packs,
        private readonly ExtraCredits $extraCredits,
    ) {}

    /**
     * @return array{status:string, purchase:CharPackPurchase, client_secret?:?string, message?:string, reason?:string}
     */
    public function start(User $user, Plan $pack, string $paymentMethodId): array
    {
        $amount = max(0, (int) $pack->getEffectivePriceCents());
        $purchase = $this->packs->startPurchase($user, $pack, CharPackPurchase::METHOD_CARD, $amount, 'USD');

        return $this->charge($user, $purchase, $paymentMethodId,
            $pack->getLocalizedName('en').' ('.number_format((int) $pack->monthly_chars).' characters)',
            ['listen_purpose' => self::PURPOSE, 'listen_plan_slug' => (string) $pack->slug],
            'listen-pack-'.$purchase->id,
            false,
            __('ui.pack.card_failed'),
        );
    }

    /**
     * Extra credits by card: the card given ($paymentMethodId, from the card
     * form), or with none the card the member already pays with.
     *
     * @return array{status:string, purchase:CharPackPurchase, client_secret?:?string, message?:string, reason?:string}
     */
    public function startExtraCredits(User $user, int $minutes, int $amountCents, ?string $paymentMethodId, bool $saveCard): array
    {
        $purchase = $this->extraCredits->startPurchase($user, $minutes, CharPackPurchase::METHOD_CARD, $amountCents, 'USD');

        $card = $paymentMethodId;
        if ($card === null) {
            try {
                $card = $this->gateway->savedCard($this->gateway->ensureCustomer($user))['id'] ?? null;
            } catch (\Throwable $exception) {
                Log::warning('The saved card could not be read for extra credits.', ['user_id' => $user->id, 'error' => $exception->getMessage()]);
            }
            if ($card === null) {
                $this->extraCredits->markFailed($purchase, 'No saved card.');

                return ['status' => 'failed', 'purchase' => $purchase->fresh(), 'message' => __('ui.extra_credits.no_saved_card')];
            }
        }

        $plan = $purchase->plan()->value('slug');

        return $this->charge($user, $purchase, $card,
            ExtraCredits::description($minutes),
            [
                'listen_purpose' => ExtraCredits::PURPOSE,
                'listen_minutes' => (string) $minutes,
                'listen_credits' => (string) $purchase->chars,
                'listen_plan_slug' => (string) ($plan ?? ''),
            ],
            'listen-extra-credits-'.$purchase->id,
            $paymentMethodId !== null && $saveCard,
            __('ui.extra_credits.card_failed'),
        );
    }

    /**
     * The browser finished the bank's check: look the payment up and settle it.
     *
     * @return array{status:string, purchase:CharPackPurchase, client_secret?:?string, message?:string, reason?:string}
     */
    public function confirm(CharPackPurchase $purchase): array
    {
        if ($purchase->status === CharPackPurchase::STATUS_PAID) {
            return ['status' => 'paid', 'purchase' => $purchase];
        }
        if (! $purchase->stripe_payment_intent_id || $purchase->status !== CharPackPurchase::STATUS_PENDING) {
            return ['status' => 'failed', 'purchase' => $purchase, 'message' => $this->failedMessage($purchase)];
        }

        return $this->settle($purchase, $this->gateway->retrievePaymentIntent($purchase->stripe_payment_intent_id));
    }

    /**
     * Stripe webhook: payment_intent.succeeded.
     */
    public function handlePaymentSucceeded(object $intent): void
    {
        if (! in_array((string) ($intent->metadata->listen_purpose ?? ''), [self::PURPOSE, ExtraCredits::PURPOSE], true)) {
            return;
        }

        $purchase = $this->purchaseForIntent((string) ($intent->id ?? ''), (string) ($intent->metadata->listen_purchase_id ?? ''));
        if (! $purchase) {
            Log::warning('A one-time card payment succeeded but no purchase matches it.', ['payment_intent' => $intent->id ?? null]);

            return;
        }

        if ((int) ($intent->amount_received ?? $intent->amount ?? 0) < (int) $purchase->amount_minor) {
            Log::warning('A one-time card payment is lower than the price; nothing was added.', ['purchase_id' => $purchase->id]);

            return;
        }

        $this->markPaid($purchase);
        $this->keepCardIfAsked($purchase, $intent);
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
            $this->refund($purchase);
        }
    }

    /** Takes back what is unused of a purchase, whatever it bought. */
    public function refund(CharPackPurchase $purchase): int
    {
        return $purchase->isExtraCredits() ? $this->extraCredits->refund($purchase) : $this->packs->refund($purchase);
    }

    /**
     * @param  array<string, string>  $metadata
     * @return array{status:string, purchase:CharPackPurchase, client_secret?:?string, message?:string, reason?:string}
     */
    private function charge(User $user, CharPackPurchase $purchase, string $paymentMethodId, string $description, array $metadata, string $idempotencyKey, bool $saveCard, string $failedMessage): array
    {
        try {
            $customerId = $this->gateway->ensureCustomer($user);
            $intent = $this->gateway->chargeCard(
                $customerId,
                $paymentMethodId,
                (int) $purchase->amount_minor,
                'usd',
                $description,
                $metadata + [
                    'listen_user_id' => (string) $user->id,
                    'listen_purchase_id' => (string) $purchase->id,
                ] + ($saveCard ? ['listen_save_card' => '1'] : []),
                $idempotencyKey,
                $saveCard,
            );
        } catch (\Throwable $exception) {
            Log::warning('A one-time card payment could not start.', [
                'user_id' => $user->id,
                'purchase_id' => $purchase->id,
                'kind' => $purchase->kind,
                'error' => $exception->getMessage(),
            ]);
            $this->markFailed($purchase, $exception->getMessage());

            return ['status' => 'failed', 'purchase' => $purchase->fresh(), 'message' => $failedMessage];
        }

        if ($intent['id'] !== '') {
            $purchase->forceFill(['stripe_payment_intent_id' => $intent['id']])->save();
        }

        return $this->settle($purchase, $intent);
    }

    /**
     * @param  array{id:string,status:string,client_secret:?string,amount:int,currency:string,customer:?string,payment_method:?string,metadata:array<string, string>,error:?string}  $intent
     * @return array{status:string, purchase:CharPackPurchase, client_secret?:?string, message?:string, reason?:string}
     */
    private function settle(CharPackPurchase $purchase, array $intent): array
    {
        $belongsHere = ($intent['metadata']['listen_purchase_id'] ?? null) === (string) $purchase->id
            || ($purchase->stripe_payment_intent_id && $intent['id'] === $purchase->stripe_payment_intent_id);

        if ($intent['status'] === 'succeeded' && $belongsHere && $intent['amount'] >= (int) $purchase->amount_minor) {
            $paid = $this->markPaid($purchase);
            $this->keepCardIfAsked($paid, (object) ['customer' => $intent['customer'], 'payment_method' => $intent['payment_method'] ?? null, 'metadata' => (object) $intent['metadata']]);
            // How it was paid, for Billing → Payments (no invoice event comes for a one-time payment).
            app(PaymentLedger::class)->syncStripeCustomer((string) ($intent['customer'] ?? ''));

            return ['status' => 'paid', 'purchase' => $paid];
        }

        if (in_array($intent['status'], ['requires_action', 'requires_confirmation'], true) && $intent['client_secret']) {
            return ['status' => 'requires_action', 'purchase' => $purchase, 'client_secret' => $intent['client_secret']];
        }

        if ($intent['status'] === 'processing') {
            // Settles through the webhook.
            return ['status' => 'processing', 'purchase' => $purchase, 'message' => $purchase->isExtraCredits() ? __('ui.extra_credits.card_processing') : __('ui.pack.card_processing')];
        }

        $this->markFailed($purchase, (string) ($intent['error'] ?? $intent['status']));

        // Stripe's reason is in English: the member reads our words in their own
        // language, and the reason goes along on its own for the page to keep aside.
        return [
            'status' => 'failed',
            'purchase' => $purchase->fresh(),
            'message' => $intent['error']
                ? ($purchase->isExtraCredits() ? __('ui.extra_credits.card_declined') : __('ui.pack.card_declined'))
                : $this->failedMessage($purchase),
            'reason' => (string) ($intent['error'] ?? ''),
        ];
    }

    private function markPaid(CharPackPurchase $purchase): CharPackPurchase
    {
        return $purchase->isExtraCredits() ? $this->extraCredits->markPaid($purchase) : $this->packs->markPaid($purchase);
    }

    private function markFailed(CharPackPurchase $purchase, string $message): void
    {
        $purchase->isExtraCredits() ? $this->extraCredits->markFailed($purchase, $message) : $this->packs->markFailed($purchase, $message);
    }

    private function failedMessage(CharPackPurchase $purchase): string
    {
        return $purchase->isExtraCredits() ? __('ui.extra_credits.card_failed') : __('ui.pack.card_failed');
    }

    /**
     * A new card the member asked to keep: it becomes the card they pay with
     * (Stripe kept it on the customer with the payment), and Billing shows it.
     */
    private function keepCardIfAsked(CharPackPurchase $purchase, object $intent): void
    {
        if ((string) ($intent->metadata->listen_save_card ?? '') !== '1') {
            return;
        }

        $customerId = is_string($intent->customer ?? null) ? $intent->customer : (string) ($intent->customer->id ?? '');
        $card = is_string($intent->payment_method ?? null) ? $intent->payment_method : (string) ($intent->payment_method->id ?? '');
        if ($customerId === '' || $card === '') {
            return;
        }

        try {
            $this->gateway->makeDefaultCard($customerId, $card);
            $saved = $this->gateway->savedCard($customerId);
            if ($saved !== null && $purchase->user) {
                $purchase->user->forceFill(['pm_type' => $saved['brand'], 'pm_last_four' => $saved['last4']])->save();
            }
        } catch (\Throwable $exception) {
            Log::warning('A card the member asked to keep could not be made their card.', [
                'purchase_id' => $purchase->id,
                'error' => $exception->getMessage(),
            ]);
        }
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
