<?php

namespace App\Support\Transcription;

use App\Models\SiteSetting;
use App\Support\VoiceCloningAccess;

/**
 * The site's own settings for speech to text, set in Admin →
 * Speech to text → Settings (App\Filament\Pages\TranscriptionSettingsPage): who
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
     * Every language a member can choose, besides "Detect automatically", in
     * the order of their English names; "tested": its accuracy was measured on
     * real speech on staging (the admin sees which).
     *
     * @var array<string, array{0: string, 1: string, 2: bool}> code => [English, Arabic, tested]
     */
    public const LANGUAGES = [
        'af' => ['Afrikaans', 'الأفريقانية', false],
        'am' => ['Amharic', 'الأمهرية', false],
        'ar' => ['Arabic', 'العربية', true],
        'hy' => ['Armenian', 'الأرمنية', false],
        'as' => ['Assamese', 'الأسامية', false],
        'ast' => ['Asturian', 'الأسترية', false],
        'az' => ['Azerbaijani', 'الأذربيجانية', false],
        'be' => ['Belarusian', 'البيلاروسية', false],
        'bn' => ['Bengali', 'البنغالية', false],
        'bs' => ['Bosnian', 'البوسنية', false],
        'bg' => ['Bulgarian', 'البلغارية', false],
        'my' => ['Burmese', 'البورمية', false],
        'yue' => ['Cantonese', 'الكانتونية', false],
        'ca' => ['Catalan', 'الكتالانية', false],
        'ceb' => ['Cebuano', 'السيبوانية', false],
        'ny' => ['Chichewa', 'الشيشيوا', false],
        'zh' => ['Chinese', 'الصينية', false],
        'hr' => ['Croatian', 'الكرواتية', false],
        'cs' => ['Czech', 'التشيكية', false],
        'da' => ['Danish', 'الدنماركية', false],
        'nl' => ['Dutch', 'الهولندية', false],
        'en' => ['English', 'الإنجليزية', true],
        'et' => ['Estonian', 'الإستونية', false],
        'fil' => ['Filipino', 'الفلبينية', false],
        'fi' => ['Finnish', 'الفنلندية', false],
        'fr' => ['French', 'الفرنسية', true],
        'ff' => ['Fulah', 'الفولانية', false],
        'gl' => ['Galician', 'الجاليكية', false],
        'lg' => ['Ganda', 'اللوغندية', false],
        'ka' => ['Georgian', 'الجورجية', false],
        'de' => ['German', 'الألمانية', true],
        'el' => ['Greek', 'اليونانية', false],
        'gu' => ['Gujarati', 'الغوجاراتية', false],
        'ha' => ['Hausa', 'الهوسا', false],
        'he' => ['Hebrew', 'العبرية', false],
        'hi' => ['Hindi', 'الهندية', false],
        'hu' => ['Hungarian', 'الهنغارية', false],
        'is' => ['Icelandic', 'الأيسلندية', false],
        'ig' => ['Igbo', 'الإيغبو', false],
        'id' => ['Indonesian', 'الإندونيسية', false],
        'ga' => ['Irish', 'الأيرلندية', false],
        'it' => ['Italian', 'الإيطالية', false],
        'ja' => ['Japanese', 'اليابانية', false],
        'jv' => ['Javanese', 'الجاوية', false],
        'kea' => ['Kabuverdianu', 'الكابوفيردية', false],
        'kn' => ['Kannada', 'الكانادا', false],
        'kk' => ['Kazakh', 'الكازاخية', false],
        'km' => ['Khmer', 'الخميرية', false],
        'ko' => ['Korean', 'الكورية', false],
        'ku' => ['Kurdish', 'الكردية', false],
        'ky' => ['Kyrgyz', 'القيرغيزية', false],
        'lo' => ['Lao', 'اللاوية', false],
        'lv' => ['Latvian', 'اللاتفية', false],
        'ln' => ['Lingala', 'اللينغالا', false],
        'lt' => ['Lithuanian', 'الليتوانية', false],
        'luo' => ['Luo', 'اللوو', false],
        'lb' => ['Luxembourgish', 'اللوكسمبورغية', false],
        'mk' => ['Macedonian', 'المقدونية', false],
        'ms' => ['Malay', 'الملايوية', false],
        'ml' => ['Malayalam', 'المالايالامية', false],
        'mt' => ['Maltese', 'المالطية', false],
        'mi' => ['Māori', 'الماورية', false],
        'mr' => ['Marathi', 'الماراثية', false],
        'mn' => ['Mongolian', 'المنغولية', false],
        'ne' => ['Nepali', 'النيبالية', false],
        'nso' => ['Northern Sotho', 'السوتو الشمالية', false],
        'no' => ['Norwegian', 'النرويجية', false],
        'oc' => ['Occitan', 'الأوكسيتانية', false],
        'or' => ['Odia', 'الأوديا', false],
        'om' => ['Oromo', 'الأورومية', false],
        'ps' => ['Pashto', 'البشتو', false],
        'fa' => ['Persian', 'الفارسية', false],
        'pl' => ['Polish', 'البولندية', false],
        'pt' => ['Portuguese', 'البرتغالية', false],
        'pa' => ['Punjabi', 'البنجابية', false],
        'ro' => ['Romanian', 'الرومانية', false],
        'ru' => ['Russian', 'الروسية', false],
        'sr' => ['Serbian', 'الصربية', false],
        'sn' => ['Shona', 'الشونا', false],
        'sd' => ['Sindhi', 'السندية', false],
        'sk' => ['Slovak', 'السلوفاكية', false],
        'sl' => ['Slovenian', 'السلوفينية', false],
        'so' => ['Somali', 'الصومالية', false],
        'es' => ['Spanish', 'الإسبانية', true],
        'sw' => ['Swahili', 'السواحلية', false],
        'sv' => ['Swedish', 'السويدية', false],
        'ta' => ['Tamil', 'التاميلية', false],
        'tg' => ['Tajik', 'الطاجيكية', false],
        'te' => ['Telugu', 'التيلوجو', false],
        'th' => ['Thai', 'التايلاندية', false],
        'tr' => ['Turkish', 'التركية', false],
        'uk' => ['Ukrainian', 'الأوكرانية', false],
        'umb' => ['Umbundu', 'الأمبوندو', false],
        'ur' => ['Urdu', 'الأردية', true],
        'uz' => ['Uzbek', 'الأوزبكية', false],
        'vi' => ['Vietnamese', 'الفيتنامية', false],
        'cy' => ['Welsh', 'الويلزية', false],
        'wo' => ['Wolof', 'الولوفية', false],
        'xh' => ['Xhosa', 'الخوسا', false],
        'zu' => ['Zulu', 'الزولو', false],
    ];

    /**
     * The round flag shown beside each language (public/images/flags, the
     * studio's own set): the country most of its speakers live in, Saudi
     * Arabia for Arabic.
     *
     * @var array<string, string> language code => flag file name
     */
    public const FLAGS = [
        'af' => 'za',
        'am' => 'et',
        'ar' => 'sa',
        'hy' => 'am',
        'as' => 'in',
        'ast' => 'es',
        'az' => 'az',
        'be' => 'by',
        'bn' => 'bd',
        'bs' => 'ba',
        'bg' => 'bg',
        'my' => 'mm',
        'yue' => 'hk',
        'ca' => 'es-ct',
        'ceb' => 'ph',
        'ny' => 'mw',
        'zh' => 'cn',
        'hr' => 'hr',
        'cs' => 'cz',
        'da' => 'dk',
        'nl' => 'nl',
        'en' => 'us',
        'et' => 'ee',
        'fil' => 'ph',
        'fi' => 'fi',
        'fr' => 'fr',
        'ff' => 'sn',
        'gl' => 'es-ga',
        'lg' => 'ug',
        'ka' => 'ge',
        'de' => 'de',
        'el' => 'gr',
        'gu' => 'in',
        'ha' => 'ng',
        'he' => 'il',
        'hi' => 'in',
        'hu' => 'hu',
        'is' => 'is',
        'ig' => 'ng',
        'id' => 'id',
        'ga' => 'ie',
        'it' => 'it',
        'ja' => 'jp',
        'jv' => 'id',
        'kea' => 'cv',
        'kn' => 'in',
        'kk' => 'kz',
        'km' => 'kh',
        'ko' => 'kr',
        'ku' => 'iq',
        'ky' => 'kg',
        'lo' => 'la',
        'lv' => 'lv',
        'ln' => 'cd',
        'lt' => 'lt',
        'luo' => 'ke',
        'lb' => 'lu',
        'mk' => 'mk',
        'ms' => 'my',
        'ml' => 'in',
        'mt' => 'mt',
        'mi' => 'nz',
        'mr' => 'in',
        'mn' => 'mn',
        'ne' => 'np',
        'nso' => 'za',
        'no' => 'no',
        'oc' => 'fr',
        'or' => 'in',
        'om' => 'et',
        'ps' => 'af',
        'fa' => 'ir',
        'pl' => 'pl',
        'pt' => 'pt',
        'pa' => 'in',
        'ro' => 'ro',
        'ru' => 'ru',
        'sr' => 'rs',
        'sn' => 'zw',
        'sd' => 'pk',
        'sk' => 'sk',
        'sl' => 'si',
        'so' => 'so',
        'es' => 'es',
        'sw' => 'tz',
        'sv' => 'se',
        'ta' => 'in',
        'tg' => 'tj',
        'te' => 'in',
        'th' => 'th',
        'tr' => 'tr',
        'uk' => 'ua',
        'umb' => 'ao',
        'ur' => 'pk',
        'uz' => 'uz',
        'vi' => 'vn',
        'cy' => 'gb-wls',
        'wo' => 'sn',
        'xh' => 'za',
        'zu' => 'za',
    ];

    /** The flag of a language, as a web address (null when it has none). */
    public static function flagUrl(string $code): ?string
    {
        $flag = self::FLAGS[$code] ?? null;

        return $flag !== null && is_file(public_path('images/flags/'.$flag.'.svg')) ? asset('images/flags/'.$flag.'.svg') : null;
    }

    /** Right-to-left languages: their transcripts and exports read right to left. */
    public const RTL = ['ar', 'ur', 'fa', 'he', 'ps', 'sd'];

    /** Spoken languages the translator does not take: they can be written out, not shown in another language. */
    public const NOT_TRANSLATED = ['ast', 'kea', 'umb'];

    /** Where the translator knows a language by another code. */
    public const TRANSLATOR_CODES = ['fil' => 'tl', 'zh' => 'zh-CN'];

    public static function audience(): string
    {
        $value = (string) SiteSetting::get('transcription_audience', self::AUDIENCE_PREVIEW);

        return in_array($value, [self::AUDIENCE_OFF, self::AUDIENCE_PREVIEW, self::AUDIENCE_EVERYONE], true) ? $value : self::AUDIENCE_PREVIEW;
    }

    /** Whether this person may see speech to text at all (before launch: the admin email only). */
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
        // Every language unless the admin chose some (Speech to text → Settings).
        $codes = is_string($saved) && $saved !== '' ? (array) json_decode($saved, true) : array_keys(self::LANGUAGES);

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
