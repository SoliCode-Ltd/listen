<?php

namespace App\Support;

/**
 * Which Gemini voice each premium speaker speaks with, so no two speakers
 * share a voice. Most come from Gemini 3.8's Extended Voice Library (ids like
 * en-gb-advisor-9), which only the 3.8 models know; the older model uses the
 * speaker's fallback, one of the 30 studio voices. Keyed by the speaker's
 * internal_voice_id, which never changes, so history, favourites and API
 * calls keep finding them. Data: database/seeders/data/speaker_voices.php.
 */
final class SpeakerVoices
{
    /** @var array<string, array{voice: string, fallback: string}>|null by lower-case internal id */
    private static ?array $byId = null;

    /** @var array<string, string>|null library voice => fallback */
    private static ?array $fallbacks = null;

    /**
     * The speaker's voice and fallback, or null for a speaker with none assigned.
     *
     * @return array{voice: string, fallback: string}|null
     */
    public static function find(?string $internalVoiceId): ?array
    {
        return self::byId()[strtolower(trim((string) $internalVoiceId))] ?? null;
    }

    /**
     * The studio voice to use instead of a library voice on a model that does not know it.
     */
    public static function fallbackFor(string $voice): ?string
    {
        self::byId();

        return self::$fallbacks[$voice] ?? null;
    }

    /**
     * @return array<string, array{voice: string, fallback: string}>
     */
    public static function all(): array
    {
        return self::byId();
    }

    /**
     * @return array<string, array{voice: string, fallback: string}>
     */
    private static function byId(): array
    {
        if (self::$byId === null) {
            self::$byId = [];
            self::$fallbacks = [];
            foreach ((require database_path('seeders/data/speaker_voices.php'))['speakers'] as $id => $row) {
                self::$byId[strtolower($id)] = ['voice' => $row['voice'], 'fallback' => $row['fallback']];
                self::$fallbacks[$row['voice']] = $row['fallback'];
            }
        }

        return self::$byId;
    }

    /**
     * Use these assignments instead of the data file (tests).
     *
     * @param  array<string, array{voice: string, fallback: string}>  $speakers
     */
    public static function set(array $speakers): void
    {
        self::$byId = [];
        self::$fallbacks = [];
        foreach ($speakers as $id => $row) {
            self::$byId[strtolower($id)] = ['voice' => $row['voice'], 'fallback' => $row['fallback']];
            self::$fallbacks[$row['voice']] = $row['fallback'];
        }
    }

    public static function forget(): void
    {
        self::$byId = null;
        self::$fallbacks = null;
    }
}
