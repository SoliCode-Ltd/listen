<?php

namespace App\Services\Tts;

use App\Models\SiteSetting;
use App\Support\VoiceCatalog;

/**
 * Arabic Voice Mapping Service.
 *
 * Maps user-facing intent (country, dialect, emotion, style)
 * to provider-specific voice/style parameters.
 *
 * This layer decouples the UI controls from provider internals,
 * allowing admin to configure mappings without code changes.
 */
class ArabicVoiceMapper
{
    /**
     * Country/dialect codes with display names.
     */
    public const DIALECTS = [
        'ar-SA' => ['name' => 'Saudi (خليجي)', 'name_ar' => 'سعودي (خليجي)', 'region' => 'gulf'],
        'ar-AE' => ['name' => 'Emirati (خليجي)', 'name_ar' => 'إماراتي (خليجي)', 'region' => 'gulf'],
        'ar-KW' => ['name' => 'Kuwaiti (خليجي)', 'name_ar' => 'كويتي (خليجي)', 'region' => 'gulf'],
        'ar-QA' => ['name' => 'Qatari (خليجي)', 'name_ar' => 'قطري (خليجي)', 'region' => 'gulf'],
        'ar-BH' => ['name' => 'Bahraini (خليجي)', 'name_ar' => 'بحريني (خليجي)', 'region' => 'gulf'],
        'ar-OM' => ['name' => 'Omani (خليجي)', 'name_ar' => 'عماني (خليجي)', 'region' => 'gulf'],
        'ar-EG' => ['name' => 'Egyptian (مصري)', 'name_ar' => 'مصري', 'region' => 'north_africa'],
        'ar-LB' => ['name' => 'Lebanese (لبناني)', 'name_ar' => 'لبناني', 'region' => 'levant'],
        'ar-SY' => ['name' => 'Syrian (سوري)', 'name_ar' => 'سوري', 'region' => 'levant'],
        'ar-JO' => ['name' => 'Jordanian (أردني)', 'name_ar' => 'أردني', 'region' => 'levant'],
        'ar-PS' => ['name' => 'Palestinian (فلسطيني)', 'name_ar' => 'فلسطيني', 'region' => 'levant'],
        'ar-IQ' => ['name' => 'Iraqi (عراقي)', 'name_ar' => 'عراقي', 'region' => 'levant'],
        'ar-MA' => ['name' => 'Moroccan (مغربي)', 'name_ar' => 'مغربي', 'region' => 'maghreb'],
        'ar-DZ' => ['name' => 'Algerian (جزائري)', 'name_ar' => 'جزائري', 'region' => 'maghreb'],
        'ar-TN' => ['name' => 'Tunisian (تونسي)', 'name_ar' => 'تونسي', 'region' => 'maghreb'],
        'ar-LY' => ['name' => 'Libyan (ليبي)', 'name_ar' => 'ليبي', 'region' => 'maghreb'],
        'ar-SD' => ['name' => 'Sudanese (سوداني)', 'name_ar' => 'سوداني', 'region' => 'north_africa'],
        'ar-YE' => ['name' => 'Yemeni (يمني)', 'name_ar' => 'يمني', 'region' => 'gulf'],
        'ar-XA' => ['name' => 'Modern Standard Arabic (فصحى)', 'name_ar' => 'فصحى', 'region' => 'msa'],
    ];

    /**
     * Performance style options.
     */
    public const PERFORMANCE_STYLES = [
        'educational'    => ['name' => 'Educational',       'name_ar' => 'تعليمي (واضح ومرتّب)',       'icon' => '🎓'],
        'commercial'     => ['name' => 'Commercial',        'name_ar' => 'تجاري (حيوي ومُقنع)',       'icon' => '📰'],
        'promo'          => ['name' => 'Promotional',       'name_ar' => 'ترويجي',                  'icon' => '📣'],
        'podcast'        => ['name' => 'Podcast',           'name_ar' => 'بودكاست (حواري ودافئ)',   'icon' => '🎧'],
        'assistant'      => ['name' => 'Virtual Assistant', 'name_ar' => 'مساعد ذكي (ودي وداعم)',   'icon' => '🤖'],
        'narrative'      => ['name' => 'Narrative',         'name_ar' => 'سردي (مُعبّر وشيّق)',        'icon' => '📖'],
        'comedic'        => ['name' => 'Comedic',           'name_ar' => 'فكاهي (ساخر وخفيف)',       'icon' => '😄'],
        'corporate'      => ['name' => 'Corporate',         'name_ar' => 'مؤسسي (احترافي ورسمي)',   'icon' => '💼'],
        'conversational' => ['name' => 'Conversational',    'name_ar' => 'محادثة (عفوي وطبيعي)',    'icon' => '💬'],
        'inspirational'  => ['name' => 'Inspirational',     'name_ar' => 'تحفيزي (مُلهم وحماسي)',   'icon' => '🔥'],
        'cinematic'      => ['name' => 'Cinematic',         'name_ar' => 'سينمائي (درامي وعاطفي)',  'icon' => '🎥'],
        'documentary'    => ['name' => 'Documentary',       'name_ar' => 'وثائقي (رصين وموضوعي)', 'icon' => '🌟'],
        'sports_commentator' => ['name' => 'Sports Commentator', 'name_ar' => 'معلق رياضي (حماسي ومثير)', 'icon' => '⚽'],
        'meditation'     => ['name' => 'Meditation',        'name_ar' => 'تأمل (هادئ وروحاني)',      'icon' => '🧘'],
        'audiobook'      => ['name' => 'Audiobook',         'name_ar' => 'كتاب مسموع (احترافي)',    'icon' => '📚'],
        'children_story' => ['name' => 'Children Story',    'name_ar' => 'قصة أطفال (مرح وساحر)',   'icon' => '🧸'],
        'radio_host'     => ['name' => 'Radio Host',        'name_ar' => 'مذيع راديو (أنيق وجذاب)', 'icon' => '📻'],
    ];

