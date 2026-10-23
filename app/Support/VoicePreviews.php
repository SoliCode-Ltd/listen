<?php

namespace App\Support;

/**
 * The voice library's previews: a ten-second line per speaker, spoken the way
 * the speaker's avatar looks, at public/audio/voice-previews/{avatar slug}.mp3.
 * The lines are in database/seeders/data/voice_previews.php; the clips are made
 * by `php artisan tts:showcase --set=previews --generate`.
 */
final class VoicePreviews
{
    public const DIR = 'audio/voice-previews';

    /**
     * Languages some English speakers' previews are spoken in, natively.
     *
     * @var array<string, array{en: string, ar: string, prompt: string}>
     */
    public const NATIVE = [
        'fr-FR' => ['en' => 'French', 'ar' => 'الفرنسية', 'prompt' => 'French as spoken in Paris, France'],
        'es-ES' => ['en' => 'Spanish (Spain)', 'ar' => 'الإسبانية (إسبانيا)', 'prompt' => 'Peninsular Spanish as spoken in Madrid, Spain'],
        'es-MX' => ['en' => 'Spanish (Mexico)', 'ar' => 'الإسبانية (المكسيك)', 'prompt' => 'Mexican Spanish as spoken in Mexico City'],
        'de-DE' => ['en' => 'German', 'ar' => 'الألمانية', 'prompt' => 'Standard German as spoken in Germany'],
        'it-IT' => ['en' => 'Italian', 'ar' => 'الإيطالية', 'prompt' => 'Standard Italian as spoken in Italy'],
        'pt-BR' => ['en' => 'Portuguese (Brazil)', 'ar' => 'البرتغالية (البرازيل)', 'prompt' => 'Brazilian Portuguese as spoken in Rio de Janeiro and São Paulo'],
        'tr-TR' => ['en' => 'Turkish', 'ar' => 'التركية', 'prompt' => 'Turkish as spoken in Istanbul'],
        'ru-RU' => ['en' => 'Russian', 'ar' => 'الروسية', 'prompt' => 'Russian as spoken in Moscow'],
        'hi-IN' => ['en' => 'Hindi', 'ar' => 'الهندية', 'prompt' => 'Hindi as spoken in Delhi, India'],
        'ja-JP' => ['en' => 'Japanese', 'ar' => 'اليابانية', 'prompt' => 'Standard Japanese as spoken in Tokyo'],
        'ko-KR' => ['en' => 'Korean', 'ar' => 'الكورية', 'prompt' => 'Standard Korean as spoken in Seoul'],
        'zh-CN' => ['en' => 'Mandarin Chinese', 'ar' => 'الصينية', 'prompt' => 'Standard Mandarin Chinese (Putonghua) as spoken in Beijing'],
    ];

    /** @var array<string, array<string, string>>|null */
    private static ?array $lines = null;

    /** @var array<string, string>|null slug => hash of the line its clip was made from */
    private static ?array $made = null;

    /**
     * Every preview line, by avatar slug.
     *
     * @return array<string, array<string, string>>
     */
    public static function lines(): array
    {
        if (self::$lines === null) {
            self::$lines = [];
            foreach ((require database_path('seeders/data/voice_previews.php'))['previews'] as $line) {
                self::$lines[$line['slug']] = $line;
            }
        }

        return self::$lines;
    }

    /**
     * The speaker's preview, or null when none has been made: its URL, and the
     * dialect, accent or language it is spoken in.
     *
     * @return array{url: string, dialect: string}|null
     */
    public static function find(?string $name, ?string $language): ?array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $slug = SpeakerAvatar::slug($name, (string) $language);
        $line = self::lines()[$slug] ?? null;
        $hash = self::made()[$slug] ?? null;
        if ($line === null || $hash === null) {
            return null;
        }

        return [
            'url' => asset(self::DIR.'/'.$slug.'.mp3').'?v='.substr($hash, 0, 10),
            'dialect' => (string) $line['dialect'],
        ];
    }

    /**
     * Localised name of a preview's dialect, accent or language.
     */
    public static function label(string $dialect, bool $arabic): string
    {
        return isset(self::NATIVE[$dialect])
            ? self::NATIVE[$dialect][$arabic ? 'ar' : 'en']
            : VoiceFlag::label($dialect, $arabic);
    }

    /**
     * What a line's clip is made from: a new hash means the clip is out of date.
     *
     * @param  array<string, string>  $line
     */
    public static function hash(array $line): string
    {
        return sha1(implode("\n", [$line['voice'], $line['language'], $line['dialect'], $line['style'], $line['text']]));
    }

    public static function madePath(): string
    {
        return public_path(self::DIR.'/made.json');
    }

    /**
     * The clips on disk whose line has not changed since they were made.
     *
     * @return array<string, string>
     */
    private static function made(): array
    {
        if (self::$made === null) {
            self::$made = [];
            $file = self::madePath();
            $made = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
            foreach (self::lines() as $slug => $line) {
                $hash = self::hash($line);
                if (($made[$slug] ?? null) === $hash && is_file(public_path(self::DIR.'/'.$slug.'.mp3'))) {
                    self::$made[$slug] = $hash;
                }
            }
        }

        return self::$made;
    }

    public static function forget(): void
    {
        self::$lines = null;
        self::$made = null;
    }
}
