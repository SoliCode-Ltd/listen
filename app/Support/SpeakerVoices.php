<?php

namespace App\Support;

/**
 * Which Gemini voice each premium speaker speaks with, so no two speakers
 * share a voice. Most come from Gemini's Extended Voice Library (ids like
 * en-gb-tutor-9). Keyed by the speaker's internal_voice_id, which never
 * changes, so history, favourites and API calls keep finding them; a
 * speaker's display name finds them too.
 * Data: database/seeders/data/speaker_voices.php.
 */
final class SpeakerVoices
{
    /** Age groups, youngest first, as the library filters them. */
    public const AGES = ['child', 'young', 'middle', 'elder'];

    /** @var array<string, array{name: string, gender: string, age?: string, voice: string}>|null by lower-case internal id */
    private static ?array $byId = null;

    /** @var array<string, string>|null lower-case display name => lower-case internal id */
    private static ?array $byName = null;

    /** @var array<string, string>|null voice => gender */
    private static ?array $genders = null;

    /**
     * The voice of the speaker with this internal id or display name, or null for one with none assigned.
     */
    public static function find(?string $speaker): ?string
    {
        $key = strtolower(trim((string) $speaker));
        $byId = self::all();

        return ($byId[$key] ?? $byId[self::$byName[$key] ?? ''] ?? null)['voice'] ?? null;
    }

    /**
     * The speaker with this internal id or display name, or null.
     *
     * @return array{name: string, gender: string, age?: string, voice: string}|null
     */
    public static function speaker(?string $speaker): ?array
    {
        $key = strtolower(trim((string) $speaker));
        $byId = self::all();

        return $byId[$key] ?? $byId[self::$byName[$key] ?? ''] ?? null;
    }

    /**
     * The speaker's age group (child, young, middle or elder: how their avatar
     * looks), or null for a speaker with none.
     */
    public static function ageOf(?string $internalVoiceId): ?string
    {
        return self::all()[strtolower(trim((string) $internalVoiceId))]['age'] ?? null;
    }

    /**
     * Whether this is a voice a speaker was given (a library id passed on as is, e.g. by dubbing).
     */
    public static function isAssigned(string $voice): bool
    {
        self::all();

        return isset(self::$genders[$voice]);
    }

    /**
     * "male" or "female" for a voice a speaker was given, or null.
     */
    public static function genderOf(string $voice): ?string
    {
        self::all();

        return self::$genders[$voice] ?? null;
    }

    /**
     * @return array<string, array{name: string, gender: string, age?: string, voice: string}>
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
     * @param  array<string, array{name: string, gender: string, age?: string, voice: string}>  $speakers  by internal id
     */
    public static function set(array $speakers): void
    {
        self::$byId = array_change_key_case($speakers, CASE_LOWER);
        self::$byName = [];
        self::$genders = [];
        foreach (self::$byId as $id => $speaker) {
            self::$byName[strtolower($speaker['name'])] = $id;
            self::$genders[$speaker['voice']] = $speaker['gender'];
        }
    }

    public static function forget(): void
    {
        self::$byId = null;
        self::$byName = null;
        self::$genders = null;
    }
}
