<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudioGeneration extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'text_item_id',
        'input_text',
        'source_language',
        'target_language',
        'voice_tier',
        'voice_name',
        'translate',
        'audio_path',
        'duration_seconds',
        'status',
        'error_message',
        // TTS telemetry (hybrid architecture)
        'tts_provider',
        'tts_model',
        'tts_latency_ms',
        'tts_fallback_used',
        'tts_cost_estimate_usd',
        // Voice studio controls
        'dialect',
        'performance_style',
        'emotion',
        'speed',
        'pitch',
        // Dialogue mode
        'is_dialogue',
        'dialogue_metadata',
        // Character tracking
        'billable_chars',
    ];

    protected $casts = [
        'translate' => 'boolean',
        'duration_seconds' => 'integer',
        'tts_latency_ms' => 'integer',
        'tts_fallback_used' => 'boolean',
        'tts_cost_estimate_usd' => 'float',
        'speed' => 'float',
        'pitch' => 'float',
        'is_dialogue' => 'boolean',
        'dialogue_metadata' => 'array',
        'billable_chars' => 'integer',
    ];

    public function textItem(): BelongsTo
    {
        return $this->belongsTo(TextItem::class);
    }
}
