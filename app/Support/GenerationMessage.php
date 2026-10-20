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
        'ui.backend.premium_text_refused',
        'ui.backend.generation_stuck_refunded',
        'ui.backend.cloned_voice_daily_limit',
        'ui.backend.translation_failed',
    ];

    /** Messages only the developer API keeps (in English), with what the studio says for them. */
    private const API_MESSAGES = [
        'Voice generation took too long and was stopped. No credits were charged; please try again.' => 'ui.backend.generation_stuck_refunded',
        'Voice generation could not be started. Please try again.' => 'ui.backend.dashboard_voice_generation_failed_try_again',
    ];

    /** The wording older generations keep (before October 2026), in either language, shown now as today's. */
    private const FORMER = [
        'Voice generation failed. Please try again.' => 'ui.backend.dashboard_voice_generation_failed_try_again',
        'فشل توليد الصوت. يرجى المحاولة مرة أخرى.' => 'ui.backend.dashboard_voice_generation_failed_try_again',
        'Our premium voices are busy right now. Nothing was charged — please try again in a minute.' => 'ui.backend.premium_voices_busy',
        'أصواتنا المميزة مشغولة الآن، ولم يُخصم أي رصيد من حسابك. حاول مرة أخرى بعد دقيقة.' => 'ui.backend.premium_voices_busy',
        "Our premium voices are unavailable right now because of a limit on our side. Nothing was charged, and we're working on it." => 'ui.backend.premium_voices_our_limit',
        'أصواتنا المميزة غير متاحة الآن بسبب حدٍّ من جهتنا، ولم يُخصم أي رصيد من حسابك. نعمل على حل ذلك.' => 'ui.backend.premium_voices_our_limit',
        "Cloned voices are unavailable right now because of a limit on our side. Nothing was charged, and we're working on it." => 'ui.backend.cloned_voices_our_limit',
        'الأصوات المستنسخة غير متاحة الآن بسبب حدٍّ من جهتنا، ولم يُخصم أي رصيد من حسابك. نعمل على حل ذلك.' => 'ui.backend.cloned_voices_our_limit',
        "Our premium voices couldn't read this text, so nothing was charged. Try changing the wording, then generate again." => 'ui.backend.premium_text_refused',
        'تعذّر على أصواتنا المميزة قراءة هذا النص، ولم يُخصم أي رصيد من حسابك. جرّب تغيير صياغته ثم أعد التوليد.' => 'ui.backend.premium_text_refused',
        'This voice took too long, so we stopped it. Nothing was charged — please try again.' => 'ui.backend.generation_stuck_refunded',
        'استغرق هذا الصوت وقتاً أطول من اللازم فأوقفناه، ولم يُخصم أي رصيد من حسابك. حاول مرة أخرى.' => 'ui.backend.generation_stuck_refunded',
        "Cloned voices are unavailable right now: the site's daily limit for premium voices has been reached. Please try again later, or use one of the studio voices for now." => 'ui.backend.cloned_voice_daily_limit',
        'الأصوات المستنسخة غير متاحة الآن لأن الحد اليومي للأصوات المميزة على المنصة قد اكتمل. حاول مرة أخرى لاحقاً، أو استخدم أحد أصوات الاستوديو الآن.' => 'ui.backend.cloned_voice_daily_limit',
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
        if (isset(self::FORMER[$stored])) {
            return __(self::FORMER[$stored]);
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
