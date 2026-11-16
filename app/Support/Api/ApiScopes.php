<?php

namespace App\Support\Api;

use App\Models\User;
use App\Support\StudioFacts;
use App\Support\Transcription\TranscriptionAccess;

/**
 * Permissions an API key can hold. A key with no list holds them all.
 */
final class ApiScopes
{
    public const TEXT_TO_SPEECH = 'text_to_speech';

    public const VOICES_READ = 'voices_read';

    public const HISTORY_READ = 'history_read';

    public const HISTORY_WRITE = 'history_write';

    public const USER_READ = 'user_read';

    /** Speech to text: send audio and read its transcripts (/v1/transcriptions). */
    public const SPEECH_TO_TEXT = 'speech_to_text';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::TEXT_TO_SPEECH,
            self::VOICES_READ,
            self::HISTORY_READ,
            self::HISTORY_WRITE,
            self::USER_READ,
            self::SPEECH_TO_TEXT,
        ];
    }

    /**
     * The permissions to show: speech to text's once it is public (StudioFacts::speechToText()),
     * or, for a member choosing a key's permissions, once they can see speech to text.
     *
     * @return array<int, string>
     */
    public static function offered(?User $member = null): array
    {
        $speechToText = $member !== null
            ? TranscriptionAccess::openTo($member)
            : StudioFacts::speechToText();

        return $speechToText ? self::all() : array_values(array_diff(self::all(), [self::SPEECH_TO_TEXT]));
    }

    /**
     * Known scopes only, in a fixed order, without repeats.
     *
     * @param  array<int, mixed>  $scopes
     * @return array<int, string>
     */
    public static function normalize(array $scopes): array
    {
        $given = array_map(static fn ($scope): string => strtolower(trim((string) $scope)), $scopes);

        return array_values(array_intersect(self::all(), $given));
    }

    /**
     * @param  array<int, string>|null  $granted  null: every scope
     */
    public static function allows(?array $granted, string $scope): bool
    {
        return $granted === null || in_array($scope, $granted, true);
    }
}
