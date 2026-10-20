<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The voice samples page's long recordings (database/seeders/data/
 * long_form_samples.php), each with how long it took to make, as measured
 * by the "Long-form samples record" workflow (long_form_takes.json). Only a
 * take of the text as it is now, with its recording on disk, is shown: every
 * time on the page is one that was measured.
 */
final class LongFormSamples
{
    public const AUDIO_DIR = 'audio/long-form';

    /** Where the measured takes are read from (a test points it elsewhere). */
    public static ?string $takesFile = null;

    /**
     * The page language's long recordings, longest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forPage(string $language): array
    {
        $arabic = $language === 'ar';
        $takes = self::takes();
        $out = [];
        foreach ((require database_path('seeders/data/long_form_samples.php'))['samples'] as $sample) {
            $take = $takes[$sample['slug']] ?? null;
            $file = self::AUDIO_DIR.'/'.$sample['slug'].'.mp3';
            if ($sample['language'] !== $language || ! is_array($take) || ! is_file(public_path($file))
                || ($take['text_sha1'] ?? '') !== sha1($sample['text'])) {
                continue;
            }
            $audio = (float) ($take['audio_seconds'] ?? 0);
            $made = (float) ($take['made_in_seconds'] ?? 0);
            if ($audio <= 0 || $made <= 0) {
                continue;
            }
            $voice = VoiceCatalog::voiceMeta($sample['voice']);
            $name = trim((string) ($arabic ? ($voice['label_ar'] ?? $voice['label'] ?? '') : ($voice['label'] ?? '')));
            $out[] = [
                'id' => $sample['slug'],
                'kind' => __('ui.samples.long_kind_'.$sample['kind']),
                'title' => $sample['title'],
                'language' => $sample['language'],
                'text' => $sample['text'],
                'chars' => mb_strlen($sample['text']),
                'words' => count(preg_split('/\s+/u', trim($sample['text'])) ?: []),
                'speaker' => $name,
                'avatar' => SpeakerAvatar::url((string) ($voice['label'] ?? ''), $sample['language']),
                'audio_url' => asset($file).'?v='.filemtime(public_path($file)),
                'audio_seconds' => $audio,
                'made_in_seconds' => $made,
                'listen_time' => self::clock($audio),
                'made_in' => self::madeIn($made),
                // Whole times faster, never rounded up: 8.9 reads as 8.
                'times' => (int) floor($audio / $made),
                // How much of the listening bar the making takes, never too thin to see.
                'share' => max(0.008, min(1, $made / $audio)),
                'recorded_on' => self::date((string) ($take['recorded_at'] ?? ''), $arabic),
            ];
        }
        usort($out, fn (array $a, array $b): int => $b['audio_seconds'] <=> $a['audio_seconds']);

        return $out;
    }

    /** "8:31", or "1:02:05" past an hour. */
    public static function clock(float $seconds): string
    {
        $total = (int) round($seconds);
        $h = intdiv($total, 3600);
        $m = intdiv($total % 3600, 60);
        $s = $total % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    /** "38 s", "65 s": always in seconds. */
    public static function madeIn(float $seconds): string
    {
        return __('ui.samples.long_seconds', ['n' => max(1, (int) round($seconds))]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function takes(): array
    {
        $file = self::$takesFile ?? database_path('seeders/data/long_form_takes.json');

        return is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
    }

    private static function date(string $at, bool $arabic): string
    {
        if ($at === '') {
            return '';
        }
        try {
            return Carbon::parse($at)->locale($arabic ? 'ar' : 'en')->translatedFormat('j F Y');
        } catch (\Throwable) {
            return '';
        }
    }
}
