<?php

namespace App\Support;

use App\Models\Plan;
use App\Services\VoiceCloningService;

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
        'max_chars_per_request' => ['type', true],
        'monthly_requests' => ['activity', true],
        'voice_controls' => ['heart', true],
        'processing_priority' => ['bolt', true],
        'commercial_use' => ['lock', true],
        'audio_kept' => ['clock', true],
        'dialogue' => ['users', true],
        'custom_prompt' => ['sliders', true],
        'voice_cloning' => ['star', true],
        'download_formats' => ['download', true],
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
                return array_values($custom);
            }
        }

        return array_merge(
            self::automatic($plan, $locale, self::shownKeys($plan->pricing_lines)),
            self::extraLines($plan->{'pricing_extra_lines_'.$locale} ?? [], $locale),
        );
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
            'max_chars_per_request' => (int) $plan->max_chars_per_request > 0
                ? self::t('chars', $locale, ['count' => number_format((int) $plan->max_chars_per_request)])
                : null,
            'monthly_requests' => (int) $plan->monthly_requests <= 0
                ? self::t('unlimited', $locale)
                : number_format((int) $plan->monthly_requests),
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
            'custom_prompt' => $premium && $plan->custom_prompt_enabled ? self::t('included', $locale) : null,
            'voice_cloning' => self::cloningOpen($plan)
                ? trans_choice('ui.pricing.lines.cloning_voices', VoiceCloningService::maxVoicesForPlan($plan), [], $locale)
                : null,
            'download_formats' => implode(', ', array_map(
                fn (string $format): string => explode(' ', AudioFormats::ALL[$format]['label'])[0],
                AudioFormats::availableFor($plan),
            )),
            'topup' => self::topupCents($plan) > 0
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
            'max_chars_per_request' => 'Hidden: no maximum is set.',
            'dialogue' => 'Hidden: this plan does not include dialogue.',
            'custom_prompt' => $premium ? 'Hidden: this plan does not include custom direction.' : 'Hidden: only the premium voice takes custom direction.',
            'voice_cloning' => ! VoiceCloningService::planAllows($plan)
                ? 'Hidden: this plan does not include voice cloning (or cloning is switched off site-wide).'
                : 'Hidden: voice cloning is still a private preview, so members on this plan cannot use it yet.',
            'topup' => 'Hidden: no top-up price is set.',
            default => 'Hidden for this plan.',
        };
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
