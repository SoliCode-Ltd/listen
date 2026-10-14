<?php

namespace App\Services\Billing;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;

class CashierStripeCardUpdateGateway implements StripeCardUpdateGateway
{
    public function ensureCustomer(User $user): string
    {
        if (! $user->hasStripeId()) {
            $user->createAsStripeCustomer();
            $user->refresh();
        }

        return (string) $user->stripe_id;
    }

    public function createSetupIntent(string $customerId, int $userId): array
    {
        $intent = Cashier::stripe()->setupIntents->create([
            'customer' => $customerId,
            'usage' => 'off_session',
            'payment_method_types' => ['card'],
            'metadata' => [
                'listen_purpose' => 'card_update',
                'listen_user_id' => (string) $userId,
            ],
        ]);

        return ['id' => (string) $intent->id, 'client_secret' => (string) $intent->client_secret];
    }

    public function retrieveSetupIntent(string $setupIntentId): array
    {
        $intent = Cashier::stripe()->setupIntents->retrieve($setupIntentId, []);
        $customer = $intent->customer ?? '';
        $paymentMethod = $intent->payment_method ?? '';
        $metadata = $intent->metadata ?? null;

        return [
            'id' => (string) $intent->id,
            'status' => (string) ($intent->status ?? ''),
            'customer' => is_object($customer) ? (string) ($customer->id ?? '') : (string) $customer,
            'payment_method' => is_object($paymentMethod) ? (string) ($paymentMethod->id ?? '') : (string) $paymentMethod,
            'purpose' => is_object($metadata) ? (string) ($metadata->listen_purpose ?? '') : (string) (($metadata ?? [])['listen_purpose'] ?? ''),
        ];
    }

    public function useForRenewals(string $customerId, ?string $subscriptionId, string $paymentMethodId): void
    {
        Cashier::stripe()->customers->update($customerId, [
            'invoice_settings' => ['default_payment_method' => $paymentMethodId],
        ]);

        if ($subscriptionId !== null && trim($subscriptionId) !== '') {
            Cashier::stripe()->subscriptions->update($subscriptionId, [
                'default_payment_method' => $paymentMethodId,
            ]);
        }
    }

    public function payOpenInvoices(string $customerId, string $subscriptionId, string $paymentMethodId): array
    {
        $attempted = 0;
        $paid = 0;
        $invoices = Cashier::stripe()->invoices->all([
            'customer' => $customerId,
            'subscription' => $subscriptionId,
            'status' => 'open',
            'limit' => 5,
        ]);

        foreach ($invoices->data ?? [] as $invoice) {
            $attempted++;
            try {
                $result = Cashier::stripe()->invoices->pay((string) $invoice->id, ['payment_method' => $paymentMethodId]);
                if ((string) ($result->status ?? '') === 'paid') {
                    $paid++;
                }
            } catch (\Throwable $exception) {
                Log::warning('Paying an open invoice with the updated card failed.', [
                    'invoice_id' => (string) $invoice->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return ['attempted' => $attempted, 'paid' => $paid];
    }
}
