<?php

namespace App\Services;

use App\Models\AudioGeneration;
use App\Models\ClonedVoice;
use App\Models\GenerationError;
use App\Models\TextItem;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a row in generation_errors for every failure a member hits while
 * generating audio, so the owner can read what happened while away.
 *
 * Recording must never break a generation: every failure here is swallowed
 * (including the table not existing yet, before its migration has run).
 */
class GenerationErrorRecorder
{
    private const MAX_MESSAGE = 2000;

    /**
     * A paid generation failed (dashboard or extension). Retries of the same
     * generation update one row instead of adding more.
     */
    public function audioGenerationFailed(AudioGeneration $generation, \Throwable $error, int $attempt, int $maxAttempts, ?string $userMessage = null): void
    {
        $this->record([
            'source' => $this->sourceForGeneration($generation),
            'severity' => GenerationError::SEVERITY_ERROR,
            'user_id' => $generation->user_id,
            'subject_type' => 'audio_generation',
            'subject_id' => $generation->id,
            'provider' => $generation->tts_provider,
            'voice_name' => $generation->voice_name,
            'voice_tier' => $generation->voice_tier,
            'target_language' => $generation->target_language,
            'dialect' => $generation->dialect,
            'exception_class' => get_class($error),
            'message' => $error->getMessage(),
            'user_message' => $userMessage,
            'context' => [
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'final' => $attempt >= $maxAttempts,
                'chars' => mb_strlen((string) $generation->input_text),
                'translate' => (bool) $generation->translate,
                'performance_style' => $generation->performance_style,
                'emotion' => $generation->emotion,
                'is_dialogue' => (bool) $generation->is_dialogue,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $details  voice_name, voice_tier, target_language, dialect, chars, ...
     */
    public function demoFailed(?int $userId, \Throwable $error, array $details, ?string $userMessage = null): void
    {
        $this->record([
            'source' => GenerationError::SOURCE_DEMO,
            'severity' => GenerationError::SEVERITY_ERROR,
            'user_id' => $userId,
            'voice_name' => $details['voice_name'] ?? null,
            'voice_tier' => $details['voice_tier'] ?? null,
            'target_language' => $details['target_language'] ?? null,
            'dialect' => $details['dialect'] ?? null,
            'exception_class' => get_class($error),
            'message' => $error->getMessage(),
            'user_message' => $userMessage,
            'context' => array_diff_key($details, array_flip(['voice_name', 'voice_tier', 'target_language', 'dialect'])),
        ]);
    }

    public function voiceCloningFailed(ClonedVoice $voice, string $reason, string $code, ?int $httpStatus = null): void
    {
        $this->record([
            'source' => GenerationError::SOURCE_VOICE_CLONING,
            // Too short a sample or a missing consent recording is the member's to fix.
            'severity' => in_array($code, ['duration', 'consent'], true)
                ? GenerationError::SEVERITY_WARNING
                : GenerationError::SEVERITY_ERROR,
            'user_id' => $voice->user_id,
            'subject_type' => 'cloned_voice',
            'subject_id' => $voice->id,
            'provider' => 'gemini',
            'voice_name' => 'cloned_'.$voice->id,
            'message' => $reason,
            'context' => [
                'failure_code' => $code,
                'http_status' => $httpStatus,
                'voice_name' => $voice->name,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function record(array $attributes): ?GenerationError
    {
        try {
            $attributes['message'] = $this->clean((string) ($attributes['message'] ?? ''), self::MAX_MESSAGE) ?: 'Unknown error.';
            if (isset($attributes['user_message'])) {
                $attributes['user_message'] = $this->clean((string) $attributes['user_message'], 500);
            }
            foreach (['voice_name' => 120, 'voice_tier' => 20, 'target_language' => 10, 'dialect' => 20, 'provider' => 30, 'exception_class' => 190] as $field => $max) {
                if (isset($attributes[$field])) {
                    $attributes[$field] = mb_substr((string) $attributes[$field], 0, $max);
                }
            }
            $attributes['last_seen_at'] = now();

            // The same failure again counts on its row: per record when there is
            // one, otherwise per member and message within the hour (an outage
            // must not bury the list in identical rows).
            $query = GenerationError::query()->unresolved()->where('source', $attributes['source']);
            if (! empty($attributes['subject_type']) && ! empty($attributes['subject_id'])) {
                $query->where('subject_type', $attributes['subject_type'])
                    ->where('subject_id', $attributes['subject_id']);
            } else {
                $query->whereNull('subject_id')
                    ->where('user_id', $attributes['user_id'] ?? null)
                    ->where('message', $attributes['message'])
                    ->where('last_seen_at', '>=', now()->subHour());
            }

            $existing = $query->latest('id')->first();
            if ($existing) {
                $existing->fill($attributes);
                $existing->occurrences = $existing->occurrences + 1;
                $existing->save();

                return $existing;
            }

            return GenerationError::query()->create($attributes);
        } catch (\Throwable $e) {
            Log::warning('Could not record a generation error.', [
                'source' => $attributes['source'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function sourceForGeneration(AudioGeneration $generation): string
    {
        if ($generation->text_item_id) {
            $type = TextItem::query()->whereKey($generation->text_item_id)->value('source_type');
            if ($type === 'message') {
                return GenerationError::SOURCE_EXTENSION;
            }
            if ($type === 'api') {
                return GenerationError::SOURCE_API;
            }
        }

        return GenerationError::SOURCE_DASHBOARD;
    }

    /**
     * Provider errors can echo request URLs; never keep anything that looks like a key.
     */
    private function clean(string $text, int $max): string
    {
        $text = preg_replace('/AIza[0-9A-Za-z_\-]{20,}/', '[key hidden]', $text) ?? $text;
        $text = preg_replace('/([?&](?:key|api_key|access_token)=)[^&\s"\']+/i', '$1[hidden]', $text) ?? $text;
        $text = preg_replace('/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1[hidden]', $text) ?? $text;

        return mb_substr(trim($text), 0, $max);
    }
}
