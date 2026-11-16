<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReferralConversion extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FLAGGED = 'flagged';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_FLAGGED,
    ];

    protected $fillable = [
        'campaign_id',
        'referrer_user_id',
        'referred_user_id',
        'visit_id',
        'status',
        'qualification_step',
        'reward_chars',
        'qualified_at',
        'approved_at',
        'rejected_at',
        'flagged_at',
        'rejection_reason',
        'fraud_indicators',
        'reviewed_by',
        'reviewer_notes',
    ];

    protected $casts = [
        'reward_chars' => 'integer',
        'fraud_indicators' => 'array',
        'qualified_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'flagged_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ReferralCampaign::class, 'campaign_id');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ReferralVisit::class, 'visit_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function rewardTransactions(): HasMany
    {
        return $this->hasMany(DemoRewardTransaction::class, 'conversion_id');
    }

    protected static function booted(): void
    {
        // When a conversion is deleted, revoke any reward chars still
        // attributable to it. Ensures admin deletion has the same effect on the
        // referrer's balance as rejection, without the row sticking around.
        static::deleting(function (ReferralConversion $conversion): void {
            try {
                $netOutstanding = (int) DemoRewardTransaction::query()
                    ->where('conversion_id', $conversion->id)
                    ->whereIn('kind', [DemoRewardTransaction::KIND_GRANT, DemoRewardTransaction::KIND_REVOKE])
                    ->sum('delta_chars');

                if ($netOutstanding <= 0) {
                    return;
                }

                $referrer = $conversion->referrer()->first();
                if (! $referrer) {
                    return;
                }

                app(\App\Services\Referral\DemoRewardLedgerService::class)
                    ->revoke($referrer, $netOutstanding, null, 'conversion_deleted');

                $referrerProfile = UserReferralProfile::query()
                    ->where('user_id', $referrer->id)
                    ->first();
                if ($referrerProfile) {
                    app(\App\Services\Referral\ReferralService::class)
                        ->recomputeProfileCounters($referrerProfile);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to revoke reward on conversion delete.', [
                    'conversion_id' => $conversion->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        });
    }
}
