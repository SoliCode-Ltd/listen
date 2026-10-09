<?php

namespace App\Support;

/**
 * The five speakers in the hero conversation for each page language. Each
 * says one line about Listen in their own dialect or accent, and visitors
 * can play it. Arabic pages show only the Arabic cast, English pages only
 * the English cast.
 *
 * Clips are made by `php artisan tts:showcase --generate` at
 * public/audio/showcase/hero-{language}-{name}.mp3. They have no spoken
 * sign-off: the speakers talk about Listen themselves, and played in order
 * the five lines make one conversation.
 */
final class HeroCast
{
    /**
     * @var array<string, array<int, array{name: string, name_ar?: string, voice: string, dialect: string, style: string, line: string}>>
     */
    public const CAST = [
        'ar' => [
            ['name' => 'Sultan', 'name_ar' => 'سلطان', 'voice' => 'Algenib', 'dialect' => 'ar-SA',
                'style' => 'A young man from Riyadh hearing his own script read back for the first time. He gasps, then speaks fast, genuinely amazed and delighted, smiling and breathing like a real person.',
                'line' => '<gasp> لحظة! هذا صوتي؟ كتبتها في إسمع وطلعت بلهجتي بالضبط!'],
            ['name' => 'Sumaya', 'name_ar' => 'سمية', 'voice' => 'Callirrhoe', 'dialect' => 'ar-EG',
                'style' => 'A cheerful woman from Cairo who bursts out laughing first, then talks playfully to her friends, warm and teasing, fully natural.',
                'line' => '<laugh> وأنا كمان! إسمع بيتكلم مصري أحسن مني!'],
            ['name' => 'Nawaf', 'name_ar' => 'نواف', 'voice' => 'Achird', 'dialect' => 'ar-AE',
                'style' => 'A relaxed, confident Emirati man explaining to friends how easy it is, unhurried and proud, with natural small pauses.',
                'line' => 'تكتب، تختار الصوت، وفي ثواني جاهز. إسمع وفّر علي وايد وقت.'],
            ['name' => 'Sawsan', 'name_ar' => 'سوسن', 'voice' => 'Zephyr', 'dialect' => 'ar-LB',
                'style' => 'A woman from Beirut who sighs with happy relief, then speaks warmly and melodically, a little amused, like sharing a secret with friends.',
                'line' => '<sigh> يا ريت عرفت إسمع من زمان، الكل بفكر إنو أنا اللي عم سجّل!'],
            ['name' => 'Aisha', 'name_ar' => 'عائشة', 'voice' => 'Erinome', 'dialect' => 'ar-MA',
                'style' => 'A joyful young woman from Casablanca who laughs, then speaks brightly and lively, inviting everyone to try it.',
                'line' => '<laugh> حتى الدارجة ديالنا كيهضرها! جربوا إسمع، غادي يعجبكم بزاف.'],
        ],
        'en' => [
            ['name' => 'Daisy', 'voice' => 'Kore', 'dialect' => 'en-US',
                'style' => 'An American woman hearing her own script read back for the first time. She gasps, then speaks fast, thrilled and a little breathless, smiling.',
                'line' => '<gasp> Wait, is that my script? I typed it into Listen and it sounds amazing!'],
            ['name' => 'Theodore', 'voice' => 'Charon', 'dialect' => 'en-GB',
                'style' => 'A calm, distinguished British gentleman with dry wit. He chuckles softly, then speaks, quietly and genuinely impressed.',
                'line' => '<chuckle> I must admit, Listen reads it rather better than I do.'],
            ['name' => 'Charlie', 'voice' => 'Alnilam', 'dialect' => 'en-NG',
                'style' => 'A confident Nigerian man, warm and proud, talking to friends with a big smile and lots of energy.',
                'line' => 'My people, Listen is top notch! Type it, pick a voice, done in seconds.'],
            ['name' => 'Miles', 'voice' => 'Iapetus', 'dialect' => 'en-AU',
                'style' => 'A laid-back Australian man who laughs first, then tells his mates, relaxed and cheeky.',
                'line' => '<laugh> Too easy, mate. I made a whole podcast intro with Listen before my coffee.'],
            ['name' => 'Luna', 'voice' => 'Despina', 'dialect' => 'en-IN',
                'style' => 'A cheerful Indian content creator from Mumbai with a clear Indian English accent. She sighs happily, then speaks brightly and warmly.',
                'line' => '<sigh> Honestly, Listen saves me hours every week. My channel has never sounded better!'],
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
                    'sign_off' => false,
                ];
            }
        }

        return $clips;
    }

    /**
     * The cast for a page language, ready to show and play.
     *
     * @return array<int, array{name: string, language: string, avatar: ?string, dialect: string, dialect_label: string, flag: ?string, line: string, audio: ?string}>
     */
    public static function for(?string $locale = null): array
    {
        $language = str_starts_with(strtolower((string) ($locale ?? app()->getLocale())), 'ar') ? 'ar' : 'en';
        $arabicUi = $language === 'ar';

        return array_map(function (array $person) use ($language, $arabicUi): array {
            $slug = self::slug($language, $person['name']);
            $path = 'audio/showcase/'.$slug.'.mp3';

            return [
                'name' => $arabicUi ? ($person['name_ar'] ?? $person['name']) : $person['name'],
                'language' => $language,
                'avatar' => SpeakerAvatar::url($person['name'], $language),
                'dialect' => $person['dialect'],
                'dialect_label' => VoiceFlag::label($person['dialect'], $arabicUi),
                'flag' => VoiceFlag::url($person['dialect']),
                // Delivery tags like <laugh> are spoken, not shown.
                'line' => trim((string) preg_replace('/\s*<[^<>]+>\s*/u', ' ', $person['line'])),
                'audio' => is_file(public_path($path)) ? AudioUrl::fromPath($path) : null,
            ];
        }, self::CAST[$language]);
    }
}
