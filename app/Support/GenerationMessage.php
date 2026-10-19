<?php

namespace App\Support;

/**
 * What a member is told about a voice that failed, in the page's language.
 *
 * Generations keep their message in English, as the developer API shows it
 * (it always answers in English). Older rows may hold one in Arabic, from the
 * language of the page that made them. A known message is recognised in either
 * language and shown in the current one; anything else is shown as it was kept.
 */
final class GenerationMessage
{
    /** The messages GenerateAudioJob and the refunds keep on a generation (cloned_voice_daily_limit: older ones only). */
    private const KEYS = [
        'ui.backend.dashboard_voice_generation_failed_try_again',
        'ui.backend.premium_voices_busy',
        'ui.backend.premium_voices_our_limit',
        'ui.backend.cloned_voices_our_limit',
        'ui.backend.generation_stuck_refunded',
        'ui.backend.cloned_voice_daily_limit',
        'ui.backend.translation_failed',
    ];

    /** Messages only the developer API keeps (in English), with what the studio says for them. */
    private const API_MESSAGES = [
        'Voice generation took too long and was stopped. No credits were charged; please try again.' => 'ui.backend.generation_stuck_refunded',
        'Voice generation could not be started. Please try again.' => 'ui.backend.dashboard_voice_generation_failed_try_again',
    ];

    /** The English text a generation keeps for one of these messages. */
    public static function stored(string $key): string
    {
        return (string) trans($key, [], 'en');
    }

    /** A kept message in the current language, or null when there is none. */
    public static function forDisplay(?string $stored): ?string
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return null;
        }

        if (isset(self::API_MESSAGES[$stored])) {
            return __(self::API_MESSAGES[$stored]);
        }

        foreach (self::KEYS as $key) {
            foreach (['en', 'ar'] as $locale) {
                if ($stored === trans($key, [], $locale)) {
                    return __($key);
                }
            }
        }

        return $stored;
    }
}
