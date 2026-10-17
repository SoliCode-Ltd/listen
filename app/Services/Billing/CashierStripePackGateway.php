<?php

namespace App\Services\Billing;

use App\Models\User;
use Laravel\Cashier\Cashier;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;

class CashierStripePackGateway implements StripePackGateway
{
    public function ensureCustomer(User $user): string
    {
        if (! $user->hasStripeId()) {
            $user->createAsStripeCustomer();
            $user->refresh();
        }

        return (string) $user->stripe_id;
    }

    public function chargeCard(
        string $customerId,
        string $paymentMethodId,
        int $amountMinor,
        string $currency,
        string $description,
        array $metadata,
        string $idempotencyKey,
    ): array {
        try {
            $intent = Cashier::stripe()->paymentIntents->create([
                'amount' => $amountMinor,
                'currency' => strtolower($currency),
                'customer' => $customerId,
                'payment_method' => $paymentMethodId,
                'payment_method_types' => ['card'],
                'confirm' => true,
                'description' => $description,
                // Shown on the card statement after the company name: "...* LISTEN".
                'statement_descriptor_suffix' => 'LISTEN',
                'metadata' => $metadata,
            ], ['idempotency_key' => $idempotencyKey]);
        } catch (CardException $exception) {
            // A declined card: Stripe answers with an error instead of the payment.
            $intent = $exception->getError()?->payment_intent ?? null;

            return [
                'id' => (string) ($intent->id ?? ''),
                'status' => 'requires_payment_method',
                'client_secret' => null,
                'amount' => $amountMinor,
                'currency' => strtolower($currency),
                'customer' => $customerId,
                'metadata' => $metadata,
                'error' => $exception->getMessage(),
            ];
        }

        return $this->toArray($intent);
    }

    public function retrievePaymentIntent(string $paymentIntentId): array
    {
        return $this->toArray(Cashier::stripe()->paymentIntents->retrieve($paymentIntentId, []));
    }

    /**
     * @return array{id:string,status:string,client_secret:?string,amount:int,currency:string,customer:?string,metadata:array<string, string>,error:?string}
     */
    private function toArray(PaymentIntent $intent): array
    {
        $metadata = $intent->metadata ? $intent->metadata->toArray() : [];

        return [
            'id' => (string) $intent->id,
            'status' => (string) $intent->status,
            'client_secret' => $intent->client_secret ? (string) $intent->client_secret : null,
            'amount' => (int) $intent->amount,
            'currency' => (string) $intent->currency,
            'customer' => is_string($intent->customer) ? $intent->customer : ($intent->customer->id ?? null),
            'metadata' => array_map('strval', $metadata),
            'error' => $intent->last_payment_error->message ?? null,
        ];
    }
}
