<?php

namespace App\Support;

class VoiceTier
{
    public const STANDARD = 'standard';
    public const HD = 'hd';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::STANDARD,
            self::HD,
        ];
    }

    public static function normalize(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        // Legacy: treat old 'hd_plus' values as 'hd'
        if ($value === 'hd_plus') {
            return self::HD;
        }

        return in_array($value, self::all(), true) ? $value : self::STANDARD;
    }

    public static function label(string $tier): string
    {
        return match (self::normalize($tier)) {
            self::HD => 'HD',
            default => __('ui.pricing.standard'),
        };
    }

    public static function rank(string $tier): int
    {
        return match (self::normalize($tier)) {
            self::HD => 2,
            default => 1,
        };
    }

    public static function isHigherThan(string $candidate, string $baseline): bool
    {
        return self::rank($candidate) > self::rank($baseline);
    }
}
