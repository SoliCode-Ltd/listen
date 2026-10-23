<?php

namespace App\Services\Billing;

/**
 * Why a bank refused a card, in the few kinds a member can act on: not enough
 * money on the card, a card detail that is wrong, a bank that could not answer
 * just now, or a bank (or card) that does not allow the payment. Stripe's
 * decline code decides; anything not listed is a refusal. The billing page
 * tells the member what happened in their language and offers what to do
 * next for each kind (ui.billing.decline_*).
 */
final class CardDecline
{
    public const NO_FUNDS = 'no_funds';

    public const CARD_DETAILS = 'card_details';

    public const TRY_AGAIN = 'try_again';

    public const REFUSED = 'refused';

    /** Stripe decline codes (or error codes) for each kind. The page reads this list too. */
    public const CODES = [
        'insufficient_funds' => self::NO_FUNDS,
        'card_velocity_exceeded' => self::NO_FUNDS,
        'withdrawal_count_limit_exceeded' => self::NO_FUNDS,
        'incorrect_number' => self::CARD_DETAILS,
        'invalid_number' => self::CARD_DETAILS,
        'incorrect_cvc' => self::CARD_DETAILS,
        'invalid_cvc' => self::CARD_DETAILS,
        'invalid_expiry_month' => self::CARD_DETAILS,
        'invalid_expiry_year' => self::CARD_DETAILS,
        'expired_card' => self::CARD_DETAILS,
        'incorrect_zip' => self::CARD_DETAILS,
        'processing_error' => self::TRY_AGAIN,
        'try_again_later' => self::TRY_AGAIN,
        'issuer_not_available' => self::TRY_AGAIN,
        'reenter_transaction' => self::TRY_AGAIN,
    ];

    public static function kind(?string $declineCode, ?string $code = null): string
    {
        return self::CODES[strtolower(trim((string) $declineCode))]
            ?? self::CODES[strtolower(trim((string) $code))]
            ?? self::REFUSED;
    }

    /** The kind of decline behind a payment intent Stripe could not take. */
    public static function ofIntent(mixed $intent): string
    {
        $error = is_object($intent) ? ($intent->last_payment_error ?? null) : null;

        return self::kind($error->decline_code ?? null, $error->code ?? null);
    }
}
