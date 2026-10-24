<?php

namespace App\Support;

/**
 * Which Gemini voice each premium speaker speaks with, so no two speakers
 * share a voice. Most come from Gemini's Extended Voice Library (ids like
 * en-gb-advisor-9). Keyed by the speaker's internal_voice_id, which never
 * changes, so history, favourites and API calls keep finding them.
 * Data: database/seeders/data/speaker_voices.php.
 */
final class SpeakerVoices
{
    /** @var array<string, string>|null voice by lower-case internal id */
    private static ?array $byId = null;

    /**
     * The speaker's own voice, or null for a speaker with none assigned.
     */
    public static function find(?string $internalVoiceId): ?string
    {
        return self::all()[strtolower(trim((string) $internalVoiceId))] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (self::$byId === null) {
            self::set((require database_path('seeders/data/speaker_voices.php'))['speakers']);
        }

        return self::$byId;
    }

    /**
     * Use these assignments instead of the data file (tests).
     *
     * @param  array<string, string>  $speakers  voice by internal id
     */
    public static function set(array $speakers): void
    {
        self::$byId = array_change_key_case($speakers, CASE_LOWER);
    }

    public static function forget(): void
    {
        self::$byId = null;
    }
}
