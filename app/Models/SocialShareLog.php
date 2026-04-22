<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialShareLog extends Model
{
    use HasFactory;

    public const PLATFORMS = [
        'facebook',
        'twitter',
        'linkedin',
        'whatsapp',
        'telegram',
        'copy_link',
        'instagram',
    ];

    protected $fillable = [
        'user_id',
        'campaign_id',
        'platform',
        'locale',
        'referral_code',
        'referral_link',
        'visitor_ip',
        'visitor_device_id',
        'user_agent',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ReferralCampaign::class, 'campaign_id');
    }
}
