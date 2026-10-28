<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A payment a member made, and how they paid (App\Services\Billing\PaymentLedger).
 */
class Payment extends Model
{
    public const METHOD_APPLE_PAY = 'apple_pay';

    public const METHOD_GOOGLE_PAY = 'google_pay';

    public const METHOD_CARD = 'card';

    public const METHOD_LINK = 'link';

    public const METHOD_INSTAPAY = 'instapay';

    public const METHOD_VODAFONE_CASH = 'vodafone_cash';

    /** @var array<string, string> */
    public const METHODS = [
        self::METHOD_APPLE_PAY => 'Apple Pay',
        self::METHOD_GOOGLE_PAY => 'Google Pay',
        self::METHOD_CARD => 'Card',
        self::METHOD_LINK => 'Link',
        self::METHOD_INSTAPAY => 'InstaPay',
        self::METHOD_VODAFONE_CASH => 'Vodafone Cash',
    ];

    protected $fillable = [
        'user_id',
        'reference',
        'method',
        'card_brand',
        'card_last4',
        'card_country',
        'amount_minor',
        'refunded_minor',
        'currency',
        'description',
        'paid_at',
        'stripe_customer_id',
        'stripe_payment_method_id',
        'card_exp_month',
        'card_exp_year',
        'card_funding',
        'card_fingerprint',
        'card_cvc_check',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'refunded_minor' => 'integer',
        'paid_at' => 'datetime',
        'card_exp_month' => 'integer',
        'card_exp_year' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The card kept on file for the member's renewals and trials, when this payment's card is one. */
    public function savedCard(): BelongsTo
    {
        return $this->belongsTo(SavedPaymentMethod::class, 'stripe_payment_method_id', 'stripe_payment_method_id');
    }

    public static function methodLabel(?string $method): string
    {
        $method = (string) $method;

        return self::METHODS[$method] ?? ($method !== '' ? Str::headline($method) : '—');
    }

    public static function methodColor(?string $method): string
    {
        return match ((string) $method) {
            self::METHOD_APPLE_PAY => 'gray',
            self::METHOD_GOOGLE_PAY => 'info',
            self::METHOD_CARD => 'primary',
            self::METHOD_INSTAPAY => 'success',
            self::METHOD_VODAFONE_CASH => 'danger',
            default => 'gray',
        };
    }

    /** "Visa •••• 6753 · SA" for a card or a phone wallet's card. */
    public function cardLabel(): ?string
    {
        if (! $this->card_brand && ! $this->card_last4) {
            return null;
        }

        return trim(Str::headline((string) $this->card_brand).($this->card_last4 ? ' •••• '.$this->card_last4 : '').($this->card_country ? ' · '.$this->card_country : ''));
    }

    public function netMinor(): int
    {
        return max(0, $this->amount_minor - $this->refunded_minor);
    }
}
