<?php

namespace App\Support\Api;

use App\Support\VoiceTier;

/**
 * The developer API's speech model. The API offers the premium studio voices
 * only, as one versioned model, so a future model can sit beside it without
 * changing what existing integrations get.
 */
final class ApiModels
{
    public const PREMIUM = 'listen_tts_premium_v1';

    public const NAME = 'Listen TTS Premium';

    /**
     * @return array<int, string>
     */
    public static function ids(): array
    {
        return [self::PREMIUM];
    }

    /** The model of a voice tier; null for voices the API does not offer (standard). */
    public static function forTier(?string $tier): ?string
    {
        return VoiceTier::normalize($tier) === VoiceTier::HD ? self::PREMIUM : null;
    }
}
