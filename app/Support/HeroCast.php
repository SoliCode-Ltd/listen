<?php

namespace App\Support;

/**
 * The five speakers in the hero conversation for each page language. Each
 * says one line about Listen in their own dialect or accent, and visitors
 * can play it. Arabic pages show only the Arabic cast, English pages only
 * the English cast.
 *
 * Clips are made by `php artisan tts:showcase --generate` at
 * public/audio/showcase/hero-{language}-{name}.mp3 and end with the Listen
 * sign-off; showcase_hero_times.json holds when each line ends, so the
 * whole conversation can play line after line with one sign-off at the end.
 */
final class HeroCast
{
    /**
     * @var array<string, array<int, array{name: string, name_ar?: string, voice: string, dialect: string, style: string, line: string}>>
     */
    public const CAST = [
        'ar' => [
            ['name' => 'Sultan', 'name_ar' => 'سلطان', 'voice' => 'Algenib', 'dialect' => 'ar-SA',
                'style' => 'A young man from Riyadh, genuinely excited and amazed, smiling as he speaks.',
                'line' => 'والله إن صوتي صار يطلع بلهجتي!'],
            ['name' => 'Sumaya', 'name_ar' => 'سمية', 'voice' => 'Callirrhoe', 'dialect' => 'ar-EG',
                'style' => 'A cheerful woman from Cairo, laughing as she speaks, playful and warm.',
                'line' => 'ده بيتكلم مصري أحسن مني!'],
            ['name' => 'Nawaf', 'name_ar' => 'نواف', 'voice' => 'Achird', 'dialect' => 'ar-AE',
                'style' => 'A relaxed, confident Emirati man telling a friend how easy it is.',
                'line' => 'وايد سهل، أكتب وأسمع على طول.'],
            ['name' => 'Sawsan', 'name_ar' => 'سوسن', 'voice' => 'Zephyr', 'dialect' => 'ar-LB',
                'style' => 'A woman from Beirut, pleasantly surprised and amazed, melodic.',
                'line' => 'كتير طبيعي، كأنو حدا عم يحكي معك!'],
            ['name' => 'Aisha', 'name_ar' => 'عائشة', 'voice' => 'Erinome', 'dialect' => 'ar-MA',
                'style' => 'A joyful young woman from Casablanca, bright and lively.',
                'line' => 'زوين بزاف، حتى الدارجة كيهضرها!'],
        ],
        'en' => [
            ['name' => 'Daisy', 'voice' => 'Kore', 'dialect' => 'en-US',
                'style' => 'An American woman hearing her own script read back, thrilled and excited.',
                'line' => "Wait, that's my script, and it sounds amazing!"],
            ['name' => 'Theodore', 'voice' => 'Charon', 'dialect' => 'en-GB',
                'style' => 'A calm, distinguished British gentleman, quietly impressed.',
                'line' => 'Rather brilliant, I must say.'],
            ['name' => 'Charlie', 'voice' => 'Alnilam', 'dialect' => 'en-NG',
                'style' => 'A confident Nigerian man, warm and proud, with a big smile.',
                'line' => 'My people, this one is top notch!'],
            ['name' => 'Miles', 'voice' => 'Iapetus', 'dialect' => 'en-AU',
                'style' => 'A laid-back Australian man, laughing as he speaks.',
                'line' => 'Too easy, mate. Type it, hear it!'],
            ['name' => 'Luna', 'voice' => 'Despina', 'dialect' => 'en-IN',
                'style' => 'A cheerful Indian content creator from Mumbai, clearly Indian English accent, bright and warm.',
                'line' => 'Perfect for my channel, every single day.'],
        ],
    ];

    public static function slug(string $language, string $name): string
    {
        return 'hero-'.$language.'-'.strtolower($name);
    }

    /**
     * Every clip to generate, in the shape `tts:showcase` uses for single samples.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function clips(): array
    {
        $clips = [];
        foreach (self::CAST as $language => $cast) {
            foreach ($cast as $person) {
                $clips[] = [
                    'slug' => self::slug($language, $person['name']),
                    'kind' => 'single',
                    'language' => $language,
                    'dialect' => $person['dialect'],
                    'voice' => $person['voice'],
                    'speaker' => $person['name'],
                    'style' => $person['style'],
                    'text' => $person['line'],
                ];
            }
        }

        return $clips;
    }

    /**
     * The cast for a page language, ready to show and play.
     *
     * @return array<int, array{name: string, language: string, avatar: ?string, dialect: string, dialect_label: string, flag: ?string, line: string, audio: ?string, line_end: ?float}>
     */
    public static function for(?string $locale = null): array
    {
        $language = str_starts_with(strtolower((string) ($locale ?? app()->getLocale())), 'ar') ? 'ar' : 'en';
        $arabicUi = $language === 'ar';
        $times = self::lineEnds();

        return array_map(function (array $person) use ($language, $arabicUi, $times): array {
            $slug = self::slug($language, $person['name']);
            $path = 'audio/showcase/'.$slug.'.mp3';

            return [
                'name' => $arabicUi ? ($person['name_ar'] ?? $person['name']) : $person['name'],
                'language' => $language,
                'avatar' => SpeakerAvatar::url($person['name'], $language),
                'dialect' => $person['dialect'],
                'dialect_label' => VoiceFlag::label($person['dialect'], $arabicUi),
                'flag' => VoiceFlag::url($person['dialect']),
                'line' => $person['line'],
                'audio' => is_file(public_path($path)) ? AudioUrl::fromPath($path) : null,
                'line_end' => isset($times[$slug]) ? (float) $times[$slug] : null,
            ];
        }, self::CAST[$language]);
    }

    /**
     * @return array<string, float>
     */
    private static function lineEnds(): array
    {
        static $times = null;
        $file = database_path('seeders/data/showcase_hero_times.json');

        return $times ??= is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
    }
}
