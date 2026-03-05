<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TtsLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'audio_generation_id',
        'provider',
        'model',
        'routing_mode',
        'plan_slug',
        'char_count',
        'latency_ms',
        'cost_estimate_usd',
        'fallback_used',
        'failure_reason',
        'target_language',
        'dialect',
        'emotion',
        'is_dialogue',
        'status',
        'created_at',
    ];

    protected $casts = [
        'char_count' => 'integer',
        'latency_ms' => 'integer',
        'cost_estimate_usd' => 'float',
        'fallback_used' => 'boolean',
        'is_dialogue' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function audioGeneration(): BelongsTo
    {
        return $this->belongsTo(AudioGeneration::class);
    }
}
