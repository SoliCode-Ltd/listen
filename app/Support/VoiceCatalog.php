<?php

namespace App\Support;

use App\Models\SiteSetting;
use App\Models\Voice;

class VoiceCatalog
{
    /**
     * Arabic transliterations of speaker display names.
     */
    private const AR_NAME_MAP = [
        // Arabic female names
        'Sara' => 'سارة',
        'Mariam' => 'مريم',
        'Nour' => 'نور',
        'Layla' => 'ليلى',
        'Farah' => 'فرح',
        'Huda' => 'هدى',
        'Amina' => 'أمينة',
        'Salma' => 'سلمى',
        'Yara' => 'يارا',
        'Dina' => 'دينا',
        'Rania' => 'رانيا',
        'Hana' => 'هناء',
        'Reem' => 'ريم',
        'Amal' => 'أمل',
        'Nada' => 'ندى',
        'Samira' => 'سميرة',
        'Lina' => 'لينا',
        'Zainab' => 'زينب',
        'Aya' => 'آية',
        'Dalia' => 'داليا',
        'Iman' => 'إيمان',
        'Nawal' => 'نوال',
        'Basma' => 'بسمة',
        'Muna' => 'منى',
        'Najwa' => 'نجوى',
        'Sahar' => 'سحر',
        'Wafaa' => 'وفاء',
        'Rasha' => 'رشا',
        'Heba' => 'هبة',
        'Nadine' => 'نادين',
        // Arabic female names – Gemini voices
        'Fatima' => 'فاطمة',
        'Khadija' => 'خديجة',
        'Ruqaya' => 'رقية',
        'Sumaya' => 'سمية',
        'Maha' => 'مها',
        'Aisha' => 'عائشة',
        'Duaa' => 'دعاء',
        'Lamya' => 'لمياء',
        'Lubna' => 'لبنى',
        'Noura' => 'نورة',
        'Abeer' => 'عبير',
        'Manal' => 'منال',
        'Jumana' => 'جمانة',
        'Sawsan' => 'سوسن',
        // Arabic male names
        'Omar' => 'عمر',
        'Ahmed' => 'أحمد',
        'Youssef' => 'يوسف',
        'Khaled' => 'خالد',
        'Karim' => 'كريم',
        'Hamza' => 'حمزة',
        'Ali' => 'علي',
        'Hassan' => 'حسن',
        'Tarek' => 'طارق',
        'Samir' => 'سمير',
        'Fadi' => 'فادي',
        'Ziad' => 'زياد',
        'Majed' => 'ماجد',
        'Nabil' => 'نبيل',
        'Adel' => 'عادل',
        'Bilal' => 'بلال',
        'Rami' => 'رامي',
        'Saad' => 'سعد',
        'Anas' => 'أنس',
        'Marwan' => 'مروان',
        'Hisham' => 'هشام',
        'Imad' => 'عماد',
        'Jamal' => 'جمال',
        'Kamel' => 'كامل',
        'Mazen' => 'مازن',
        'Bader' => 'بدر',
        'Wael' => 'وائل',
        'Nasser' => 'ناصر',
        'Murad' => 'مراد',
        'Riyad' => 'رياض',
        // Arabic male names – Gemini voices
        'Nawaf' => 'نواف',
        'Sultan' => 'سلطان',
        'Turki' => 'تركي',
        'Faisal' => 'فيصل',
        'Waleed' => 'وليد',
        'Mansour' => 'منصور',
        'Saud' => 'سعود',
        'Fahad' => 'فهد',
        'Abdulaziz' => 'عبدالعزيز',
        'Othman' => 'عثمان',
        'Mishary' => 'مشاري',
        'Hamed' => 'حامد',
        'Tamim' => 'تميم',
        'Hazem' => 'حازم',
        'Raed' => 'رائد',
        'Yasser' => 'ياسر',
        // English female names
        'Emma' => 'إيما',
        'Olivia' => 'أوليفيا',
        'Sophia' => 'صوفيا',
        'Ava' => 'آفا',
        'Mia' => 'ميا',
        'Amelia' => 'أميليا',
        'Charlotte' => 'شارلوت',
        'Harper' => 'هاربر',
        'Evelyn' => 'إيفلين',
        'Abigail' => 'أبيغيل',
        'Ella' => 'إيلا',
        'Lily' => 'ليلي',
        'Scarlett' => 'سكارليت',
        'Grace' => 'غريس',
        'Chloe' => 'كلوي',
        'Victoria' => 'فيكتوريا',
        'Zoe' => 'زوي',
        'Hannah' => 'هانا',
        'Aria' => 'آريا',
        'Nora' => 'نورا',
        'Lucy' => 'لوسي',
        'Stella' => 'ستيلا',
        'Hazel' => 'هازل',
        'Penelope' => 'بينيلوبي',
        'Addison' => 'أديسون',
        'Audrey' => 'أودري',
        'Bella' => 'بيلا',
        'Claire' => 'كلير',
        'Naomi' => 'ناعومي',
        'Elena' => 'إيلينا',
        // English female names – Gemini voices
        'Alice' => 'أليس',
        'Ruby' => 'روبي',
        'Ivy' => 'آيفي',
        'Maya' => 'مايا',
        'Luna' => 'لونا',
        'Willow' => 'ويلو',
        'Isla' => 'آيلا',
        'Daisy' => 'ديزي',
        'Poppy' => 'بوبي',
        'Sienna' => 'سيينا',
        'Freya' => 'فرايا',
        'Phoebe' => 'فيبي',
        'Rose' => 'روز',
        'Clara' => 'كلارا',
        // English male names
        'Liam' => 'ليام',
        'Noah' => 'نوح',
        'James' => 'جيمس',
        'William' => 'ويليام',
        'Benjamin' => 'بنجامين',
        'Lucas' => 'لوكاس',
        'Henry' => 'هنري',
        'Alexander' => 'ألكسندر',
        'Mason' => 'ميسون',
        'Michael' => 'مايكل',
        'Ethan' => 'إيثان',
        'Daniel' => 'دانيال',
        'Jacob' => 'جايكوب',
        'Logan' => 'لوغان',
        'Jackson' => 'جاكسون',
        'Sebastian' => 'سيباستيان',
        'Jack' => 'جاك',
        'Aiden' => 'أيدن',
        'Owen' => 'أوين',
        'Samuel' => 'صمويل',
        'Matthew' => 'ماثيو',
        'Joseph' => 'جوزيف',
        'Carter' => 'كارتر',
        'David' => 'ديفيد',
        'John' => 'جون',
        'Leo' => 'ليو',
        'Gabriel' => 'جبريل',
        'Julian' => 'جوليان',
        'Isaac' => 'إسحاق',
        'Thomas' => 'توماس',
        // English male names – Gemini voices
        'Oliver' => 'أوليفر',
        'George' => 'جورج',
        'Oscar' => 'أوسكار',
        'Charlie' => 'تشارلي',
        'Theodore' => 'ثيودور',
        'Arthur' => 'آرثر',
        'Finn' => 'فين',
        'Miles' => 'مايلز',
        'Nathan' => 'ناثان',
        'Dylan' => 'ديلان',
        'Ryan' => 'ريان',
        'Adrian' => 'أدريان',
        'Caleb' => 'كالب',
        'Marcus' => 'ماركوس',
        'Vincent' => 'فينسنت',
        'Philip' => 'فيليب',
    ];

