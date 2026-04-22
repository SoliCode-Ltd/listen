<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoRewardTransaction extends Model
{
    use HasFactory;

    public const KIND_GRANT = 'grant';
    public const KIND_CONSUME = 'consume';
    public const KIND_REVOKE = 'revoke';
    public const KIND_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'user_id',
        'conversion_id',
        'demo_voice_generation_id',
        'kind',
        'delta_chars',
        'reason',
    ];

    protected $casts = [
        'delta_chars' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversion(): BelongsTo
    {
        return $this->belongsTo(ReferralConversion::class, 'conversion_id');
    }

    public function demoVoiceGeneration(): BelongsTo
    {
        return $this->belongsTo(DemoVoiceGeneration::class, 'demo_voice_generation_id');
    }
}
