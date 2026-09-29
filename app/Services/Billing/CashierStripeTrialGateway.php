<?php

namespace App\Services\Billing;

use App\Models\User;
use Laravel\Cashier\Cashier;

class CashierStripeTrialGateway implements StripeTrialGateway
{
    public function ensureCustomer(User $user): string
    {
        if (! $user->hasStripeId()) {
            $user->createAsStripeCustomer();
            $user->refresh();
        }

        return (string) $user->stripe_id;
    }

    public function createSetupIntent(string $customerId): array
    {
        $intent = Cashier::stripe()->setupIntents->create([
            'customer' => $customerId,
            'usage' => 'off_session',
            'payment_method_types' => ['card'],
            'metadata' => [
                'listen_purpose' => 'creator_trial',
            ],
        ]);

        return [
            'id' => (string) $intent->id,
            'client_secret' => (string) $intent->client_secret,
        ];
    }

    public function retrieveSetupIntent(string $setupIntentId): array
    {
        $intent = Cashier::stripe()->setupIntents->retrieve($setupIntentId, []);
        $paymentMethod = $intent->payment_method ?? '';
        if (is_object($paymentMethod)) {
            $paymentMethod = (string) ($paymentMethod->id ?? '');
        }

        return [
            'id' => (string) $intent->id,
            'status' => (string) ($intent->status ?? ''),
            'customer' => $this->idFromMixed($intent->customer ?? null),
            'payment_method' => (string) $paymentMethod,
        ];
    }

    public function retrievePaymentMethod(string $paymentMethodId): array
    {
        $paymentMethod = Cashier::stripe()->paymentMethods->retrieve($paymentMethodId, []);
        $card = $paymentMethod->card ?? null;

        return [
            'id' => (string) ($paymentMethod->id ?? ''),
            'type' => (string) ($paymentMethod->type ?? ''),
            'customer' => $this->idFromMixed($paymentMethod->customer ?? null),
            'brand' => (string) ($card->brand ?? ''),
            'last4' => (string) ($card->last4 ?? ''),
            'exp_month' => (int) ($card->exp_month ?? 0),
            'exp_year' => (int) ($card->exp_year ?? 0),
            'fingerprint' => (string) ($card->fingerprint ?? ''),
            'funding' => (string) ($card->funding ?? ''),
            'country' => (string) ($card->country ?? ''),
            'cvc_check' => (string) ($card->checks->cvc_check ?? ''),
        ];
    }

    public function setDefaultPaymentMethod(string $customerId, string $paymentMethodId): void
    {
        Cashier::stripe()->customers->update($customerId, [
            'invoice_settings' => [
                'default_payment_method' => $paymentMethodId,
            ],
        ]);
    }

    public function createTrialSubscription(string $customerId, string $priceId, string $paymentMethodId, int $trialDays, array $metadata): array
    {
        $subscription = Cashier::stripe()->subscriptions->create([
            'customer' => $customerId,
            'items' => [
                ['price' => $priceId],
            ],
            'trial_period_days' => $trialDays,
            'default_payment_method' => $paymentMethodId,
            'payment_settings' => [
                'save_default_payment_method' => 'on_subscription',
            ],
            'trial_settings' => [
                'end_behavior' => [
                    'missing_payment_method' => 'cancel',
                ],
            ],
            'metadata' => $metadata,
        ]);

        $item = $subscription->items->data[0] ?? null;

        return [
            'id' => (string) $subscription->id,
            'status' => (string) ($subscription->status ?? ''),
            'trial_end' => (int) ($subscription->trial_end ?? 0),
            'customer' => $this->idFromMixed($subscription->customer ?? null),
            'default_payment_method' => $this->idFromMixed($subscription->default_payment_method ?? null),
            'price_id' => (string) ($item->price->id ?? ''),
            'item_id' => (string) ($item->id ?? ''),
            'product_id' => $this->idFromMixed($item->price->product ?? null),
        ];
    }

    public function cancelSubscription(string $subscriptionId): void
    {
        $subscriptionId = trim($subscriptionId);
        if ($subscriptionId === '') {
            return;
        }

        Cashier::stripe()->subscriptions->cancel($subscriptionId, []);
    }

    private function idFromMixed(mixed $value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }

        if (is_object($value)) {
            return trim((string) ($value->id ?? ''));
        }

        return '';
    }
}
