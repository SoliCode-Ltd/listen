<?php

namespace App\Support;

class VoiceTier
{
    public const STANDARD = 'standard';
    public const HD = 'hd';
    public const HD_PLUS = 'hd_plus';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::STANDARD,
            self::HD,
            self::HD_PLUS,
        ];
    }

    public static function normalize(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, self::all(), true) ? $value : self::STANDARD;
    }

    public static function label(string $tier): string
    {
        return match (self::normalize($tier)) {
            self::HD => 'HD',
            self::HD_PLUS => 'HD+',
            default => 'Standard',
        };
    }

    public static function rank(string $tier): int
    {
        return match (self::normalize($tier)) {
            self::HD => 2,
            self::HD_PLUS => 3,
            default => 1,
        };
    }

    public static function isHigherThan(string $candidate, string $baseline): bool
    {
        return self::rank($candidate) > self::rank($baseline);
    }
}
