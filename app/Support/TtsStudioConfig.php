<?php

namespace App\Support;

use App\Models\SiteSetting;

class TtsStudioConfig
{
    /**
     * @var array<int, array{value:string,label:string,translation_key:?string}>
     */
    private const DEFAULT_PERFORMANCE_STYLES = [
        ['value' => 'neutral', 'label' => 'Neutral', 'translation_key' => 'ui.dashboard.emotion_neutral'],
        ['value' => 'educational', 'label' => 'Educational', 'translation_key' => 'ui.dashboard.style_educational'],
        ['value' => 'commercial', 'label' => 'Commercial', 'translation_key' => 'ui.dashboard.style_commercial'],
        ['value' => 'promo', 'label' => 'Promotional', 'translation_key' => 'ui.dashboard.style_promo'],
        ['value' => 'podcast', 'label' => 'Podcast', 'translation_key' => 'ui.dashboard.style_podcast'],
        ['value' => 'assistant', 'label' => 'Virtual Assistant', 'translation_key' => 'ui.dashboard.style_assistant'],
        ['value' => 'narrative', 'label' => 'Narrative', 'translation_key' => 'ui.dashboard.style_narrative'],
        ['value' => 'comedic', 'label' => 'Comedic', 'translation_key' => 'ui.dashboard.style_comedic'],
        ['value' => 'corporate', 'label' => 'Corporate', 'translation_key' => 'ui.dashboard.style_corporate'],
        ['value' => 'conversational', 'label' => 'Conversational', 'translation_key' => 'ui.dashboard.style_conversational'],
        ['value' => 'inspirational', 'label' => 'Inspirational', 'translation_key' => 'ui.dashboard.style_inspirational'],
        ['value' => 'cinematic', 'label' => 'Cinematic', 'translation_key' => 'ui.dashboard.style_cinematic'],
        ['value' => 'documentary', 'label' => 'Documentary', 'translation_key' => 'ui.dashboard.style_documentary'],
        ['value' => 'storytelling', 'label' => 'Storytelling', 'translation_key' => 'ui.dashboard.emotion_storytelling'],
        ['value' => 'ad_style', 'label' => 'Ad Style', 'translation_key' => 'ui.dashboard.emotion_ad_style'],
        ['value' => 'news', 'label' => 'News', 'translation_key' => 'ui.dashboard.emotion_news'],
        ['value' => 'sports_commentator', 'label' => 'Sports Commentator', 'translation_key' => 'ui.dashboard.style_sports_commentator'],
        ['value' => 'meditation', 'label' => 'Meditation', 'translation_key' => 'ui.dashboard.style_meditation'],
        ['value' => 'audiobook', 'label' => 'Audiobook', 'translation_key' => 'ui.dashboard.style_audiobook'],
        ['value' => 'children_story', 'label' => 'Children Story', 'translation_key' => 'ui.dashboard.style_children_story'],
        ['value' => 'radio_host', 'label' => 'Radio Host', 'translation_key' => 'ui.dashboard.style_radio_host'],
        ['value' => 'movie_trailer', 'label' => 'Movie Trailer', 'translation_key' => 'ui.dashboard.style_movie_trailer'],
        ['value' => 'poetry', 'label' => 'Poetry Recital', 'translation_key' => 'ui.dashboard.style_poetry'],
        ['value' => 'bedtime_story', 'label' => 'Bedtime Story', 'translation_key' => 'ui.dashboard.style_bedtime_story'],
        ['value' => 'customer_service', 'label' => 'Customer Service', 'translation_key' => 'ui.dashboard.style_customer_service'],
        ['value' => 'phone_system', 'label' => 'Phone System', 'translation_key' => 'ui.dashboard.style_phone_system'],
        ['value' => 'game_narrator', 'label' => 'Game Narrator', 'translation_key' => 'ui.dashboard.style_game_narrator'],
        ['value' => 'asmr', 'label' => 'ASMR', 'translation_key' => 'ui.dashboard.style_asmr'],
        ['value' => 'event_host', 'label' => 'Event Host', 'translation_key' => 'ui.dashboard.style_event_host'],
        ['value' => 'tour_guide', 'label' => 'Tour Guide', 'translation_key' => 'ui.dashboard.style_tour_guide'],
        ['value' => 'true_crime', 'label' => 'True Crime', 'translation_key' => 'ui.dashboard.style_true_crime'],
        ['value' => 'luxury_brand', 'label' => 'Luxury Brand', 'translation_key' => 'ui.dashboard.style_luxury_brand'],
    ];

