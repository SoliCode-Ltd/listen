<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member cancelling their subscription (Billing → Cancellations), wherever it was made:
 * in the cancel dialog, which requires a reason; in Stripe, with the reason Stripe's own
 * survey gave, if any; or before the dialog asked why (cancellations:import). A member who
 * turns renewal back on keeps the row, marked resumed, and is no longer counted.
 */
class SubscriptionCancellation extends Model
{
    public const REASONS = ['price', 'quality', 'technical', 'not_needed', 'other'];

    /** Where the cancellation was made: the cancel dialog, Stripe, or before the dialog asked why. */
    public const SOURCE_SITE = 'site';

    public const SOURCE_STRIPE = 'stripe';

    public const SOURCE_IMPORT = 'import';

    /** The day the cancel dialog started asking why. */
    public const REASONS_SINCE = '2026-11-28';

    /** The longest note a member can write. */
    public const NOTE_MAX = 1000;

    protected $fillable = [
        'user_id',
        'plan_id',
        'plan_name',
        'stripe_subscription_id',
        'source',
        'reason',
        'note',
        'locale',
        'ended_now',
        'ends_at',
        'cancelled_at',
        'email_sent_at',
        'resumed_at',
    ];

    protected $casts = [
        'ended_now' => 'boolean',
        'ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'email_sent_at' => 'datetime',
        'resumed_at' => 'datetime',
    ];

    /** Cancellations that went through and still stand (the member did not turn renewal back on). */
    public function scopeCounted($query)
    {
        return $query->whereNotNull('cancelled_at')->whereNull('resumed_at');
    }

    /**
     * The reason Stripe's own cancellation survey recorded (cancellation_details.feedback), as
     * the dialog's reasons; one with no close match is "something else". Null when there is none.
     */
    public static function reasonFromStripeFeedback(?string $feedback): ?string
    {
        return match ($feedback) {
            'too_expensive' => 'price',
            'low_quality' => 'quality',
            'unused' => 'not_needed',
            'missing_features', 'switched_service', 'too_complex', 'customer_service', 'other' => 'other',
            default => null,
        };
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** What the member saw in the dialog, in English, for the admin. */
    public static function reasonLabel(?string $reason): string
    {
        return match ($reason) {
            'price' => 'The price didn\'t suit them',
            'quality' => 'Voices or dialects not as expected',
            'technical' => 'A technical problem',
            'not_needed' => 'Don\'t need it right now',
            'other' => 'Something else',
            default => 'No reason given',
        };
    }

    /** Where it was made, for the admin. */
    public static function sourceLabel(?string $source): string
    {
        return match ($source) {
            self::SOURCE_STRIPE => 'In Stripe',
            self::SOURCE_IMPORT => 'Before reasons were asked',
            default => 'Cancel dialog',
        };
    }

    /**
     * The reason this member gave when they cancelled just now, if any: the cancel
     * dialog saves it before the cancellation is sent to Stripe, so it is there
     * whichever way the email is triggered (the site or Stripe's webhook).
     */
    public static function recentFor(User $user, int $minutes = 30): ?self
    {
        return self::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->latest('id')
            ->first();
    }
}
