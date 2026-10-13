<?php

namespace App\Services\Billing;

use App\Models\User;

/**
 * One-time card payments for character packs. Tests swap in a fake.
 */
interface StripePackGateway
{
    public function ensureCustomer(User $user): string;

    /**
     * Charges the card straight away. A card that needs the bank's check
     * comes back as requires_action with a client_secret for the browser.
     *
     * @param  array<string, string>  $metadata
     * @return array{id:string,status:string,client_secret:?string,amount:int,currency:string,customer:?string,metadata:array<string, string>,error:?string}
     */
    public function chargeCard(
        string $customerId,
        string $paymentMethodId,
        int $amountMinor,
        string $currency,
        string $description,
        array $metadata,
        string $idempotencyKey,
    ): array;

    /**
     * @return array{id:string,status:string,client_secret:?string,amount:int,currency:string,customer:?string,metadata:array<string, string>,error:?string}
     */
    public function retrievePaymentIntent(string $paymentIntentId): array;
}
