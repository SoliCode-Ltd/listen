<?php

namespace App\Support\Transcription;

use App\Models\SiteSetting;
use App\Support\VoiceCloningAccess;

/**
 * The site's own settings for speech to text ("Transcribe"), set in Admin →
 * Transcribe → Settings (App\Filament\Pages\TranscriptionSettingsPage): who
 * sees it, the free try, the site's daily ceiling, how long uploads are kept,
 * the languages members can choose, the second listening, and Listen's cost
 * figures. Each falls back to config/listen.php "transcription" until saved.
 * What each plan includes is set on the plan (TranscriptionAccess).
 */
final class TranscriptionSettings
{
    public const GROUP = 'transcription';

    public const AUDIENCE_OFF = 'off';

    public const AUDIENCE_PREVIEW = 'preview';

    public const AUDIENCE_EVERYONE = 'everyone';

    /**
     * Languages a member can choose, besides "Detect automatically": those
     * measured on staging first, then those not yet measured on real speech.
     *
     * @var array<string, array{0: string, 1: string, 2: bool}> code => [English, Arabic, tested]
     */
    public const LANGUAGES = [
        'ar' => ['Arabic', 'العربية', true],
        'en' => ['English', 'الإنجليزية', true],
        'fr' => ['French', 'الفرنسية', true],
        'es' => ['Spanish', 'الإسبانية', true],
        'de' => ['German', 'الألمانية', true],
        'ur' => ['Urdu', 'الأردية', true],
        'tr' => ['Turkish', 'التركية', false],
        'it' => ['Italian', 'الإيطالية', false],
        'pt' => ['Portuguese', 'البرتغالية', false],
        'nl' => ['Dutch', 'الهولندية', false],
        'ru' => ['Russian', 'الروسية', false],
        'hi' => ['Hindi', 'الهندية', false],
        'fa' => ['Persian', 'الفارسية', false],
        'id' => ['Indonesian', 'الإندونيسية', false],
        'ja' => ['Japanese', 'اليابانية', false],
        'ko' => ['Korean', 'الكورية', false],
        'zh' => ['Chinese', 'الصينية', false],
    ];

    /** Right-to-left languages: their transcripts and exports read right to left. */
    public const RTL = ['ar', 'ur', 'fa', 'he'];

    public static function audience(): string
    {
        $value = (string) SiteSetting::get('transcription_audience', self::AUDIENCE_PREVIEW);

        return in_array($value, [self::AUDIENCE_OFF, self::AUDIENCE_PREVIEW, self::AUDIENCE_EVERYONE], true) ? $value : self::AUDIENCE_PREVIEW;
    }

    /** Whether this person may see Transcribe at all (before launch: the admin email only). */
    public static function visibleTo(?string $email): bool
    {
        return match (self::audience()) {
            self::AUDIENCE_EVERYONE => true,
            self::AUDIENCE_PREVIEW => $email !== null && strcasecmp(trim($email), VoiceCloningAccess::PREVIEW_EMAIL) === 0,
            default => false,
        };
    }

    public static function freeTrySeconds(): int
    {
        return self::int('transcription_free_try_seconds', 'free_try_seconds', 60, 0, 300);
    }

    public static function freePerDay(): int
    {
        return self::int('transcription_free_per_day', 'free_per_day', 50, 0, 100000);
    }

    /** Minutes of audio the whole site may transcribe in a day; 0: no ceiling. */
    public static function dailyMinutes(): int
    {
        return self::int('transcription_daily_minutes', 'daily_minutes', 3000, 0, 1000000);
    }

    public static function keepAudioDays(): int
    {
        return self::int('transcription_keep_audio_days', 'keep_audio_days', 7, 1, 90);
    }

    public static function stuckMinutes(): int
    {
        return self::int('transcription_stuck_minutes', 'stuck_minutes', 30, 5, 240);
    }

    public static function maxUploadMb(): int
    {
        return self::int('transcription_max_upload_mb', 'max_upload_mb', 500, 10, 2000);
    }

    /** Whether each part is heard a second time with its draft, to correct it (more accurate, more cost). */
    public static function review(): bool
    {
        return (string) SiteSetting::get('transcription_review', (bool) config('listen.transcription.review', false) ? '1' : '0') === '1';
    }

    /**
     * The language codes members can choose, in the order of LANGUAGES.
     *
     * @return array<int, string>
     */
    public static function languages(): array
    {
        $saved = SiteSetting::get('transcription_languages');
        $codes = is_string($saved) && $saved !== '' ? (array) json_decode($saved, true) : array_keys(array_filter(self::LANGUAGES, fn ($l) => $l[2]));

        return array_values(array_filter(array_keys(self::LANGUAGES), fn ($code) => in_array($code, $codes, true)));
    }

    /** What a minute of audio costs Listen, for the admin's cost and margin figures. */
    public static function costPerMinute(): float
    {
        $default = (float) config('listen.transcription.cost_per_minute', 0.0025);
        $value = (float) SiteSetting::get('transcription_cost_per_minute', $default);

        return $value > 0 ? $value : $default;
    }

    private static function int(string $key, string $config, int $fallback, int $min, int $max): int
    {
        return max($min, min($max, (int) SiteSetting::get($key, (int) config('listen.transcription.'.$config, $fallback))));
    }
}
