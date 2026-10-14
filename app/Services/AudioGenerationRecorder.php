<?php

namespace App\Services;

use App\Models\AudioGeneration;
use App\Models\TextItem;
use App\Support\VoiceCatalog;
use App\Support\VoiceTier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AudioGenerationRecorder
{
    public function __construct(
        private readonly ProfanityFilter $profanityFilter,
        private readonly BlockedGenerationLogger $blockedGenerationLogger,
    ) {
    }

    /**
     * @param  array<string, mixed>  $textItemAttributes
     * @param  array<string, mixed>  $generationAttributes
     * @return array{item:TextItem,job:AudioGeneration}
     */
    public function createForUser(int $userId, array $textItemAttributes, array $generationAttributes): array
    {
        $inputText = trim((string) ($generationAttributes['input_text'] ?? $textItemAttributes['content'] ?? ''));
        if ($inputText !== '') {
            $match = $this->profanityFilter->firstMatch($inputText);
            if ($match !== null) {
                $this->blockedGenerationLogger->logFromValidation($inputText, $match, 'content');

                throw ValidationException::withMessages([
                    'content' => __('ui.backend.generation_text_prohibited'),
                ]);
            }
        }

        return DB::transaction(function () use ($userId, $textItemAttributes, $generationAttributes): array {
            $item = TextItem::create(array_merge($textItemAttributes, [
                'user_id' => $userId,
            ]));

            $sequenceNumber = ((int) AudioGeneration::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->max('user_sequence_number')) + 1;

            $attributes = array_merge($generationAttributes, [
                'user_id' => $userId,
                'text_item_id' => $item->id,
                'user_sequence_number' => $sequenceNumber,
            ]);

            $attributes['settings_snapshot'] = $this->buildSettingsSnapshot($attributes);
            // Kept in the settings only: the effect is added after the speech (GenerateAudioJob).
            unset($attributes['audio_effect']);

            $job = AudioGeneration::create($attributes);

            return [
                'item' => $item,
                'job' => $job,
            ];
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    private function buildSettingsSnapshot(array $attributes): ?array
    {
        $voiceTier = VoiceTier::normalize((string) ($attributes['voice_tier'] ?? VoiceTier::STANDARD));
        $voiceName = trim((string) ($attributes['voice_name'] ?? ''));

        if ($voiceTier !== VoiceTier::HD || $voiceName === '') {
            return null;
        }

        $voiceMeta = VoiceCatalog::voiceMeta($voiceName);
        $dialogueMetadata = $attributes['dialogue_metadata'] ?? null;
        $dialogueSpeakers = is_array($dialogueMetadata)
            ? (($dialogueMetadata['speakers'] ?? null) ?: null)
            : null;

        $snapshot = array_filter([
            'voice_name' => $voiceName,
            'voice_key' => is_array($voiceMeta) ? ($voiceMeta['key'] ?? null) : null,
            'voice_tier' => $voiceTier,
            'target_language' => trim((string) ($attributes['target_language'] ?? '')),
            'translate' => (bool) ($attributes['translate'] ?? false),
            'tts_provider' => trim((string) ($attributes['tts_provider'] ?? VoiceCatalog::providerForName($voiceName))) ?: null,
            'dialect' => trim((string) ($attributes['dialect'] ?? '')) ?: null,
            'performance_style' => trim((string) ($attributes['performance_style'] ?? '')) ?: null,
            'style_prompt' => trim((string) ($attributes['style_prompt'] ?? '')) ?: null,
            'emotion' => trim((string) ($attributes['emotion'] ?? '')) ?: null,
            'speed' => array_key_exists('speed', $attributes) ? (float) $attributes['speed'] : null,
            'pitch' => array_key_exists('pitch', $attributes) ? (float) $attributes['pitch'] : null,
            'is_dialogue' => (bool) ($attributes['is_dialogue'] ?? false),
            'dialogue_speakers' => $dialogueSpeakers,
            'audio_effect' => \App\Support\AudioEffects::isValid($attributes['audio_effect'] ?? null) ? $attributes['audio_effect'] : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);

        return $snapshot === [] ? null : $snapshot;
    }
}
