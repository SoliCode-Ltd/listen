<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ReferralCampaign extends Model
{
    use HasFactory;

    public const QUALIFICATION_PAID_PLAN = 'paid_plan';
    public const QUALIFICATION_SIGNUP = 'signup';
    public const QUALIFICATION_EMAIL_VERIFIED = 'email_verified';
    public const QUALIFICATION_FIRST_DEMO = 'first_demo_generation';

    public const QUALIFICATION_OPTIONS = [
        self::QUALIFICATION_PAID_PLAN,
        self::QUALIFICATION_SIGNUP,
        self::QUALIFICATION_EMAIL_VERIFIED,
        self::QUALIFICATION_FIRST_DEMO,
    ];

    protected $fillable = [
        'slug',
        'name',
        'is_active',
        'qualification_step',
        'reward_chars',
        'visit_window_days',
        'max_conversions_per_referrer_per_day',
        'max_conversions_per_referrer_total',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'reward_chars' => 'integer',
        'visit_window_days' => 'integer',
        'max_conversions_per_referrer_per_day' => 'integer',
        'max_conversions_per_referrer_total' => 'integer',
    ];

    public static function active(): ?self
    {
        return Cache::remember('referral.campaign.active', 60, function () {
            return static::query()
                ->where('is_active', true)
                ->orderByDesc('id')
                ->first();
        });
    }

    public static function forgetCache(): void
    {
        Cache::forget('referral.campaign.active');
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::forgetCache());
        static::deleted(fn () => static::forgetCache());
    }
}
