<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * A payment that did not happen: a member who nearly bought a plan (their first payment was
 * declined, or they opened the checkout and left without paying), or a paying member whose
 * renewal could not be charged. The row keeps what happened, the email it got (or why it
 * got none) and whether the member paid afterwards (App\Services\Billing\PurchaseFollowUps).
 */
class PurchaseFollowUp extends Model
{
    public const KIND_PAYMENT_FAILED = 'payment_failed';

    public const KIND_CHECKOUT_LEFT = 'checkout_left';

    /** A paying member's renewal could not be charged (App\Services\Billing\RenewalGrace). */
    public const KIND_RENEWAL_FAILED = 'renewal_failed';

    /**
     * For a sample renewal (a preview or a test send): the day its plan stays on until.
     * Never saved; a real case reads it from the member's plan (planKeptUntil()).
     */
    public ?\DateTimeInterface $sampleKeptUntil = null;

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
        'discount_percent' => 'integer',
        'discount_cents' => 'integer',
        'discount_months' => 'integer',
        'discounted_at' => 'datetime',
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

    /** The discount columns are there (their migration was run): until then no discount is given. */
    public static function discountsReady(): bool
    {
        return once(fn (): bool => self::ready() && rescue(fn () => Schema::hasColumn('purchase_follow_ups', 'discount_percent'), false, false));
    }

    /** The team took something off this unpaid payment (PurchaseFollowUps::giveDiscount()). */
    public function hasDiscount(): bool
    {
        return (int) $this->discount_percent > 0 && (int) $this->discount_cents > 0;
    }

    /** What the member owes for it now, after any discount; null when the amount is not known. */
    public function priceCents(): ?int
    {
        if ($this->amount_cents === null) {
            return null;
        }

        return max(0, (int) $this->amount_cents - ($this->hasDiscount() ? (int) $this->discount_cents : 0));
    }

    /** What happened, for the admin. */
    public function what(): string
    {
        return match ($this->kind) {
            self::KIND_PAYMENT_FAILED => 'First payment declined',
            self::KIND_RENEWAL_FAILED => 'Renewal declined',
            default => 'Left the checkout',
        };
    }

    /** A declined payment (a first one or a renewal): it has a bank's reason and Stripe's page to pay it. */
    public function isDeclinedPayment(): bool
    {
        return in_array($this->kind, [self::KIND_PAYMENT_FAILED, self::KIND_RENEWAL_FAILED], true);
    }

    /**
     * A failed renewal: the day the member's plan stays on until while it is unpaid (the
     * grace days), or null when it got none (the paid features paused at once) or is over.
     */
    public function planKeptUntil(): ?\DateTimeInterface
    {
        if ($this->kind !== self::KIND_RENEWAL_FAILED) {
            return null;
        }
        if ($this->sampleKeptUntil !== null || ! $this->exists || ! $this->user_id) {
            return $this->sampleKeptUntil;
        }

        $plan = Subscription::query()->where('user_id', $this->user_id)->latest('id')->first();

        return $plan !== null && (string) $plan->billing_payment_state === 'past_due' ? $plan->billing_grace_ends_at : null;
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
