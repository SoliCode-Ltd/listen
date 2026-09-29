<?php

namespace App\Services\Billing;

use App\Models\User;

interface StripeTrialGateway
{
    public function ensureCustomer(User $user): string;

    /**
     * @param  array<string, string>  $metadata
     * @return array{id:string,client_secret:string}
     */
    public function createSetupIntent(string $customerId, array $metadata = []): array;

    /**
     * @return array{id:string,status:string,customer:string,payment_method:string,metadata:array<string, string>}
     */
    public function retrieveSetupIntent(string $setupIntentId): array;

    /**
     * @return array{id:string,type:string,customer:string,brand:string,last4:string,exp_month:int,exp_year:int,fingerprint:string,funding:string,country:string,cvc_check:string}
     */
    public function retrievePaymentMethod(string $paymentMethodId): array;

    public function setDefaultPaymentMethod(string $customerId, string $paymentMethodId): void;

    /**
     * @param  array<string, string>  $metadata
     * @return array{id:string,status:string,trial_end:int,customer:string,default_payment_method:string,price_id:string,item_id:string,product_id:string}
     */
    public function createTrialSubscription(string $customerId, string $priceId, string $paymentMethodId, int $trialDays, array $metadata): array;

    public function cancelSubscription(string $subscriptionId): void;
}
