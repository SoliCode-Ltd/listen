<?php

namespace App\Services\Billing;

use App\Models\User;

/**
 * One-time card payments: character packs and extra credits. Tests swap in a fake.
 */
interface StripePackGateway
{
    public function ensureCustomer(User $user): string;

    /**
     * Charges the card straight away. A card that needs the bank's check
     * comes back as requires_action with a client_secret for the browser.
     * $saveCard keeps a new card on the customer for later payments.
     *
     * @param  array<string, string>  $metadata
     * @return array{id:string,status:string,client_secret:?string,amount:int,currency:string,customer:?string,payment_method:?string,metadata:array<string, string>,error:?string}
     */
    public function chargeCard(
        string $customerId,
        string $paymentMethodId,
        int $amountMinor,
        string $currency,
        string $description,
        array $metadata,
        string $idempotencyKey,
        bool $saveCard = false,
    ): array;

    /**
     * The card the customer pays with by default (the one their plan renews
     * on), or else the card they saved last; null when none is saved.
     *
     * @return array{id:string,brand:string,last4:string,exp_month:int,exp_year:int}|null
     */
    public function savedCard(string $customerId): ?array;

    /** Makes a card the customer has saved the one they pay with by default. */
    public function makeDefaultCard(string $customerId, string $paymentMethodId): void;

    /**
     * @return array{id:string,status:string,client_secret:?string,amount:int,currency:string,customer:?string,payment_method:?string,metadata:array<string, string>,error:?string}
     */
    public function retrievePaymentIntent(string $paymentIntentId): array;
}
