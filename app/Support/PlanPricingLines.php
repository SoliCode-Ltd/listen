<?php

namespace App\Support;

use App\Models\Plan;
use App\Services\VoiceCloningService;
use App\Support\Agents\AgentAccess;
use App\Support\Api\ApiPlanAccess;
use App\Support\Transcription\TranscriptionAccess;
use App\Support\Transcription\TranscriptionSettings;

/**
 * The feature lines on a plan's pricing card, written from the plan's own
 * settings in English and Arabic. Each line has a "show on the pricing page"
 * switch in Admin → Plans → Pricing page, and the admin can add extra lines.
 * A plan can instead show hand-written lists ("Use my own text instead").
 */
final class PlanPricingLines
{
    /**
     * Every line the card can show, in card order: key => [icon, shown by default].
     *
     * @var array<string, array{0:string, 1:bool}>
     */
    public const LINES = [
        'voice_quality' => ['mic', true],
        'voice_minutes' => ['clock', true],
        'max_chars_per_request' => ['type', true],
        'monthly_requests' => ['activity', true],
        'daily_requests' => ['activity', true],
        'voice_controls' => ['heart', true],
        // Off by default: the studio makes every plan's audio as fast; the priority only
        // picks the queue of the API's and the extension's background generations.
        'processing_priority' => ['bolt', false],
        'commercial_use' => ['lock', true],
        'audio_kept' => ['clock', true],
        'dialogue' => ['users', true],
        'custom_prompt' => ['sliders', true],
        'sound_environments' => ['sliders', true],
        'voice_cloning' => ['star', true],
        // Shown on every plan that includes it, once Voice Design is open to everyone.
        'voice_design' => ['sliders', true],
        // Shown on every plan that includes dubbing, once Video Dubbing is open to everyone.
        'video_dubbing' => ['mic', true],
        // Shown on every plan that includes speech to text (Plans → Speech to text), with its minutes,
        // once Speech to text → Settings opens it to everyone.
        'speech_to_text' => ['type', true],
        // Shown on every plan that includes voice agents (Plans → Voice agents), once Agents are open to everyone.
        'voice_agents' => ['users', true],
        'download_formats' => ['download', true],
        // Shown on every plan that includes the developer API (Plans → Developer API).
        'developer_api' => ['globe', true],
        // Off by default: members cannot buy top-ups yet.
        'topup' => ['download', false],
    ];

    /** Icons an extra line can use (the pricing card draws each one). */
    public const ICONS = [
        'check' => 'Check mark',
        'activity' => 'Activity',
        'type' => 'Text',
        'mic' => 'Microphone',
        'star' => 'Star',
        'heart' => 'Heart',
        'bolt' => 'Lightning',
        'lock' => 'Lock',
        'clock' => 'Clock',
        'globe' => 'Globe',
        'shield' => 'Shield',
        'download' => 'Download',
        'users' => 'People',
        'sliders' => 'Sliders',
    ];

    public const LOCALES = ['en', 'ar'];

    /**
     * @return array<string, string>
     */
    public static function adminOptions(): array
    {
        $options = [];
        foreach (array_keys(self::LINES) as $key) {
            $options[$key] = self::t('label_'.$key, 'en');
        }
        $options['topup'] .= ' (members cannot buy top-ups yet)';
        $options['processing_priority'] .= ' (the studio is as fast on every plan)';

        return $options;
    }

    /**
     * The keys shown when the admin has not chosen yet.
     *
     * @return array<int, string>
     */
    public static function defaultShown(): array
    {
        return array_keys(array_filter(self::LINES, fn (array $line): bool => $line[1]));
    }

    /**
     * Stored switches ({key: bool}) as the list of keys shown. A line added to
     * the catalogue later uses its default until the admin saves the plan.
     *
     * @return array<int, string>
     */
    public static function shownKeys(mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];