    /**
     * Resolve preferred provider for a tier based on global TTS mode.
     * HD is provider-pinned by mode; standard is provider-agnostic.
     */
    public static function preferredProviderForTier(string $tier, ?string $ttsMode = null): ?string
    {
        if (VoiceTier::normalize($tier) !== VoiceTier::HD) {
            return null;
        }

        $mode = strtolower(trim((string) ($ttsMode ?? SiteSetting::get('tts_mode', config('listen.tts.mode', 'google')))));

        return $mode === 'hybrid' ? 'gemini' : 'google_cloud';
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function byLanguage(?string $tier = null, ?string $provider = null): array
    {
        $voices = self::activeQuery($tier, $provider)->get();
        $catalog = [];

        foreach ($voices as $voice) {
            $catalog[$voice->language] ??= [];
            $catalog[$voice->language][] = self::toVoiceEntry($voice);
        }

        return $catalog;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function forLanguage(string $language, ?string $tier = null): array
    {
        return self::byLanguage($tier)[$language] ?? [];
    }

    /**
     * @return array<int, string>
     */
    public static function allVoiceNames(?string $tier = null): array
    {
        return self::activeQuery($tier)
            ->pluck('internal_voice_id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function namesForLanguage(string $language, ?string $tier = null): array
    {
        return self::activeQuery($tier)
            ->where('language', $language)
            ->pluck('internal_voice_id')
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function mapByName(?string $tier = null): array
    {
        $map = [];

        foreach (self::activeQuery($tier)->get() as $voice) {
            $map[$voice->internal_voice_id] = self::toVoiceEntry($voice);
        }

        return $map;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function publicCatalog(?string $tier = null, ?string $provider = null): array
    {
        $catalog = [];

        foreach (self::byLanguage($tier, $provider) as $language => $voices) {
            $catalog[$language] = array_map(function (array $voice): array {
                return [
                    'key' => $voice['key'],
                    'label' => $voice['label'],
                    'label_ar' => $voice['label_ar'],
                    'gender' => $voice['gender'],
                    'avatar' => $voice['avatar'],
                    'recommended' => $voice['recommended'],
                    'tier' => $voice['tier'],
                    'tier_label' => VoiceTier::label((string) $voice['tier']),
                ];
            }, $voices);
        }

        return $catalog;
    }

    /**
     * @return array<string, array<string, array<int, array<string, mixed>>>>
     */
    public static function publicCatalogByTier(): array
    {
        $ttsMode = strtolower(trim((string) SiteSetting::get('tts_mode', config('listen.tts.mode', 'google'))));

        $catalog = [];

        foreach (VoiceTier::all() as $tier) {
            // In hybrid mode, prefer Gemini voices for HD tier.
            // If no Gemini HD voices are configured, gracefully fall back to Google, then any provider.
            if ($tier === VoiceTier::HD) {
                $preferredProvider = self::preferredProviderForTier($tier, $ttsMode);
                $preferredCatalog = self::publicCatalog($tier, $preferredProvider);

                if (self::catalogHasVoices($preferredCatalog)) {
                    $catalog[$tier] = $preferredCatalog;
                    continue;
                }

                $fallbackProvider = $preferredProvider === 'gemini' ? 'google_cloud' : 'gemini';
                $fallbackCatalog = self::publicCatalog($tier, $fallbackProvider);
                if (self::catalogHasVoices($fallbackCatalog)) {
                    $catalog[$tier] = $fallbackCatalog;
                    continue;
                }

                $catalog[$tier] = self::publicCatalog($tier);
            } else {
                $catalog[$tier] = self::publicCatalog($tier);
            }
        }

        return $catalog;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $catalog
     */
    private static function catalogHasVoices(array $catalog): bool
    {
        foreach ($catalog as $voices) {
            if (is_array($voices) && count($voices) > 0) {
                return true;
            }
        }

        return false;
    }

    public static function voiceMeta(?string $voiceName): ?array
    {
        $voiceName = trim((string) $voiceName);
        if ($voiceName === '') {
            return null;
        }

        $voice = self::activeQuery()->where('internal_voice_id', $voiceName)->first();
        if (! $voice) {
            return null;
        }

        $meta = self::toVoiceEntry($voice);

        return [
            'key' => $meta['key'],
            'label' => $meta['label'],
            'label_ar' => $meta['label_ar'],
            'gender' => $meta['gender'],
            'avatar' => $meta['avatar'],
            'recommended' => (bool) $meta['recommended'],
            'language' => $meta['language'],
            'tier' => $meta['tier'],
            'tier_label' => VoiceTier::label((string) $meta['tier']),
        ];
    }

    public static function labelForName(?string $voiceName): string
    {
        return self::voiceMeta($voiceName)['label'] ?? 'Default voice';
    }

    public static function nameFromKeyForLanguage(string $language, ?string $voiceKey, ?string $tier = null): ?string
    {
        $voice = self::findByKeyForLanguage($voiceKey, $language, $tier);

        return $voice?->internal_voice_id;
    }

    public static function nameFromKeyForLanguageAndProvider(
        string $language,
        ?string $voiceKey,
        ?string $tier = null,
        ?string $provider = null,
    ): ?string {
        $voice = self::findByKeyForLanguage($voiceKey, $language, $tier, $provider);

        return $voice?->internal_voice_id;
    }

    public static function isAllowedForLanguage(?string $voiceName, string $language, ?string $tier = null): bool
    {
        $voiceName = trim((string) $voiceName);
        if ($voiceName === '') {
            return true;
        }

        return self::activeQuery($tier)
            ->where('language', $language)
            ->where('internal_voice_id', $voiceName)
            ->exists();
    }

    public static function defaultForLanguage(string $language, ?string $tier = null): string
    {
        return self::defaultForLanguageAndProvider($language, $tier, null);
    }

    public static function defaultForLanguageAndProvider(string $language, ?string $tier = null, ?string $provider = null): string
    {
        $language = strtolower(trim($language));
        $normalizedTier = $tier !== null && $tier !== ''
            ? VoiceTier::normalize($tier)
            : null;
        $provider = strtolower(trim((string) $provider));

        if ($normalizedTier !== null) {
            $preferredVoice = self::preferredDefaultVoice($language, $normalizedTier, $provider !== '' ? $provider : null);
            if ($preferredVoice) {
                return (string) $preferredVoice->internal_voice_id;
            }

            $voice = self::activeQuery($normalizedTier, $provider !== '' ? $provider : null)
                ->where('language', $language)
                ->orderBy('id')
                ->first();

            if ($voice) {
                return (string) $voice->internal_voice_id;
            }

            if ($provider !== '') {
                $providerAgnosticTierVoice = self::activeQuery($normalizedTier)
                    ->where('language', $language)
                    ->orderBy('id')
                    ->first();

                return $providerAgnosticTierVoice ? (string) $providerAgnosticTierVoice->internal_voice_id : '';
            }

            return '';
        }

        $preferredVoice = self::preferredDefaultVoice($language, null, $provider !== '' ? $provider : null);
        if ($preferredVoice) {
            return (string) $preferredVoice->internal_voice_id;
        }

        $fallback = self::activeQuery(null, $provider !== '' ? $provider : null)
            ->where('language', $language)
            ->orderBy('id')
            ->first();

        if ($fallback) {
            return (string) $fallback->internal_voice_id;
        }

        if ($provider !== '') {
            $providerAgnosticFallback = self::activeQuery()
                ->where('language', $language)
                ->orderBy('id')
                ->first();

            return $providerAgnosticFallback ? (string) $providerAgnosticFallback->internal_voice_id : '';
        }

        return '';
    }

    public static function tierForName(?string $voiceName): ?string
    {
        $voiceName = trim((string) $voiceName);
        if ($voiceName === '') {
            return null;
        }

        return self::activeQuery()
            ->where('internal_voice_id', $voiceName)
            ->value('tier');
    }

    public static function providerForName(?string $voiceName): ?string
    {
        $voiceName = trim((string) $voiceName);
        if ($voiceName === '') {
            return null;
        }

        $provider = self::activeQuery()
            ->where('internal_voice_id', $voiceName)
            ->value('provider');

        return is_string($provider) && trim($provider) !== ''
            ? strtolower(trim($provider))
            : null;
    }

    public static function providerVoiceIdForName(?string $voiceName): ?string
    {
        $voiceName = trim((string) $voiceName);
        if ($voiceName === '') {
            return null;
        }

        $voice = self::activeQuery()
            ->where('internal_voice_id', $voiceName)
            ->first(['internal_voice_id', 'provider_voice_id']);

        if (! $voice) {
            return null;
        }

        $providerVoiceId = trim((string) ($voice->provider_voice_id ?? ''));
        if ($providerVoiceId !== '') {
            return $providerVoiceId;
        }

        return trim((string) $voice->internal_voice_id) !== ''
            ? (string) $voice->internal_voice_id
            : null;
    }

    public static function defaultForLanguageByProvider(
        string $language,
        string $provider,
        ?string $tier = null,
        ?string $gender = null,
    ): string {
        $language = strtolower(trim($language));
        $provider = strtolower(trim($provider));
        $normalizedTier = $tier !== null && $tier !== ''
            ? VoiceTier::normalize($tier)
            : null;
        $gender = strtolower(trim((string) $gender));

        if ($language === '' || $provider === '') {
            return '';
        }

        $preferredVoice = self::preferredDefaultVoice($language, $normalizedTier, $provider, $gender);
        if ($preferredVoice) {
            return (string) $preferredVoice->internal_voice_id;
        }

        $query = Voice::query()
            ->where('is_active', true)
            ->where('language', $language)
            ->where('provider', $provider);

        if ($normalizedTier !== null) {
            $query->where('tier', $normalizedTier);
        }

        if ($gender !== '' && in_array($gender, ['male', 'female'], true)) {
            $query->where('gender', $gender);
        }

        $voice = $query
            ->orderByRaw("CASE tier WHEN 'standard' THEN 1 WHEN 'hd' THEN 2 ELSE 99 END")
            ->orderBy('display_name')
            ->orderBy('id')
            ->first();

        return $voice ? (string) $voice->internal_voice_id : '';
    }

    private static function preferredDefaultVoice(
        string $language,
        ?string $tier = null,
        ?string $provider = null,
        ?string $gender = null,
    ): ?Voice {
        $language = strtolower(trim($language));
        $provider = strtolower(trim((string) $provider));
        $gender = strtolower(trim((string) $gender));

        // Admin override: explicit per-language default voice.
        $adminDefaultVoiceName = TtsStudioConfig::defaultVoiceName($language);
        if ($adminDefaultVoiceName !== '') {
            $adminDefaultQuery = Voice::query()
                ->where('is_active', true)
                ->where('language', $language)
                ->where('internal_voice_id', $adminDefaultVoiceName);

            if ($tier !== null && $tier !== '') {
                $adminDefaultQuery->where('tier', VoiceTier::normalize($tier));
            }

            if ($provider !== '') {
                $adminDefaultQuery->where('provider', $provider);
            }

            if ($gender !== '' && in_array($gender, ['male', 'female'], true)) {
                $adminDefaultQuery->where('gender', $gender);
            }

            $adminDefaultVoice = $adminDefaultQuery
                ->orderBy('id')
                ->first();

            if ($adminDefaultVoice) {
                return $adminDefaultVoice;
            }
        }

        // Product preference: default Arabic Gemini speaker should be Fahad.
        if ($language !== 'ar' || $provider !== 'gemini') {
            return null;
        }

        $query = Voice::query()
            ->where('is_active', true)
            ->where('language', $language)
            ->where('provider', $provider);

        if ($tier !== null && $tier !== '') {
            $query->where('tier', VoiceTier::normalize($tier));
        }

        if ($gender !== '' && in_array($gender, ['male', 'female'], true)) {
            $query->where('gender', $gender);
        }

        foreach (['ar-gemini-hd-iapetus', 'gemini-ar-fahad', 'gemini-ar-fahd'] as $preferredInternalId) {
            $voice = (clone $query)
                ->whereRaw('LOWER(internal_voice_id) = ?', [$preferredInternalId])
                ->orderBy('id')
                ->first();
            if ($voice) {
                return $voice;
            }
        }

        foreach (['fahad', 'fahd'] as $preferredDisplayName) {
            $voice = (clone $query)
                ->whereRaw('LOWER(display_name) = ?', [$preferredDisplayName])
                ->orderBy('id')
                ->first();
            if ($voice) {
                return $voice;
            }
        }

        return null;
    }

    public static function isWaveNetVoiceName(?string $voiceName): bool
    {
        $voiceName = trim((string) $voiceName);

        return $voiceName !== '' && str_contains(strtolower($voiceName), '-wavenet-');
    }

    public static function findByKeyForLanguage(
        ?string $voiceKey,
        string $language,
        ?string $tier = null,
        ?string $provider = null,
    ): ?Voice
    {
        $id = self::idFromKey($voiceKey);
        if (! $id) {
            return null;
        }

        $query = self::activeQuery($tier, $provider)->where('id', $id)->where('language', $language);

        return $query->first();
    }

    public static function keyFromId(int $id): string
    {
        return 'voice_'.$id;
    }

    private static function idFromKey(?string $key): ?int
    {
        $key = trim((string) $key);
        if ($key === '') {
            return null;
        }

        if (! preg_match('/^voice_(\d+)$/', $key, $matches)) {
            return null;
        }

        $id = (int) ($matches[1] ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Voice>
     */
    private static function activeQuery(?string $tier = null, ?string $provider = null): \Illuminate\Database\Eloquent\Builder
    {
        $query = Voice::query()->where('is_active', true);

        if ($tier !== null && $tier !== '') {
            $query->where('tier', VoiceTier::normalize($tier));
        }

        if ($provider !== null && $provider !== '') {
            $query->where('provider', strtolower(trim($provider)));
        }

        return $query
            ->orderBy('language')
            ->orderByRaw("CASE tier WHEN 'standard' THEN 1 WHEN 'hd' THEN 2 ELSE 99 END")
            ->orderBy('display_name')
            ->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    private static function toVoiceEntry(Voice $voice): array
    {
        $displayName = (string) $voice->display_name;

        return [
            'id' => (int) $voice->id,
            'key' => self::keyFromId((int) $voice->id),
            'name' => (string) $voice->internal_voice_id,
            'label' => $displayName,
            'label_ar' => self::AR_NAME_MAP[$displayName] ?? $displayName,
            'gender' => (string) $voice->gender,
            // The speaker's Listen avatar (public/images/speakers), or null.
            'avatar' => SpeakerAvatar::url($displayName, (string) $voice->language),
            'recommended' => false,
            'language' => (string) $voice->language,
            'tier' => VoiceTier::normalize((string) $voice->tier),
        ];
    }
}