    /**
     * @var array<int, array{value:string,label:string,translation_key:?string}>
     */
    private const DEFAULT_EMOTIONS = [
        ['value' => 'neutral', 'label' => 'Neutral', 'translation_key' => 'ui.dashboard.emotion_neutral'],
        ['value' => 'warm', 'label' => 'Warm', 'translation_key' => 'ui.dashboard.emotion_warm'],
        ['value' => 'sad', 'label' => 'Sad', 'translation_key' => 'ui.dashboard.emotion_sad'],
        ['value' => 'excited', 'label' => 'Excited', 'translation_key' => 'ui.dashboard.emotion_excited'],
        ['value' => 'formal', 'label' => 'Formal', 'translation_key' => 'ui.dashboard.emotion_formal'],
        ['value' => 'calm', 'label' => 'Calm', 'translation_key' => 'ui.dashboard.emotion_calm'],
        ['value' => 'angry', 'label' => 'Angry', 'translation_key' => 'ui.dashboard.emotion_angry'],
        ['value' => 'hopeful', 'label' => 'Hopeful', 'translation_key' => 'ui.dashboard.emotion_hopeful'],
        ['value' => 'serious', 'label' => 'Serious', 'translation_key' => 'ui.dashboard.emotion_serious'],
        ['value' => 'playful', 'label' => 'Playful', 'translation_key' => 'ui.dashboard.emotion_playful'],
        ['value' => 'mysterious', 'label' => 'Mysterious', 'translation_key' => 'ui.dashboard.emotion_mysterious'],
        ['value' => 'confident', 'label' => 'Confident', 'translation_key' => 'ui.dashboard.emotion_confident'],
        ['value' => 'joyful', 'label' => 'Joyful', 'translation_key' => 'ui.dashboard.emotion_joyful'],
        ['value' => 'tender', 'label' => 'Tender', 'translation_key' => 'ui.dashboard.emotion_tender'],
        ['value' => 'empathetic', 'label' => 'Empathetic', 'translation_key' => 'ui.dashboard.emotion_empathetic'],
        ['value' => 'romantic', 'label' => 'Romantic', 'translation_key' => 'ui.dashboard.emotion_romantic'],
        ['value' => 'nostalgic', 'label' => 'Nostalgic', 'translation_key' => 'ui.dashboard.emotion_nostalgic'],
        ['value' => 'proud', 'label' => 'Proud', 'translation_key' => 'ui.dashboard.emotion_proud'],
        ['value' => 'grateful', 'label' => 'Grateful', 'translation_key' => 'ui.dashboard.emotion_grateful'],
        ['value' => 'determined', 'label' => 'Determined', 'translation_key' => 'ui.dashboard.emotion_determined'],
        ['value' => 'anxious', 'label' => 'Anxious', 'translation_key' => 'ui.dashboard.emotion_anxious'],
        ['value' => 'fearful', 'label' => 'Fearful', 'translation_key' => 'ui.dashboard.emotion_fearful'],
        ['value' => 'surprised', 'label' => 'Surprised', 'translation_key' => 'ui.dashboard.emotion_surprised'],
        ['value' => 'sarcastic', 'label' => 'Sarcastic', 'translation_key' => 'ui.dashboard.emotion_sarcastic'],
        ['value' => 'suspenseful', 'label' => 'Suspenseful', 'translation_key' => 'ui.dashboard.emotion_suspenseful'],
    ];

