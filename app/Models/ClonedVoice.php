<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Represents a user's cloned voice created via Google Cloud TTS Chirp 3
 * Instant Custom Voice API.
 *
 * The provider_voice_key stores the voice_cloning_key returned by the
 * Google Cloud TTS v1beta1 generateVoiceCloningKey endpoint. This key
 * is then passed in synthesis requests via voice.voice_clone.voice_cloning_key.
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
