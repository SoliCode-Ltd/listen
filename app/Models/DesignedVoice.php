<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A voice a member described in words (Voice Design, App\Services\VoiceDesignService).
 *
 * provider_voice_key is the engine's voice_... id; the voice lives only in the
 * project of the site key that made it (key_name), so it is always spoken on
 * that key. A draft is one take of a design, waiting to be kept; generations
 * name a saved voice as designed_{id}.
 */
class DesignedVoice extends Model
{
    use SoftDeletes;

    public const DRAFT = 'draft';

    public const SAVED = 'saved';

    /** How a generation names the voice (as cloned_{id} names a cloned voice). */
    public const KEY_PREFIX = 'designed_';

    protected $fillable = [
        'user_id',
        'status',
        'batch',
        'name',
        'description',
        'engine_prompt',
        'language',
        'gender',
        'preview_text',
        'preview_audio_path',
        'provider_voice_key',
        'key_name',
        'is_showcase',
        'take',
        'metadata',
        'kept_at',
        'last_used_at',
    ];

    protected $casts = [
        'is_showcase' => 'boolean',
        'take' => 'integer',
        'metadata' => 'array',
        'kept_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSaved(): bool
    {
        return $this->status === self::SAVED && filled($this->provider_voice_key);
    }

    public function voiceKey(): string
    {
        return self::KEY_PREFIX.$this->id;
    }

    /** The id in a voice key (designed_12), or null when it is not one. */
    public static function idFromKey(?string $key): ?int
    {
        $key = (string) $key;
        if (! str_starts_with($key, self::KEY_PREFIX)) {
            return null;
        }
        $id = substr($key, strlen(self::KEY_PREFIX));

        return ctype_digit($id) ? (int) $id : null;
    }

    public static function isKey(?string $key): bool
    {
        return self::idFromKey($key) !== null;
    }

    /** The speech engine's language code: Arabic voices on ar-XA, English ones on en-US. */
    public function languageCode(): string
    {
        return $this->language === 'en' ? 'en-US' : 'ar-XA';
    }

    /**
     * @param  Builder<DesignedVoice>  $query
     */
    public function scopeSaved(Builder $query): Builder
    {
        return $query->where('status', self::SAVED)->whereNotNull('provider_voice_key');
    }

    /**
     * @param  Builder<DesignedVoice>  $query
     */
    public function scopeDrafts(Builder $query): Builder
    {
        return $query->where('status', self::DRAFT);
    }

    /**
     * @param  Builder<DesignedVoice>  $query
     */
    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
