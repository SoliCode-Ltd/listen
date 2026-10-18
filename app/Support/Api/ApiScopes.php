<?php

namespace App\Support\Api;

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
        ];
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
