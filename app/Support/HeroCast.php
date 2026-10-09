<?php

namespace App\Support;

/**
 * The five speakers in the hero conversation for each page language. Each
 * says one line about Listen in their own dialect or accent, with a mood.
 * Arabic pages show only the Arabic cast, English pages only the English cast.
 */
final class HeroCast
{
    /**
     * @var array<string, array<int, array{name: string, name_ar?: string, dialect: string, mood: array{en: string, ar: string}, line: string}>>
     */
    private const CAST = [
        'ar' => [
            ['name' => 'Sultan', 'name_ar' => 'سلطان', 'dialect' => 'ar-SA', 'mood' => ['en' => 'Excited', 'ar' => 'حماس'],
                'line' => 'والله إن صوتي صار يطلع بلهجتي!'],
            ['name' => 'Sumaya', 'name_ar' => 'سمية', 'dialect' => 'ar-EG', 'mood' => ['en' => 'Laughing', 'ar' => 'ضحك'],
                'line' => 'ده بيتكلم مصري أحسن مني!'],
            ['name' => 'Nawaf', 'name_ar' => 'نواف', 'dialect' => 'ar-AE', 'mood' => ['en' => 'Confident', 'ar' => 'ثقة'],
                'line' => 'وايد سهل، أكتب وأسمع على طول.'],
            ['name' => 'Sawsan', 'name_ar' => 'سوسن', 'dialect' => 'ar-LB', 'mood' => ['en' => 'Amazed', 'ar' => 'دهشة'],
                'line' => 'كتير طبيعي، كأنو حدا عم يحكي معك!'],
            ['name' => 'Aisha', 'name_ar' => 'عائشة', 'dialect' => 'ar-MA', 'mood' => ['en' => 'Joyful', 'ar' => 'فرح'],
                'line' => 'زوين بزاف، حتى الدارجة كيهضرها!'],
        ],
        'en' => [
            ['name' => 'Daisy', 'dialect' => 'en-US', 'mood' => ['en' => 'Excited', 'ar' => 'حماس'],
                'line' => "Wait, that's my script, and it sounds amazing!"],
            ['name' => 'Theodore', 'dialect' => 'en-GB', 'mood' => ['en' => 'Calm', 'ar' => 'هدوء'],
                'line' => 'Rather brilliant, I must say.'],
            ['name' => 'Charlie', 'dialect' => 'en-NG', 'mood' => ['en' => 'Confident', 'ar' => 'ثقة'],
                'line' => 'My people, this one is top notch!'],
            ['name' => 'Miles', 'dialect' => 'en-AU', 'mood' => ['en' => 'Laughing', 'ar' => 'ضحك'],
                'line' => 'Too easy, mate. Type it, hear it!'],
            ['name' => 'Luna', 'dialect' => 'en-IN', 'mood' => ['en' => 'Cheerful', 'ar' => 'بهجة'],
                'line' => 'Perfect for my channel, every single day.'],
        ],
    ];

    /**
     * The cast for a page language, ready to show.
     *
     * @return array<int, array{name: string, language: string, avatar: ?string, dialect: string, dialect_label: string, flag: ?string, mood: string, line: string}>
     */
    public static function for(?string $locale = null): array
    {
        $language = str_starts_with(strtolower((string) ($locale ?? app()->getLocale())), 'ar') ? 'ar' : 'en';
        $arabicUi = $language === 'ar';

        return array_map(fn (array $person): array => [
            'name' => $arabicUi ? ($person['name_ar'] ?? $person['name']) : $person['name'],
            'language' => $language,
            'avatar' => SpeakerAvatar::url($person['name'], $language),
            'dialect' => $person['dialect'],
            'dialect_label' => VoiceFlag::label($person['dialect'], $arabicUi),
            'flag' => VoiceFlag::url($person['dialect']),
            'mood' => $person['mood'][$arabicUi ? 'ar' : 'en'],
            'line' => $person['line'],
        ], self::CAST[$language]);
    }
}
