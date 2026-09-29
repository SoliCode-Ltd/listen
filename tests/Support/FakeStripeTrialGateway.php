<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Billing\StripeTrialGateway;
use RuntimeException;

class FakeStripeTrialGateway implements StripeTrialGateway
{
    /** @var array<string, array{id:string,status:string,customer:string,payment_method:string,client_secret:string,metadata:array<string, string>}> */
    public array $setupIntents = [];

    /** @var array<string, array<string, mixed>> */
    public array $cards = [];

    /** @var list<array<string, mixed>> */
    public array $created = [];

    /** @var list<string> */
    public array $canceled = [];

    public string $subscriptionStatus = 'trialing';

    public ?int $trialEnd = null;

    public bool $throwOnCreate = false;

    public ?string $returnedPriceId = null;

    public ?string $returnedPaymentMethod = null;

    public function ensureCustomer(User $user): string
    {
        if (! $user->stripe_id) {
            $user->forceFill(['stripe_id' => 'cus_test_'.$user->id])->save();
        }

        return (string) $user->stripe_id;
    }

    public function createSetupIntent(string $customerId, array $metadata = []): array
    {
        $id = 'seti_'.(count($this->setupIntents) + 1);
        $this->setupIntents[$id] = [
            'id' => $id,
            'status' => 'requires_payment_method',
            'customer' => $customerId,
            'payment_method' => '',
            'client_secret' => $id.'_secret',
            'metadata' => $metadata,
        ];

        return [
            'id' => $id,
            'client_secret' => $id.'_secret',
        ];
    }

    public function markSucceeded(string $setupIntentId, string $paymentMethodId): void
    {
        $this->setupIntents[$setupIntentId]['status'] = 'succeeded';
        $this->setupIntents[$setupIntentId]['payment_method'] = $paymentMethodId;
    }

    /**
     * @param  array<string, mixed>  $card
     */
    public function putCard(array $card): void
    {
        $id = (string) $card['id'];
        $this->cards[$id] = array_merge([
            'id' => $id,
            'type' => 'card',
            'customer' => '',
            'brand' => 'visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => (int) now()->addYears(3)->year,
            'fingerprint' => 'fp_'.$id,
            'funding' => 'credit',
            'country' => 'US',
            'cvc_check' => 'pass',
        ], $card);
    }

    public function retrieveSetupIntent(string $setupIntentId): array
    {
        if (! isset($this->setupIntents[$setupIntentId])) {
            throw new RuntimeException('Unknown setup intent.');
        }

        $intent = $this->setupIntents[$setupIntentId];

        return [
            'id' => $intent['id'],
            'status' => $intent['status'],
            'customer' => $intent['customer'],
            'payment_method' => $intent['payment_method'],
            'metadata' => $intent['metadata'] ?? [],
        ];
    }

    public function retrievePaymentMethod(string $paymentMethodId): array
    {
        if (! isset($this->cards[$paymentMethodId])) {
            throw new RuntimeException('Unknown payment method.');
        }

        return $this->cards[$paymentMethodId];
    }

    public function setDefaultPaymentMethod(string $customerId, string $paymentMethodId): void
    {
        if (isset($this->cards[$paymentMethodId])) {
            $this->cards[$paymentMethodId]['customer'] = $customerId;
        }
    }

    public function createTrialSubscription(string $customerId, string $priceId, string $paymentMethodId, int $trialDays, array $metadata): array
    {
        if ($this->throwOnCreate) {
            throw new RuntimeException('Stripe rejected the subscription.');
        }

        $number = count($this->created) + 1;
        $record = [
            'id' => 'sub_test_'.$number,
            'status' => $this->subscriptionStatus,
            'trial_end' => $this->trialEnd ?? now()->addDays($trialDays)->getTimestamp(),
            'customer' => $customerId,
            'default_payment_method' => $this->returnedPaymentMethod ?? $paymentMethodId,
            'price_id' => $this->returnedPriceId ?? $priceId,
            'item_id' => 'si_test_'.$number,
            'product_id' => 'prod_creator',
            'metadata' => $metadata,
        ];
        $this->created[] = $record;

        return $record;
    }

    public function cancelSubscription(string $subscriptionId): void
    {
        $this->canceled[] = $subscriptionId;
    }
}
