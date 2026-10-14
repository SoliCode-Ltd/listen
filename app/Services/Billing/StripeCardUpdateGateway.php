<?php

namespace App\Services\Billing;

use App\Models\User;

/**
 * Replacing the card that renews a subscription: the bank approves the new
 * card first (a SetupIntent, with 3D Secure when the bank asks), and only
 * then does it become the renewal card.
 */
interface StripeCardUpdateGateway
{
    public function ensureCustomer(User $user): string;

    /** @return array{id:string, client_secret:string} */
    public function createSetupIntent(string $customerId, int $userId): array;

    /** @return array{id:string, status:string, customer:string, payment_method:string, purpose:string} */
    public function retrieveSetupIntent(string $setupIntentId): array;

    /** The customer's default card and the subscription's own card (which wins when set). */
    public function useForRenewals(string $customerId, ?string $subscriptionId, string $paymentMethodId): void;

    /**
     * Pays the subscription's unpaid invoices with the new card.
     *
     * @return array{attempted:int, paid:int}
     */
    public function payOpenInvoices(string $customerId, string $subscriptionId, string $paymentMethodId): array;
}
