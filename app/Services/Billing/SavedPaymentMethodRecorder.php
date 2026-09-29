<?php

namespace App\Services\Billing;

use App\Models\SavedPaymentMethod;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class SavedPaymentMethodRecorder
{
    public function __construct(
        private readonly StripeTrialGateway $gateway,
    ) {}

    /**
     * @param  array{id?:string,brand?:string,last4?:string,exp_month?:int,exp_year?:int,fingerprint?:string,funding?:string,country?:string,cvc_check?:string}  $card
     */
    public function rememberCard(
        User $user,
        array $card,
        string $purpose,
        bool $isDefault = false,
        ?int $billingTrialId = null,
        ?string $setupIntentId = null,
        bool $offSessionReady = false,
    ): ?SavedPaymentMethod {
        $paymentMethodId = trim((string) ($card['id'] ?? ''));
        if ($paymentMethodId === '') {
            return null;
        }

        if ($isDefault) {
            SavedPaymentMethod::query()
                ->where('user_id', $user->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        return SavedPaymentMethod::query()->updateOrCreate(
            ['stripe_payment_method_id' => $paymentMethodId],
            [
                'user_id' => $user->id,
                'billing_trial_id' => $billingTrialId,
                'stripe_customer_id' => $user->stripe_id,
                'brand' => $this->nullIfEmpty($card['brand'] ?? null),
                'last4' => $this->nullIfEmpty($card['last4'] ?? null),
                'exp_month' => (int) ($card['exp_month'] ?? 0) ?: null,
                'exp_year' => (int) ($card['exp_year'] ?? 0) ?: null,
                'fingerprint' => $this->nullIfEmpty($card['fingerprint'] ?? null),
                'funding' => $this->nullIfEmpty($card['funding'] ?? null),
                'country' => $this->nullIfEmpty($card['country'] ?? null),
                'cvc_check' => $this->nullIfEmpty($card['cvc_check'] ?? null),
                'setup_intent_id' => $this->nullIfEmpty($setupIntentId),
                'off_session_ready' => $offSessionReady,
                'purpose' => $purpose,
                'is_default' => $isDefault,
            ]
        );
    }

    public function rememberQuietly(User $user, ?string $paymentMethodId, string $purpose): void
    {
        $paymentMethodId = trim((string) $paymentMethodId);
        if ($paymentMethodId === '') {
            return;
        }

        try {
            $card = $this->gateway->retrievePaymentMethod($paymentMethodId);
            if (strtolower((string) ($card['type'] ?? '')) !== 'card') {
                return;
            }

            $this->rememberCard($user, $card, $purpose, true, null, null, false);
            $user->forceFill([
                'pm_type' => $this->nullIfEmpty($card['brand'] ?? null),
                'pm_last_four' => $this->nullIfEmpty($card['last4'] ?? null),
            ])->save();
        } catch (\Throwable $exception) {
            Log::warning('Saved card record was not written. The Stripe payment itself was not changed.', [
                'user_id' => $user->id,
                'purpose' => $purpose,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function nullIfEmpty(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
