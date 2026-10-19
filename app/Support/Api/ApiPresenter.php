<?php

namespace App\Support\Api;

use App\Models\AudioGeneration;
use App\Models\ClonedVoice;
use App\Models\Voice;
use App\Services\Api\ApiSpeechService;
use App\Services\Api\ApiUsageMeter;
use App\Support\Credits;
use App\Support\UsageEstimator;
use App\Support\VoiceCatalog;
use App\Support\VoiceTier;
use Illuminate\Support\Collection;

/**
 * How the developer API shows history items.
 */
final class ApiPresenter
{
    /** The API's names for generation statuses. */
    public static function status(?string $status): string
    {
        return match ((string) $status) {
            'done' => 'completed',
            'failed' => 'failed',
            'queued' => 'queued',
            default => 'processing',
        };
    }

    /**
     * @param  iterable<AudioGeneration>  $generations
     * @return array<int, array<string, mixed>>
     */
    public static function historyItems(iterable $generations): array
    {
        $generations = $generations instanceof Collection ? $generations : collect($generations);
        $voices = self::voiceNames($generations);

        return $generations->map(fn (AudioGeneration $generation): array => self::historyItem($generation, $voices))->values()->all();
    }

    /**
     * @param  array<string, array{voice_id:string, name:string}>|null  $voices  internal voice name => public id and name
     * @return array<string, mixed>
     */
    public static function historyItem(AudioGeneration $generation, ?array $voices = null): array
    {
        $voices ??= self::voiceNames(collect([$generation]));
        $voice = $voices[(string) $generation->voice_name] ?? null;
        $status = self::status($generation->status);
        $title = $generation->relationLoaded('textItem') ? $generation->textItem?->title : $generation->textItem()->value('title');

        return [
            'history_item_id' => (int) $generation->id,
            'status' => $status,
            'request_source' => $generation->api_key_id ? 'api' : 'app',
            'api_key_id' => $generation->api_key_id ? (int) $generation->api_key_id : null,
            'voice_id' => $voice['voice_id'] ?? null,
            'voice_name' => $voice['name'] ?? null,
            'model_id' => ApiModels::forTier((string) $generation->voice_tier),
            'language_code' => (string) $generation->target_language,
            'dialect' => VoiceTier::normalize((string) $generation->voice_tier) === VoiceTier::HD ? ($generation->dialect ?: null) : null,
            'style' => VoiceTier::normalize((string) $generation->voice_tier) === VoiceTier::HD ? ($generation->performance_style ?: null) : null,
            'emotion' => VoiceTier::normalize((string) $generation->voice_tier) === VoiceTier::HD ? ($generation->emotion ?: null) : null,
            'voice_settings' => [
                'speed' => (float) ($generation->speed ?? 1.0),
                'pitch' => (float) ($generation->pitch ?? 0.0),
            ],
            // The effect is kept in the settings, as GenerateAudioJob reads it.
            'audio_effect' => (($generation->settings_snapshot ?? [])['audio_effect'] ?? null) ?: null,
            'dialogue' => (bool) $generation->is_dialogue,
            'title' => $title !== null && $title !== '' ? (string) $title : null,
            'text' => (string) $generation->input_text,
            'character_count' => (int) ($generation->billable_chars ?? UsageEstimator::billableChars((string) $generation->input_text)),
            'credits_used' => self::creditsUsed($generation),
            'duration_seconds' => $status === 'completed' ? (int) $generation->duration_seconds : null,
            'audio_url' => $status === 'completed' && filled($generation->audio_path) ? url('/v1/history/'.$generation->id.'/audio') : null,
            'error' => $status === 'failed' ? ['message' => (string) ($generation->error_message ?: 'Voice generation failed.')] : null,
            'created_at' => $generation->created_at?->toIso8601String(),
            'created_at_unix' => $generation->created_at?->getTimestamp(),
        ];
    }

    /**
     * Credits the generation cost: the real audio once it is done, the
     * reserved estimate while it runs, nothing when it failed.
     */
    public static function creditsUsed(AudioGeneration $generation): int
    {
        return match (self::status($generation->status)) {
            'completed' => Credits::forSeconds((int) $generation->duration_seconds),
            'failed' => 0,
            // As reserved (older generations kept no reservation: estimated again).
            default => ApiUsageMeter::reservationOf($generation)['credits']
                ?? ApiSpeechService::estimate((string) $generation->input_text, (float) ($generation->speed ?: 1.0), (string) ($generation->voice_tier ?: VoiceTier::HD), $generation->only(['performance_style', 'emotion', 'dialect', 'style_prompt']))['credits'],
        };
    }

    /**
     * Public ids and names for the voices of a set of generations, in two queries.
     *
     * @param  Collection<int, AudioGeneration>  $generations
     * @return array<string, array{voice_id:string, name:string}>
     */
    private static function voiceNames(Collection $generations): array
    {
        $names = $generations->pluck('voice_name')->filter()->unique()->values();
        $map = [];

        $clonedIds = $names->filter(fn ($name): bool => str_starts_with((string) $name, 'cloned_'))
            ->map(fn ($name): int => (int) substr((string) $name, 7))->all();
        if ($clonedIds !== []) {
            foreach (ClonedVoice::withTrashed()->whereIn('id', $clonedIds)->get(['id', 'name']) as $cloned) {
                $map['cloned_'.$cloned->id] = ['voice_id' => 'cloned_'.$cloned->id, 'name' => (string) $cloned->name];
            }
        }

        $premade = $names->reject(fn ($name): bool => str_starts_with((string) $name, 'cloned_'))->all();
        if ($premade !== []) {
            foreach (Voice::query()->whereIn('internal_voice_id', $premade)->orderBy('is_active')->get(['id', 'internal_voice_id', 'display_name']) as $voice) {
                // Active voices last, so they win when an id was reused.
                $map[(string) $voice->internal_voice_id] = ['voice_id' => VoiceCatalog::keyFromId((int) $voice->id), 'name' => (string) $voice->display_name];
            }
        }

        return $map;
    }
}
