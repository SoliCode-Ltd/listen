<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Billing\StripePackGateway;
use RuntimeException;

/**
 * Stands in for Stripe in pack tests: no real card is ever charged.
 */
class FakeStripePackGateway implements StripePackGateway
{
    /** What the next charge does: succeeded, requires_action, declined, processing, or throw. */
    public string $nextOutcome = 'succeeded';

    /** @var array<string, array<string, mixed>> */
    public array $intents = [];

    /** @var list<array<string, mixed>> */
    public array $charges = [];

    public function ensureCustomer(User $user): string
    {
        if (! $user->stripe_id) {
            $user->forceFill(['stripe_id' => 'cus_test_'.$user->id])->save();
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
        if ($this->nextOutcome === 'throw') {
            throw new RuntimeException('Stripe is unreachable.');
        }

        $id = 'pi_test_'.(count($this->intents) + 1);
        $this->charges[] = compact('customerId', 'paymentMethodId', 'amountMinor', 'currency', 'description', 'metadata', 'idempotencyKey');

        $status = match ($this->nextOutcome) {
            'declined' => 'requires_payment_method',
            default => $this->nextOutcome,
        };

        $this->intents[$id] = [
            'id' => $id,
            'status' => $status,
            'client_secret' => $status === 'requires_action' ? $id.'_secret_test' : null,
            'amount' => $amountMinor,
            'currency' => $currency,
            'customer' => $customerId,
            'metadata' => $metadata,
            'error' => $this->nextOutcome === 'declined' ? 'Your card was declined.' : null,
        ];

        return $this->intents[$id];
    }

    public function retrievePaymentIntent(string $paymentIntentId): array
    {
        return $this->intents[$paymentIntentId] ?? throw new RuntimeException('No such payment.');
    }

    /** The member passed the bank's check in the browser. */
    public function completeBankCheck(string $paymentIntentId): void
    {
        $this->intents[$paymentIntentId]['status'] = 'succeeded';
        $this->intents[$paymentIntentId]['client_secret'] = null;
    }
}