    /**
     * @var array<string, array<int, array{value:string,label:string,translation_key:?string}>>
     */
    private const DEFAULT_DIALECTS_BY_LANGUAGE = [
        'ar' => [
            ['value' => 'ar-XA', 'label' => 'Modern Standard Arabic', 'translation_key' => 'ui.dialects.msa'],
            ['value' => 'ar-SA', 'label' => 'Saudi', 'translation_key' => 'ui.dialects.saudi'],
            ['value' => 'ar-AE', 'label' => 'Emirati', 'translation_key' => 'ui.dialects.emirati'],
            ['value' => 'ar-EG', 'label' => 'Egyptian', 'translation_key' => 'ui.dialects.egyptian'],
            ['value' => 'ar-LB', 'label' => 'Lebanese', 'translation_key' => 'ui.dialects.lebanese'],
            ['value' => 'ar-SY', 'label' => 'Syrian', 'translation_key' => 'ui.dialects.syrian'],
            ['value' => 'ar-JO', 'label' => 'Jordanian', 'translation_key' => 'ui.dialects.jordanian'],
            ['value' => 'ar-IQ', 'label' => 'Iraqi', 'translation_key' => 'ui.dialects.iraqi'],
            ['value' => 'ar-MA', 'label' => 'Moroccan', 'translation_key' => 'ui.dialects.moroccan'],
            ['value' => 'ar-KW', 'label' => 'Kuwaiti', 'translation_key' => 'ui.dialects.kuwaiti'],
            ['value' => 'ar-TN', 'label' => 'Tunisian', 'translation_key' => 'ui.dialects.tunisian'],
            ['value' => 'ar-OM', 'label' => 'Omani', 'translation_key' => 'ui.dialects.omani'],
        ],
        'en' => [
            ['value' => 'en-US', 'label' => 'American', 'translation_key' => 'ui.dialects.american'],
            ['value' => 'en-GB', 'label' => 'British', 'translation_key' => 'ui.dialects.british'],
            ['value' => 'en-AU', 'label' => 'Australian', 'translation_key' => 'ui.dialects.australian'],
        ],
    ];

    /**
     * @return array<int, string>
     */
    public static function performanceStyleValues(): array
    {
        return array_values(array_map(
            static fn (array $row): string => $row['value'],
            self::performanceStyleCatalog()
        ));
    }

    /**
     * @return array<int, string>
     */
    public static function emotionValues(): array
    {
        return array_values(array_map(
            static fn (array $row): string => $row['value'],
            self::emotionCatalog()
        ));
    }