    /**
     * Emotion tones.
     *
     * Includes legacy entries (storytelling/ad_style/news) for backward compatibility.
     */
    public const EMOTIONS = [
        'neutral' => ['name' => 'Neutral', 'name_ar' => 'محايد', 'icon' => 'neutral'],
        'warm' => ['name' => 'Warm', 'name_ar' => 'دافئ', 'icon' => 'warm'],
        'sad' => ['name' => 'Sad', 'name_ar' => 'حزين', 'icon' => 'sad'],
        'excited' => ['name' => 'Excited', 'name_ar' => 'متحمس', 'icon' => 'excited'],
        'formal' => ['name' => 'Formal', 'name_ar' => 'رسمي', 'icon' => 'formal'],
        'storytelling' => ['name' => 'Storytelling', 'name_ar' => 'سرد قصصي', 'icon' => 'storytelling'],
        'ad_style' => ['name' => 'Ad Style', 'name_ar' => 'أسلوب إعلاني', 'icon' => 'ad_style'],
        'news' => ['name' => 'News', 'name_ar' => 'أخبار', 'icon' => 'news'],
        'calm' => ['name' => 'Calm', 'name_ar' => 'هادئ', 'icon' => 'calm'],
        'angry' => ['name' => 'Angry', 'name_ar' => 'غاضب', 'icon' => 'angry'],
        'hopeful' => ['name' => 'Hopeful', 'name_ar' => 'متفائل', 'icon' => 'hopeful'],
        'serious' => ['name' => 'Serious', 'name_ar' => 'جاد', 'icon' => 'serious'],
        'playful' => ['name' => 'Playful', 'name_ar' => 'مرح', 'icon' => 'playful'],
        'mysterious' => ['name' => 'Mysterious', 'name_ar' => 'غامض', 'icon' => 'mysterious'],
        'confident' => ['name' => 'Confident', 'name_ar' => 'واثق', 'icon' => 'confident'],
    ];

    /**
     * Map user intent to provider-specific voice configuration.
     *
     * @param  string  $provider       Provider name ('google_cloud' or 'gemini')
     * @param  array   $userIntent     User selections:
     *                                  - dialect: string (e.g. 'ar-SA')
     *                                  - emotion: string (e.g. 'warm')
     *                                  - gender: string ('male'|'female')
     *                                  - speed: float
     *                                  - pitch: float
     *                                  - voice_name: string (if explicitly selected)
     * @return array                   Provider-specific voice config
     */
    public function mapToProviderConfig(string $provider, array $userIntent): array
    {
        $dialect = $userIntent['dialect'] ?? 'ar-XA';
        $hasExplicitPerformanceStyle = array_key_exists('performance_style', $userIntent)
            || array_key_exists('style', $userIntent);
        $performanceStyle = $userIntent['performance_style'] ?? ($userIntent['style'] ?? 'neutral');
        $emotion = $userIntent['emotion'] ?? 'neutral';
        $voiceName = trim((string) ($userIntent['voice_name'] ?? ''));

        // Backward compatibility: legacy payloads used `emotion` for style.
        if (! $hasExplicitPerformanceStyle && is_string($emotion) && $emotion !== 'neutral') {
            $performanceStyle = $emotion;
        }

        $gender = strtolower((string) ($userIntent['gender'] ?? ''));
        if ($gender === '' && $voiceName !== '') {
            $voiceMeta = VoiceCatalog::voiceMeta($voiceName);
            $gender = strtolower((string) (($voiceMeta['gender'] ?? '')));
        }
        if (! in_array($gender, ['male', 'female'], true)) {
            $gender = 'female';
        }

        $speed = (float) ($userIntent['speed'] ?? 1.0);
        $pitch = (float) ($userIntent['pitch'] ?? 0.0);

        // Check admin override mapping first
        $adminMapping = $this->getAdminMapping($provider, $dialect, (string) $performanceStyle, (string) $emotion, $gender);
        if ($adminMapping !== null) {
            return array_merge($adminMapping, [
                'speed' => $speed,
                'pitch' => $pitch,
            ]);
        }

        if ($provider === 'gemini') {
            return $this->mapForGemini($dialect, (string) $performanceStyle, (string) $emotion, $gender, $speed, $pitch, $voiceName);
        }

        return $this->mapForGoogleCloud($dialect, (string) $performanceStyle, (string) $emotion, $gender, $speed, $pitch, $voiceName);
    }