        return array_values(array_filter(
            array_keys(self::LINES),
            fn (string $key): bool => array_key_exists($key, $stored) ? (bool) $stored[$key] : self::LINES[$key][1],
        ));
    }

    /**
     * The list of shown keys from the admin form as the stored switches.
     *
     * @return array<string, bool>
     */
    public static function switchesFrom(mixed $shown): array
    {
        $shown = is_array($shown) ? $shown : [];

        $switches = [];
        foreach (array_keys(self::LINES) as $key) {
            $switches[$key] = in_array($key, $shown, true);
        }

        return $switches;
    }

    /**
     * What the plan's card lists in this language.
     *
     * @return array<int, array{label:string, value:string, icon:string}>
     */
    public static function forPlan(Plan $plan, string $locale): array
    {
        $locale = self::locale($locale);

        if ($plan->pricing_use_custom_text) {
            $custom = $plan->getLocalizedFeatures($locale);
            if ($custom !== null) {
                return self::withSpeechToText($plan, self::withoutClosed(self::withLiveValues($plan, array_values($custom), $locale)), $locale);
            }
        }

        $automatic = self::isDemoOnlyFreePlan($plan)
            ? self::demoLines($locale)
            : self::automatic($plan, $locale, self::shownKeys($plan->pricing_lines));

        return array_merge(
            $automatic,
            self::withoutClosed(self::extraLines($plan->{'pricing_extra_lines_'.$locale} ?? [], $locale)),
        );
    }

    /**
     * What the comparison table lists for the plan in this language: the lines
     * shown by default, written from the plan's settings, and the admin's extra
     * lines. Never the hand-written card text, which each plan may word in its
     * own way: a row is then the same line on every plan.
     *
     * @return array<int, array{label:string, value:string, icon:string}>
     */
    public static function forComparison(Plan $plan, string $locale): array
    {
        $locale = self::locale($locale);

        $automatic = self::isDemoOnlyFreePlan($plan)
            ? self::demoLines($locale)
            : self::automatic($plan, $locale, self::defaultShown());

        return array_merge(
            $automatic,
            self::withoutClosed(self::extraLines($plan->{'pricing_extra_lines_'.$locale} ?? [], $locale)),
        );
    }

    /**
     * Speech to text on a card the admin wrote by hand: its line from the plan's own minutes is
     * added (before the API line, or at the end) unless the hand-written text already names it,
     * so no card has to be edited when a plan gets speech to text or its minutes change.
     *
     * @param  array<int, mixed>  $lines
     * @return array<int, mixed>
     */
    private static function withSpeechToText(Plan $plan, array $lines, string $locale): array
    {
        $value = self::value($plan, 'speech_to_text', $locale);
        if ($value === null) {
            return $lines;
        }
        foreach ($lines as $line) {
            $text = is_array($line) ? implode(' ', array_filter([$line['label'] ?? '', $line['value'] ?? '', $line['text'] ?? ''], 'is_string')) : (string) $line;
            if (preg_match(self::SPEECH_TO_TEXT_WORDS, $text)) {
                return $lines;
            }
        }
        $line = ['label' => self::t('label_speech_to_text', $locale), 'value' => $value, 'icon' => self::LINES['speech_to_text'][0]];
        foreach ($lines as $i => $existing) {
            $text = is_array($existing) ? (string) ($existing['label'] ?? '') : (string) $existing;
            if (preg_match('/\bAPI\b|واجهة (المطورين|برمجية)/u', $text)) {
                array_splice($lines, $i, 0, [$line]);

                return $lines;
            }
        }
        $lines[] = $line;

        return $lines;
    }

    /** Words that name speech to text in a hand-written line, in English or Arabic. */
    private const SPEECH_TO_TEXT_WORDS = '/speech[ -]to[ -]text|transcri|تحويل (الكلام|الصوت) إلى نص|تفريغ/iu';

    /**
     * A hand-written line can carry a value written from the plan's own settings: {agents} becomes
     * "3 agents" (Plans → Voice agents), so the card follows
     * the settings. The line is left out where the plan has no agents or Agents are not open yet.
     *
     * @param  array<int, mixed>  $lines
     * @return array<int, mixed>
     */
    private static function withLiveValues(Plan $plan, array $lines, string $locale): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (is_array($line) && str_contains(((string) ($line['label'] ?? '')).((string) ($line['value'] ?? '')), '{agents}')) {
                $agents = self::agentsValue($plan, $locale);
                if ($agents === null) {
                    continue;
                }
                $line['label'] = str_replace('{agents}', $agents, (string) ($line['label'] ?? ''));
                $line['value'] = str_replace('{agents}', $agents, (string) ($line['value'] ?? ''));
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * Lines the admin wrote by hand about a feature not open to everyone yet are left
     * out, as its automatic line is: video dubbing ("Video dubbing: 20 videos", "دبلجة
     * الفيديو") until Video Dubbing → Settings opens it, Voice Design ("Voice Design: 10
     * voices", "تصميم الأصوات") until Voice Design → Settings does.
     *
     * @param  array<int, mixed>  $lines
     * @return array<int, mixed>
     */
    private static function withoutClosed(array $lines): array
    {
        $closed = array_filter([
            StudioFacts::dubbing() ? null : '\bdub|دبلج',
            VoiceDesign::audience() === VoiceDesign::AUDIENCE_EVERYONE ? null : 'voice design|design your own voices|تصميم (ال)?(صوت|أصوات)|صمّ?م أصواتك',
            AgentAccess::audience() === AgentAccess::AUDIENCE_EVERYONE ? null : 'voice agents?|الوكلاء الصوتي|وكلاء صوتي|وكيل صوتي',
            TranscriptionSettings::audience() === TranscriptionSettings::AUDIENCE_EVERYONE ? null : 'speech[ -]to[ -]text|transcri|تحويل (الكلام|الصوت) إلى نص',
        ]);
        if ($closed === []) {
            return $lines;
        }

        return array_values(array_filter($lines, function (mixed $line) use ($closed): bool {
            $text = is_array($line)
                ? implode(' ', array_filter([$line['label'] ?? '', $line['value'] ?? '', $line['text'] ?? ''], 'is_string'))
                : (is_string($line) ? $line : '');

            return ! preg_match('/'.implode('|', $closed).'/iu', $text);
        }));
    }

    /**
     * New members get no monthly characters on the free plan, only the demo,
     * so its card describes the demo instead of a monthly allowance.
     */
    public static function isDemoOnlyFreePlan(Plan $plan): bool
    {
        return ! $plan->isPack()
            && (int) $plan->price_cents <= 0
            && ! FreeAllowance::freePlanGivesMonthlyChars();
    }

    /**
     * @return array<int, array{label:string, value:string, icon:string}>
     */
    private static function demoLines(string $locale): array
    {
        return [
            ['label' => self::t('label_demo', $locale), 'value' => self::t('chars', $locale, ['count' => number_format(DemoAllowance::chars())]), 'icon' => 'mic'],
            ['label' => self::t('label_card_needed', $locale), 'value' => self::t('card_not_needed', $locale), 'icon' => 'lock'],
        ];
    }

    /**
     * The automatic lines for the given keys. A line for something the plan
     * does not include (dialogue on a plan without it, say) is left out.
     *
     * @param  array<int, string>|null  $keys  null for every line
     * @return array<int, array{label:string, value:string, icon:string}>
     */
    public static function automatic(Plan $plan, string $locale, ?array $keys = null): array
    {
        $locale = self::locale($locale);
        $keys ??= array_keys(self::LINES);

        $lines = [];
        foreach (array_keys(self::LINES) as $key) {
            if (! in_array($key, $keys, true)) {
                continue;
            }
            $value = self::value($plan, $key, $locale);
            if ($value === null) {
                continue;
            }
            $lines[] = [
                'label' => self::t('label_'.$key, $locale),
                'value' => $value,
                'icon' => self::LINES[$key][0],
            ];
        }

        return $lines;
    }

    /**
     * The line's value for this plan, or null when the plan does not include it.
     */
    public static function value(Plan $plan, string $key, string $locale): ?string
    {
        $premium = VoiceTier::normalize((string) ($plan->voice_tier ?: VoiceTier::STANDARD)) === VoiceTier::HD;

        return match ($key) {
            'voice_quality' => self::t($premium ? 'premium' : 'standard', $locale),
            // The credits also set the month's audio: one minute per Credits::PER_MINUTE.
            'voice_minutes' => ! $plan->isPack() && Credits::minutesFor((int) $plan->monthly_chars) > 0
                ? self::t('voice_minutes_value', $locale, ['count' => number_format(Credits::minutesFor((int) $plan->monthly_chars))])
                : null,
            'max_chars_per_request' => (int) $plan->max_chars_per_request > 0
                ? self::t('chars', $locale, ['count' => number_format((int) $plan->max_chars_per_request)])
                : null,
            // A pack has no monthly count: it lasts until its characters or days run out.
            'monthly_requests' => $plan->isPack() ? null : ((int) $plan->monthly_requests <= 0
                ? self::t('unlimited', $locale)
                : number_format((int) $plan->monthly_requests)),
            'daily_requests' => (int) ($plan->daily_requests ?? 0) > 0 ? number_format((int) $plan->daily_requests) : null,
            'voice_controls' => $premium ? self::t('full_control', $locale) : null,
            'processing_priority' => self::t(
                in_array((string) $plan->processing_priority, ['high', 'priority', 'highest'], true) ? 'priority_high' : 'priority_standard',
                $locale
            ),
            'commercial_use' => self::t($plan->commercial_use_allowed ? 'commercial_yes' : 'commercial_no', $locale),
            'audio_kept' => (int) ($plan->recent_voices_days ?? 7) <= 0
                ? self::t('kept_no_limit', $locale)
                : trans_choice('ui.pricing.lines.kept_days', (int) ($plan->recent_voices_days ?? 7), [], $locale),
            'dialogue' => $plan->dialogue_enabled ? self::t('included', $locale) : null,
            // The same rule as the studio and the developer API (Plan::allowsCustomPrompt).
            'custom_prompt' => $premium && $plan->allowsCustomPrompt() ? self::t('included', $locale) : null,
            // As in the studio: the plan includes them and this server can make them (ffmpeg).
            'sound_environments' => PlanFeatures::planOffers($plan, PlanFeatures::EFFECTS) && AudioEffects::available()
                ? self::t('included', $locale)
                : null,
            'voice_cloning' => self::cloningOpen($plan)
                ? trans_choice('ui.pricing.lines.cloning_voices', VoiceCloningService::maxVoicesForPlan($plan), [], $locale)
                : null,
            'voice_design' => VoiceDesign::planIncludes($plan) && VoiceDesign::audience() === VoiceDesign::AUDIENCE_EVERYONE
                ? self::designValue(VoiceDesign::isUnlimited($plan), VoiceDesign::monthlyLimit($plan), VoiceDesign::maxVoices($plan), $locale)
                : null,
            'download_formats' => implode(', ', array_map(
                fn (string $format): string => explode(' ', AudioFormats::ALL[$format]['label'])[0],
                AudioFormats::availableFor($plan),
            )),
            'developer_api' => ApiPlanAccess::enabled($plan) ? self::t('included', $locale) : null,
            'video_dubbing' => VideoDubbing::planIncludes($plan) && VideoDubbing::audience() === VideoDubbing::AUDIENCE_EVERYONE
                ? (VideoDubbing::monthlyLimit($plan) > 0
                    ? trans_choice('ui.pricing.lines.dubbing_videos', VideoDubbing::monthlyLimit($plan), ['seconds' => VideoDubbing::maxSeconds($plan, false)], $locale)
                    : self::t('dubbing_open', $locale, ['seconds' => VideoDubbing::maxSeconds($plan, false)]))
                : null,
            // The plan's own minutes (Plans → Speech to text), once it is open to everyone.
            'speech_to_text' => TranscriptionAccess::planColumns()
                && TranscriptionAccess::planIncludes($plan)
                && TranscriptionSettings::audience() === TranscriptionSettings::AUDIENCE_EVERYONE
                ? trans_choice('ui.pricing.lines.stt_minutes', TranscriptionAccess::monthlyMinutes($plan),
                    ['count' => number_format(TranscriptionAccess::monthlyMinutes($plan))], self::locale($locale))
                : null,
            'voice_agents' => self::agentsValue($plan, $locale),
            'topup' => ! $plan->isPack() && self::topupCents($plan) > 0
                ? self::t('topup_price', $locale, ['price' => '$'.number_format(self::topupCents($plan) / 100, 0)])
                : null,
            default => null,
        };
    }

    /**
     * Why a ticked line is missing from this plan's card, for the admin; null when it shows.
     */
    public static function whyHidden(Plan $plan, string $key): ?string
    {
        if (self::value($plan, $key, 'en') !== null) {
            return null;
        }

        $premium = VoiceTier::normalize((string) ($plan->voice_tier ?: VoiceTier::STANDARD)) === VoiceTier::HD;

        return match ($key) {
            'voice_controls' => 'Hidden: only the premium voice has these controls.',
            'voice_minutes' => 'Hidden: the plan has too few credits for a full minute.',
            'max_chars_per_request' => 'Hidden: no maximum is set.',
            'dialogue' => 'Hidden: this plan does not include dialogue.',
            'custom_prompt' => $premium ? 'Hidden: this plan does not include custom direction.' : 'Hidden: only the premium voice takes custom direction.',
            'sound_environments' => AudioEffects::available()
                ? 'Hidden: this plan does not include sound environments.'
                : 'Hidden: this server cannot make sound environments (ffmpeg is missing).',
            'voice_cloning' => ! VoiceCloningService::planAllows($plan)
                ? 'Hidden: this plan does not include voice cloning (or cloning is switched off site-wide).'
                : 'Hidden: voice cloning is still a private preview, so members on this plan cannot use it yet.',
            'voice_design' => VoiceDesign::planIncludes($plan)
                ? 'Hidden: Voice Design is still for the admin only (Voice Design → Settings).'
                : 'Hidden: this plan does not include Voice Design.',
            'topup' => 'Hidden: no top-up price is set.',
            'video_dubbing' => VideoDubbing::planIncludes($plan)
                ? 'Hidden: Video Dubbing is still for the admin only (Video Dubbing → Settings).'
                : 'Hidden: this plan does not include video dubbing.',
            'voice_agents' => AgentAccess::planColumns() && AgentAccess::planIncludes($plan)
                ? 'Hidden: Agents are not open to everyone yet (Agents → Settings).'
                : 'Hidden: this plan does not include voice agents.',
            'speech_to_text' => TranscriptionAccess::planColumns() && TranscriptionAccess::planIncludes($plan)
                ? 'Hidden: Speech to text is not open to everyone yet (Speech to text → Settings).'
                : 'Hidden: this plan does not include speech to text.',
            'monthly_requests' => 'Hidden: a pack has no monthly count.',
            'daily_requests' => 'Hidden: no daily limit is set (Usage Limits → Generations per day).',
            default => 'Hidden for this plan.',
        };
    }

    /**
     * What a plan's Voice Design line says: no limit, the new voices a month, or (with no
     * monthly count) the voices a member keeps.
     */
    public static function designValue(bool $unlimited, int $monthly, int $kept, string $locale): string
    {
        $locale = self::locale($locale);
        if ($unlimited) {
            return self::t('unlimited', $locale);
        }

        return $monthly > 0
            ? trans_choice('ui.pricing.lines.design_voices_month', $monthly, [], $locale)
            : trans_choice('ui.pricing.lines.design_voices', $kept, [], $locale);
    }

    /**
     * "3 agents": the agents a member keeps (Plans → Voice agents). The call minutes are in the
     * comparison table's own rows. Only on a plan with agents, once Agents are open to everyone.
     */
    private static function agentsValue(Plan $plan, string $locale): ?string
    {
        if (! AgentAccess::planColumns() || ! AgentAccess::planIncludes($plan)
            || AgentAccess::audience() !== AgentAccess::AUDIENCE_EVERYONE) {
            return null;
        }
        $limits = AgentAccess::limits($plan);
        $minutes = intdiv(max(0, (int) $plan->monthly_chars), AgentAccess::creditsPerMinute());
        if ($limits['minutes'] > 0) {
            $minutes = min($minutes, $limits['minutes']);
        }

        return trans_choice('ui.pricing.lines.agents_value', $limits['agents'], ['count' => $limits['agents'], 'minutes' => number_format($minutes)], self::locale($locale));
    }

    /**
     * The comparison table's Voice agents rows, from the plan's own settings (Plans → Voice agents)
     * and the call rate, so the table follows any change there: the agents, the call minutes its
     * credits pay for (or its own monthly call minutes, when fewer), the calls at once, the longest
     * call and what a call minute costs. Null where the plan has no agents or Agents are not open
     * to everyone yet.
     *
     * @return array{agents: string, minutes: string, concurrent: string, longest: string, rate: string}|null
     */
    public static function agentComparison(Plan $plan, string $locale): ?array
    {
        if (! AgentAccess::planColumns() || ! AgentAccess::planIncludes($plan)
            || AgentAccess::audience() !== AgentAccess::AUDIENCE_EVERYONE) {
            return null;
        }
        $locale = self::locale($locale);
        $limits = AgentAccess::limits($plan);
        $rate = AgentAccess::creditsPerMinute();
        $minutes = intdiv(max(0, (int) $plan->monthly_chars), $rate);
        if ($limits['minutes'] > 0) {
            $minutes = min($minutes, $limits['minutes']);
        }
        $longest = max(1, intdiv($limits['call_seconds'], 60));

        return [
            'agents' => number_format($limits['agents']),
            'minutes' => __('ui.pricing.compare_agents_minutes_value', ['count' => number_format($minutes)], $locale),
            'concurrent' => number_format($limits['concurrent']),
            'longest' => trans_choice('ui.pricing.compare_agents_longest_value', $longest, ['count' => number_format($longest)], $locale),
            'rate' => __('ui.pricing.compare_agents_rate_value', ['credits' => number_format($rate)], $locale),
        ];
    }

    private static function cloningOpen(Plan $plan): bool
    {
        return VoiceCloningService::planAllows($plan) && ! VoiceCloningAccess::isPreviewOnly();
    }

    /**
     * The top-up price per million characters, falling back to the overage price.
     */
    private static function topupCents(Plan $plan): int
    {
        if ($plan->topup_char_price_cents !== null) {
            return (int) $plan->topup_char_price_cents;
        }

        return $plan->allow_overage && $plan->overage_price_per_million_cents !== null
            ? (int) $plan->overage_price_per_million_cents
            : 0;
    }

    /**
     * The admin's extra lines; an empty value shows a check mark.
     *
     * @return array<int, array{label:string, value:string, icon:string}>
     */
    public static function extraLines(mixed $items, string $locale): array
    {
        $lines = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $label = trim((string) (is_array($item) ? ($item['label'] ?? '') : ''));
            if ($label === '') {
                continue;
            }
            $value = trim((string) ($item['value'] ?? ''));
            $icon = (string) ($item['icon'] ?? 'check');
            $lines[] = [
                'label' => $label,
                'value' => $value !== '' ? $value : self::t('included', self::locale($locale)),
                'icon' => array_key_exists($icon, self::ICONS) ? $icon : 'check',
            ];
        }

        return $lines;
    }

    /**
     * A line's label as a key for matching the same line across plans, so a label typed
     * a little differently on each plan ("التوليدات يومياً", "التوليدات يوميا", "Daily
     * generations ") is still one line: case, spaces, Arabic marks, the forms of alef
     * and a final ى do not count.
     */
    public static function labelKey(string $label): string
    {
        $key = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $label)));
        $key = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $key);
        $key = strtr($key, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي']);

        return rtrim($key, ' :.');
    }

    private static function locale(string $locale): string
    {
        return in_array($locale, self::LOCALES, true) ? $locale : 'en';
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private static function t(string $key, string $locale, array $replace = []): string
    {
        return (string) __('ui.pricing.lines.'.$key, $replace, $locale);
    }
}
