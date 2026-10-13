<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    ];

    public function textItem(): BelongsTo
    {
        return $this->belongsTo(TextItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function displayNumber(): int
    {
        return (int) ($this->user_sequence_number ?: $this->id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function reusableSettings(): ?array
    {
        $voiceTier = VoiceTier::normalize((string) ($this->voice_tier ?: VoiceTier::STANDARD));
        if ($voiceTier !== VoiceTier::HD) {
            return null;
        }

        $snapshot = is_array($this->settings_snapshot) ? $this->settings_snapshot : [];
        $dialogueMetadata = is_array($this->dialogue_metadata) ? $this->dialogue_metadata : [];
        $voiceName = trim((string) ($snapshot['voice_name'] ?? $this->voice_name ?? ''));

        if ($voiceName === '') {
            return null;
        }

        $voiceMeta = VoiceCatalog::voiceMeta($voiceName);
        $settings = [
            'voice_name' => $voiceName,
            'voice_key' => $snapshot['voice_key'] ?? (is_array($voiceMeta) ? ($voiceMeta['key'] ?? null) : null),
            'voice_tier' => $voiceTier,
            'target_language' => trim((string) ($snapshot['target_language'] ?? $this->target_language)),
            'translate' => (bool) ($snapshot['translate'] ?? $this->translate),
            'dialect' => $snapshot['dialect'] ?? $this->dialect,
            'performance_style' => $snapshot['performance_style'] ?? $this->performance_style,
            'style_prompt' => $snapshot['style_prompt'] ?? $this->style_prompt,
            'emotion' => $snapshot['emotion'] ?? $this->emotion,
            'speed' => $snapshot['speed'] ?? $this->speed,
            'pitch' => $snapshot['pitch'] ?? $this->pitch,
            'is_dialogue' => (bool) ($snapshot['is_dialogue'] ?? $this->is_dialogue),
            'dialogue_speakers' => $snapshot['dialogue_speakers'] ?? ($dialogueMetadata['speakers'] ?? null),
        ];

        return array_filter($settings, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    public function hasReusablePremiumSettings(): bool
    {
        return $this->reusableSettings() !== null;
    }
}
