<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoVoiceGeneration extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'primary_identity_hash',
        'link_group',
        'input_text',
        'speech_text',
        'source_language',
        'target_language',
        'translate',
        'voice_key',
        'voice_name',
        'voice_label',
        'voice_provider',
        'voice_tier',
        'audio_path',
        'duration_seconds',
        'billable_chars',
        'dialect',
        'performance_style',
        'style_prompt',
        'emotion',
        'speed',
        'pitch',
        'tts_model',
        'tts_latency_ms',
        'tts_fallback_used',
        'tts_cost_estimate_usd',
        'quota_limit_chars',
        'quota_remaining_chars',
        'metadata',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected $casts = [
        'translate' => 'boolean',
        'duration_seconds' => 'integer',
        'billable_chars' => 'integer',
        'speed' => 'float',
        'pitch' => 'float',
        'tts_latency_ms' => 'integer',
        'tts_fallback_used' => 'boolean',
        'tts_cost_estimate_usd' => 'float',
        'quota_limit_chars' => 'integer',
        'quota_remaining_chars' => 'integer',
        'metadata' => 'array',
    ];
}
