<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * A member who nearly bought a plan: their first payment was declined, or they opened the
 * checkout and left without paying. The row keeps what happened, the follow-up email it got
 * (or why it got none) and whether the member bought a plan afterwards
 * (App\Services\Billing\PurchaseFollowUps).
 */
class PurchaseFollowUp extends Model
{
    public const KIND_PAYMENT_FAILED = 'payment_failed';

    public const KIND_CHECKOUT_LEFT = 'checkout_left';

    /** The email is due at email_due_at. */
    public const WAITING = 'waiting';

    public const SENT = 'sent';

    /** No email, for the reason in email_note (bought first, emailed lately, no mailbox...). */
    public const SKIPPED = 'skipped';

    /** The email could not be sent (email_note holds why). */
    public const FAILED = 'failed';

    /** This kind of email was turned off when it came due. */
    public const OFF = 'off';

    protected $guarded = [];

    protected $casts = [
        'happened_at' => 'datetime',
        'email_due_at' => 'datetime',
        'email_sent_at' => 'datetime',
        'paid_at' => 'datetime',
        'opens' => 'integer',
        'amount_cents' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The table is there (its migration was run): until then nothing is recorded or sent. */
    public static function ready(): bool
    {
        return once(fn (): bool => rescue(fn () => Schema::hasTable('purchase_follow_ups'), false, false));
    }

    /** What happened, for the admin. */
    public function what(): string
    {
        return $this->kind === self::KIND_PAYMENT_FAILED ? 'Payment declined' : 'Left the checkout';
    }

    /**
     * The email's state, for the admin.
     *
     * @return array{label: string, color: string}
     */
    public function emailState(): array
    {
        return match ($this->email_status) {
            self::SENT => ['label' => 'Sent', 'color' => 'success'],
            self::WAITING => ['label' => 'Waiting to send', 'color' => 'warning'],
            self::FAILED => ['label' => 'Could not be sent', 'color' => 'danger'],
            self::OFF => ['label' => 'Emails were off', 'color' => 'gray'],
            default => ['label' => 'Not sent', 'color' => 'gray'],
        };
    }

    /** The bank's reason for a decline, in plain words. */
    public function reasonLabel(): ?string
    {
        if ($this->reason === null || $this->reason === '') {
            return null;
        }

        return match ($this->reason) {
            'insufficient_funds' => 'Not enough money on the card',
            'do_not_honor', 'generic_decline', 'card_declined' => 'Declined by the bank',
            'transaction_not_allowed', 'card_not_supported' => 'Card not allowed for this payment',
            'expired_card' => 'Card expired',
            'incorrect_cvc', 'invalid_cvc' => 'Wrong security code',
            'incorrect_number', 'invalid_number' => 'Wrong card number',
            'authentication_required', 'authentication_failed' => 'Bank confirmation not completed',
            'lost_card', 'stolen_card', 'fraudulent', 'pickup_card' => 'Blocked by the bank',
            default => ucfirst(str_replace('_', ' ', $this->reason)),
        };
    }
}
