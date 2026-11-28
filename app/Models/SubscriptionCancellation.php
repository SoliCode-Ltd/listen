<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member cancelling their subscription, with the reason they gave in the cancel
 * dialog (Billing → Cancellations). The reason is optional: a member can cancel
 * without one, and the cancellation email then asks for it.
 */
class SubscriptionCancellation extends Model
{
    public const REASONS = ['price', 'quality', 'technical', 'not_needed', 'other'];

    /** The longest note a member can write. */
    public const NOTE_MAX = 1000;

    protected $fillable = [
        'user_id',
        'plan_id',
        'plan_name',
        'reason',
        'note',
        'locale',
        'ended_now',
        'ends_at',
        'cancelled_at',
        'email_sent_at',
    ];

    protected $casts = [
        'ended_now' => 'boolean',
        'ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'email_sent_at' => 'datetime',
    ];

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
