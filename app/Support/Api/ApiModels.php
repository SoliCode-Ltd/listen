<?php

namespace App\Support\Api;

use App\Support\VoiceTier;

/**
 * The API's model ids for the two voice tiers. Each voice belongs to one tier,
 * so a voice decides its model; model_id in a request only has to agree.
 */
final class ApiModels
{
    public const STANDARD = 'listen_standard';

    public const PREMIUM = 'listen_premium';

    /** @var array<string, string> model id => voice tier */
    private const TIERS = [
        self::STANDARD => VoiceTier::STANDARD,
        self::PREMIUM => VoiceTier::HD,
    ];

    /**
     * @return array<int, string>
     */
    public static function ids(): array
    {
        return array_keys(self::TIERS);
    }

    public static function tierFor(string $modelId): ?string
    {
        return self::TIERS[$modelId] ?? null;
    }

    public static function forTier(?string $tier): string
    {
        return VoiceTier::normalize($tier) === VoiceTier::HD ? self::PREMIUM : self::STANDARD;
    }
}