    /**
     * Map intent to Gemini TTS config.
     */
    private function mapForGemini(string $dialect, string $performanceStyle, string $emotion, string $gender, float $speed, float $pitch, string $voiceName): array
    {
        // If explicit voice name is a Gemini voice, use it directly
        if ($voiceName !== '') {
            return [
                'voice_name' => $voiceName,
                'performance_style' => $performanceStyle,
                'style' => $performanceStyle,
                'emotion' => $emotion,
                'speed' => $speed,
                'pitch' => $pitch,
                'dialect' => $dialect,
                'gender' => strtoupper($gender),
            ];
        }

        // Default Gemini voice selection based on gender
        $voice = match ($gender) {
            'male' => 'Orus',    // Natural male Arabic voice
            default => 'Kore',   // Natural female Arabic voice
        };

        return [
            'voice_name' => $voice,
            'performance_style' => $performanceStyle,
            'style' => $performanceStyle,
            'emotion' => $emotion,
            'speed' => $speed,
            'pitch' => $pitch,
            'dialect' => $dialect,
            'gender' => strtoupper($gender),
        ];
    }

    /**
     * Map intent to Google Cloud TTS config.
     * Google Cloud doesn't support emotions natively, but we can adjust pitch/speed.
     */
    private function mapForGoogleCloud(string $dialect, string $performanceStyle, string $emotion, string $gender, float $speed, float $pitch, string $voiceName): array
    {
        // Apply emotion adjustments through speed and pitch
        [$emotionSpeed, $emotionPitch] = $this->emotionToSpeedPitch($emotion);
        $adjustedSpeed = min(4.0, max(0.25, $speed * $emotionSpeed));
        $adjustedPitch = min(20.0, max(-20.0, $pitch + $emotionPitch));

        return [
            'voice_name' => $voiceName,
            'performance_style' => $performanceStyle,
            'style' => null, // Google Cloud doesn't support style prompts
            'emotion' => $emotion,
            'speed' => $adjustedSpeed,
            'pitch' => $adjustedPitch,
            'dialect' => $dialect,
            'gender' => strtoupper($gender),
        ];
    }

    /**
     * Convert emotion to speed/pitch modifiers for Google Cloud TTS.
     *
     * @return array{float, float} [speed_multiplier, pitch_offset]
     */
    private function emotionToSpeedPitch(string $emotion): array
    {
        return match ($emotion) {
            // Emotion tones
            'warm' => [0.98, 0.5],
            'sad' => [0.88, -1.0],
            'excited' => [1.12, 2.0],
            'formal' => [0.94, -0.5],
            'calm' => [0.90, -0.8],
            'angry' => [1.08, 1.0],
            // Backward compatibility for older payloads that stored style in emotion
            'educational' => [0.92, -0.5],
            'commercial' => [1.10, 2.0],
            'promo' => [1.05, 1.5],
            'podcast' => [0.95, 0.5],
            'assistant' => [0.95, 1.0],
            'narrative' => [0.90, 0.5],
            'comedic' => [1.05, 1.5],
            'corporate' => [0.90, -1.0],
            'conversational' => [1.0, 0.0],
            'inspirational' => [1.05, 2.0],
            'cinematic' => [0.85, -1.5],
            'documentary' => [0.88, -1.0],
            default          => [1.0, 0.0],
        };
    }

    /**
     * Check for admin-configured voice mapping override.
     */
    private function getAdminMapping(string $provider, string $dialect, string $performanceStyle, string $emotion, string $gender): ?array
    {
        $key = "voice_mapping_{$provider}_{$dialect}_{$performanceStyle}_{$emotion}_{$gender}";
        $mapping = SiteSetting::get($key);

        if ($mapping === null || $mapping === '') {
            // Backward compatibility with legacy key that did not include performance style.
            $legacyKey = "voice_mapping_{$provider}_{$dialect}_{$emotion}_{$gender}";
            $mapping = SiteSetting::get($legacyKey);
        }

        if ($mapping === null || $mapping === '') {
            return null;
        }

        $decoded = is_string($mapping) ? json_decode($mapping, true) : null;
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Get all available dialects for display.
     */
    public static function getDialects(): array
    {
        return self::DIALECTS;
    }

    /**
     * Get dialects grouped by region.
     */
    public static function getDialectsByRegion(): array
    {
        $grouped = [];
        foreach (self::DIALECTS as $code => $dialect) {
            $region = $dialect['region'];
            $grouped[$region][$code] = $dialect;
        }
        return $grouped;
    }

    /**
     * Get all available performance styles for display.
     */
    public static function getPerformanceStyles(): array
    {
        return self::PERFORMANCE_STYLES;
    }

    /**
     * Get all available emotion tones for display.
     */
    public static function getEmotions(): array
    {
        return self::EMOTIONS;
    }
}
