<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\AudioFiles;
use App\Support\TtsStudioConfig;
use App\Support\VoiceCatalog;
use App\Support\VoiceTier;

class AudioGeneration extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_sequence_number',
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
        'style_prompt',
        'emotion',
        'speed',
        'pitch',
        // Dialogue mode
        'is_dialogue',
        'dialogue_metadata',
        // Character tracking
        'billable_chars',
        // Saved premium reuse settings
        'settings_snapshot',
        // Paid from pack characters rather than the monthly allowance
        'billed_to_pack',
        'duration_measured',
        // The developer API key that asked for it (null: dashboard or extension)
        'api_key_id',
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
        'billed_to_pack' => 'boolean',
        'duration_measured' => 'boolean',
        'dialogue_metadata' => 'array',
        'billable_chars' => 'integer',
        'user_sequence_number' => 'integer',
        'settings_snapshot' => 'array',
        'api_key_id' => 'integer',
    ];

    protected static function booted(): void
    {
        // A developer API retry with the key that made it must not make (and charge) it again.
        static::deleting(fn (AudioGeneration $generation) => \App\Services\Api\ApiIdempotency::markResultDeleted($generation));
    }

    public function textItem(): BelongsTo
    {
        return $this->belongsTo(TextItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The developer API key, or the AI app's key, that asked for it. */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    public function displayNumber(): int
    {
        return (int) ($this->user_sequence_number ?: $this->id);
    }

    /**
     * Deletes the generation as its member does (the studio and the developer API),
     * with what it leaves behind: its audio, the speech cache's copy of it
     * (GenerateAudioJob keeps that address) and its text, each unless another
     * voice still uses it.
     */
    public function deleteWithAudio(): void
    {
        $files = [$this->audio_path, ($this->settings_snapshot ?? [])['cache_copy'] ?? null];
        $textItemId = (int) $this->text_item_id;

        $this->delete();

        AudioFiles::deleteUnused($files);
        if ($textItemId > 0 && ! self::query()->where('text_item_id', $textItemId)->exists()) {
            TextItem::query()->whereKey($textItemId)->delete();
        }
    }

    /**
     * Everything the voice was made with, for the studio's "reuse" (the page then keeps
     * only what the member's plan has today: DashboardController::reuseForPlan).
     *
     * @return array<string, mixed>|null
     */
    public function reusableSettings(): ?array
    {
        $voiceTier = VoiceTier::normalize((string) ($this->voice_tier ?: VoiceTier::STANDARD));
        $snapshot = is_array($this->settings_snapshot) ? $this->settings_snapshot : [];
        $dialogueMetadata = is_array($this->dialogue_metadata) ? $this->dialogue_metadata : [];
        $voiceName = trim((string) ($snapshot['voice_name'] ?? $this->voice_name ?? ''));

        if ($voiceName === '') {
            return null;
        }

        $voiceMeta = VoiceCatalog::voiceMeta($voiceName);
        // A cloned voice is picked in the studio by its own name (cloned_12).
        $voiceKey = $snapshot['voice_key'] ?? (is_array($voiceMeta) ? ($voiceMeta['key'] ?? null) : (str_starts_with($voiceName, 'cloned_') ? $voiceName : null));
        $common = [
            'voice_name' => $voiceName,
            'voice_key' => $voiceKey,
            'voice_tier' => $voiceTier,
            'target_language' => trim((string) ($snapshot['target_language'] ?? $this->target_language)),
            'translate' => (bool) ($snapshot['translate'] ?? $this->translate),
        ];
        // A standard voice is made with no style, emotion, dialect or speed to choose.
        if ($voiceTier !== VoiceTier::HD) {
            return array_filter($common, static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        $dialect = trim((string) ($snapshot['dialect'] ?? $this->dialect ?? ''));
        $settings = $common + [
            'dialect' => $dialect,
            // Made with the dialect left on "Auto": it comes back as "Auto", not as the default dialect.
            // (Only Arabic and English have dialects and accents to choose.)
            'dialect_auto' => $dialect === '' && in_array($common['target_language'], ['ar', 'en'], true),
            'performance_style' => $snapshot['performance_style'] ?? $this->performance_style,
            'style_prompt' => $snapshot['style_prompt'] ?? $this->style_prompt,
            'emotion' => $snapshot['emotion'] ?? $this->emotion,
            'speed' => $snapshot['speed'] ?? $this->speed,
            'pitch' => $snapshot['pitch'] ?? $this->pitch,
            'is_dialogue' => (bool) ($snapshot['is_dialogue'] ?? $this->is_dialogue),
            'dialogue_speakers' => $snapshot['dialogue_speakers'] ?? ($dialogueMetadata['speakers'] ?? null),
            // The environment chosen, also one that could not be added (GenerateAudioJob).
            'audio_effect' => $snapshot['audio_effect'] ?? $snapshot['audio_effect_not_added'] ?? null,
        ];

        // A choice left on "Default" comes back as "Default", not as the value it stood for.
        $settings = TtsStudioConfig::reusedAsChosen($settings, is_array($snapshot['on_default'] ?? null) ? $snapshot['on_default'] : null, $settings['target_language']);

        return array_filter($settings, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    public function hasReusableSettings(): bool
    {
        return $this->reusableSettings() !== null;
    }
}
