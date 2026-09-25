<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A user's cloned voice created with Gemini 3.8 Flash voice replication.
 *
 * provider_voice_key stores the persistent voice_... id returned by
 * POST /v1beta/voices. Synthesis passes that id back to Gemini.
 */
class ClonedVoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'source_type',
        'source_audio_path',
        'consent_audio_path',
        'source_audio_duration_seconds',
        'language_code',
        'provider',
        'provider_voice_key',
        'status',
        'failure_reason',
        'consent_confirmed',
        'metadata',
    ];

    protected $casts = [
        'source_audio_duration_seconds' => 'integer',
        'consent_confirmed' => 'boolean',
        'metadata' => 'array',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* ------------------------------------------------------------------ */
    /*  Status helpers                                                     */
    /* ------------------------------------------------------------------ */

    public function isReady(): bool
    {
        return $this->status === 'ready' && filled($this->provider_voice_key);
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isProcessing(): bool
    {
        return in_array($this->status, ['pending', 'validating', 'processing'], true);
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeReady($query)
    {
        return $query->where('status', 'ready')->whereNotNull('provider_voice_key');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeOwnedBy($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