    /**
     * @return array<int, string>
     */
    public static function dialectValues(?string $language = null): array
    {
        if ($language !== null && $language !== '') {
            return array_values(array_map(
                static fn (array $row): string => $row['value'],
                self::dialectCatalogForLanguage($language)
            ));
        }

        $values = [];
        foreach (self::dialectCatalogByLanguage() as $rows) {
            foreach ($rows as $row) {
                $values[] = $row['value'];
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @return array<int, array{value:string,label:string,label_ar:string,instruction_en:string,instruction_ar:string}>
     */
    public static function performanceStyleCatalog(): array
    {
        $custom = self::normalizeCatalogRows(self::decodeJsonArraySetting('tts_performance_styles_catalog'));

        return self::mergeCatalogRows(self::DEFAULT_PERFORMANCE_STYLES, $custom);
    }

    /**
     * @return array<int, array{value:string,label:string,label_ar:string,instruction_en:string,instruction_ar:string}>
     */
    public static function emotionCatalog(): array
    {
        $custom = self::normalizeCatalogRows(self::decodeJsonArraySetting('tts_emotions_catalog'));

        return self::mergeCatalogRows(self::DEFAULT_EMOTIONS, $custom);
    }

    /**
     * @return array<string, array<int, array{value:string,label:string,label_ar:string,instruction_en:string,instruction_ar:string}>>
     */
    public static function dialectCatalogByLanguage(): array
    {
        return [
            'ar' => self::dialectCatalogForLanguage('ar'),
            'en' => self::dialectCatalogForLanguage('en'),
        ];
    }

    /**
     * @return array<int, array{value:string,label:string,label_ar:string,instruction_en:string,instruction_ar:string}>
     */
    public static function dialectCatalogForLanguage(string $language): array
    {
        $normalizedLanguage = self::normalizeLanguage($language);
        $customKey = "tts_dialects_catalog_{$normalizedLanguage}";
        $custom = self::normalizeCatalogRows(self::decodeJsonArraySetting($customKey));
        $defaults = match ($normalizedLanguage) {
            'ar' => ArabicDialects::catalogRows(),
            'en' => EnglishAccents::catalogRows(),
            default => self::DEFAULT_DIALECTS_BY_LANGUAGE[$normalizedLanguage] ?? [],
        };

        return self::mergeCatalogRows($defaults, $custom);
    }

    public static function defaultPerformanceStyle(string $language): string
    {
        $normalizedLanguage = self::normalizeLanguage($language);
        $setting = trim((string) SiteSetting::get(
            "tts_default_performance_style_{$normalizedLanguage}",
            $normalizedLanguage === 'ar' ? 'promo' : 'neutral'
        ));

        return in_array($setting, self::performanceStyleValues(), true)
            ? $setting
            : ($normalizedLanguage === 'ar' ? 'promo' : 'neutral');
    }

    /**
     * Style and emotion left on "Default" become the site's defaults for the language.
     *
     * @param  array<string, mixed>  $input
     * @return array{performance_style: string, emotion: string}
     */
    public static function withDefaultChoices(array $input, ?string $language): array
    {
        $language = (string) ($language ?: 'ar');
        $style = is_string($input['performance_style'] ?? null) ? trim($input['performance_style']) : '';
        $emotion = is_string($input['emotion'] ?? null) ? trim($input['emotion']) : '';

        return [
            'performance_style' => $style !== '' ? $style : self::defaultPerformanceStyle($language),
            'emotion' => $emotion !== '' ? $emotion : self::defaultEmotion($language),
        ];
    }

    public static function defaultEmotion(string $language): string
    {
        $normalizedLanguage = self::normalizeLanguage($language);
        $setting = trim((string) SiteSetting::get(
            "tts_default_emotion_{$normalizedLanguage}",
            $normalizedLanguage === 'ar' ? 'confident' : 'neutral'
        ));

        return in_array($setting, self::emotionValues(), true)
            ? $setting
            : ($normalizedLanguage === 'ar' ? 'confident' : 'neutral');
    }

    public static function defaultDialect(string $language): string
    {
        $normalizedLanguage = self::normalizeLanguage($language);
        $fallback = $normalizedLanguage === 'ar' ? 'ar-EG' : '';
        $setting = trim((string) SiteSetting::get("tts_default_dialect_{$normalizedLanguage}", $fallback));

        return in_array($setting, self::dialectValues($normalizedLanguage), true) ? $setting : $fallback;
    }

    public static function defaultVoiceName(string $language): string
    {
        $normalizedLanguage = self::normalizeLanguage($language);

        return trim((string) SiteSetting::get("tts_default_voice_{$normalizedLanguage}", ''));
    }

    public static function performanceStyleInstruction(string $value, ?string $language = null): string
    {
        $targetValue = self::normalizeSlug($value);
        if ($targetValue === '') {
            return '';
        }

        foreach (self::performanceStyleCatalog() as $row) {
            if (self::normalizeSlug((string) ($row['value'] ?? '')) !== $targetValue) {
                continue;
            }

            return self::resolveInstruction($row, $language);
        }

        return '';
    }

    public static function emotionInstruction(string $value, ?string $language = null): string
    {
        $targetValue = self::normalizeSlug($value);
        if ($targetValue === '') {
            return '';
        }

        foreach (self::emotionCatalog() as $row) {
            if (self::normalizeSlug((string) ($row['value'] ?? '')) !== $targetValue) {
                continue;
            }

            return self::resolveInstruction($row, $language);
        }

        return '';
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array{value:string,label:string,label_ar:string,instruction_en:string,instruction_ar:string}>
     */
    private static function normalizeCatalogRows(array $rows): array
    {
        $normalized = [];
        $seen = [];

        foreach ($rows as $row) {
            if (is_string($row)) {
                $value = self::normalizeSlug($row);
                if ($value === '' || isset($seen[$value])) {
                    continue;
                }

                $seen[$value] = true;
                $normalized[] = [
                    'value' => $value,
                    'label' => self::humanizeValue($value),
                    'label_ar' => self::humanizeValue($value),
                    'instruction_en' => '',
                    'instruction_ar' => '',
                ];
                continue;
            }

            if (! is_array($row)) {
                continue;
            }

            $value = self::normalizeSlug((string) ($row['value'] ?? $row['code'] ?? ''));
            if ($value === '' || isset($seen[$value])) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? $row['name'] ?? ''));
            $labelAr = trim((string) ($row['label_ar'] ?? $row['name_ar'] ?? ''));
            $instructionEn = trim((string) ($row['instruction_en'] ?? $row['instruction'] ?? $row['description'] ?? $row['prompt'] ?? $row['prompt_en'] ?? ''));
            $instructionAr = trim((string) ($row['instruction_ar'] ?? $row['description_ar'] ?? $row['prompt_ar'] ?? ''));
            if ($label === '') {
                $label = self::humanizeValue($value);
            }
            if ($labelAr === '') {
                $labelAr = $label;
            }
            if ($instructionAr === '') {
                $instructionAr = $instructionEn;
            }

            $seen[$value] = true;
            $normalized[] = [
                'value' => $value,
                'label' => $label,
                'label_ar' => $labelAr,
                'instruction_en' => $instructionEn,
                'instruction_ar' => $instructionAr,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{value:string,label:string,translation_key:?string}>  $defaults
     * @param  array<int, array{value:string,label:string,label_ar:string,instruction_en:string,instruction_ar:string}>  $custom
     * @return array<int, array{value:string,label:string,label_ar:string,instruction_en:string,instruction_ar:string}>
     */
    private static function mergeCatalogRows(array $defaults, array $custom): array
    {
        $mergedByValue = [];

        foreach ($defaults as $row) {
            $value = self::normalizeSlug((string) ($row['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            $fallback = trim((string) ($row['label'] ?? self::humanizeValue($value)));
            $translationKey = trim((string) ($row['translation_key'] ?? ''));
            $translated = $translationKey !== ''
                ? trim((string) __($translationKey, ['default' => $fallback]))
                : $fallback;
            if ($translated === '' || ($translationKey !== '' && $translated === $translationKey)) {
                $translated = $fallback !== '' ? $fallback : self::humanizeValue($value);
            }

            // Both languages, whatever the page's language (the extension switches on its own).
            $inLocale = function (string $locale) use ($translationKey, $translated): string {
                if ($translationKey === '') {
                    return $translated;
                }
                $text = trim((string) __($translationKey, [], $locale));

                return $text !== '' && $text !== $translationKey ? $text : $translated;
            };

            $mergedByValue[$value] = [
                'value' => $value,
                'label' => $translated,
                'label_en' => trim((string) ($row['label_en'] ?? '')) ?: $inLocale('en'),
                'label_ar' => trim((string) ($row['label_ar'] ?? '')) ?: $inLocale('ar'),
                'instruction_en' => '',
                'instruction_ar' => '',
                'region' => (string) ($row['region'] ?? ''),
            ];
        }

        foreach ($custom as $row) {
            $value = self::normalizeSlug((string) ($row['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            $mergedByValue[$value] = [
                'value' => $value,
                'label' => trim((string) ($row['label'] ?? self::humanizeValue($value))),
                'label_en' => trim((string) ($row['label'] ?? self::humanizeValue($value))),
                'label_ar' => trim((string) ($row['label_ar'] ?? $row['label'] ?? self::humanizeValue($value))),
                'instruction_en' => trim((string) ($row['instruction_en'] ?? '')),
                'instruction_ar' => trim((string) ($row['instruction_ar'] ?? $row['instruction_en'] ?? '')),
                'region' => (string) ($mergedByValue[$value]['region'] ?? ''),
            ];
        }

        return array_values($mergedByValue);
    }

    /**
     * @return array<int, mixed>
     */
    private static function decodeJsonArraySetting(string $key): array
    {
        $raw = SiteSetting::get($key, '');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private static function normalizeLanguage(string $language): string
    {
        return strtolower(trim($language)) === 'en' ? 'en' : 'ar';
    }

    private static function normalizeSlug(string $value): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return '';
        }

        $normalized = str_replace([' ', '.'], ['_', '_'], $normalized);

        return preg_replace('/[^A-Za-z0-9\-_]/', '', $normalized) ?: '';
    }

    private static function humanizeValue(string $value): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return '';
        }

        return ucwords(str_replace(['_', '-'], ' ', $normalized));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function resolveInstruction(array $row, ?string $language = null): string
    {
        $normalizedLanguage = $language !== null && trim($language) !== ''
            ? self::normalizeLanguage($language)
            : self::normalizeLanguage((string) app()->getLocale());

        $instructionEn = trim((string) ($row['instruction_en'] ?? ''));
        $instructionAr = trim((string) ($row['instruction_ar'] ?? ''));

        if ($normalizedLanguage === 'ar') {
            return $instructionAr !== '' ? $instructionAr : $instructionEn;
        }

        return $instructionEn !== '' ? $instructionEn : $instructionAr;
    }
}
