<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Sound environments for the whole recording (echo, hall, phone, radio …).
 *
 * The speech engines give a clean voice only, so the effect is added after,
 * with ffmpeg filters on the server. The effected copy is a new file: the
 * clean voice in the speech cache is shared and never changed. Offered only
 * when the server has ffmpeg; if a run fails, the member gets the clean voice.
 */
final class AudioEffects
{
    /**
     * key => [icon, English name, Arabic name, English line, Arabic line, ffmpeg filter graph, tail seconds]
     * The graph reads [0:a] and ends in [out]; the tail (silence added before the
     * echo, which then adds its own delay) lets the effect ring out, under two seconds.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: float}>
     */
    public const ALL = [
        'studio' => ['g-sliders', 'Studio polish', 'لمسة استوديو', 'Even level, clean low end, broadcast loudness', 'مستوى متوازن ونقاء وصوت بمعايير البث',
            '[0:a]highpass=f=70,acompressor=threshold=0.125:ratio=3:attack=10:release=150:makeup=1.5,loudnorm=I=-16:TP=-1.5:LRA=11[out]', 0.0],
        'echo' => ['g-echo', 'Echo', 'صدى', 'Repeats that fade, like an open space', 'تكرار يتلاشى كأنك في مكان مفتوح',
            '[0:a]apad=pad_dur={tail},aecho=0.8:0.85:280|560:0.35|0.2[out]', 0.9],
        'hall' => ['g-hall', 'Concert hall', 'قاعة كبيرة', 'A large room with a rich reverb', 'قاعة واسعة بصدى غني',
            '[0:a]apad=pad_dur={tail},aecho=0.8:0.88:60|90|130|180|250:0.32|0.28|0.24|0.2|0.15[out]', 1.2],
        'cave' => ['g-cave', 'Cave', 'كهف', 'Deep, long echoes in a closed space', 'صدى عميق وطويل في مكان مغلق',
            '[0:a]apad=pad_dur={tail},aecho=0.8:0.9:450|900|1300:0.45|0.3|0.2,lowpass=f=4000[out]', 0.8],
        'phone' => ['g-phone', 'Phone call', 'مكالمة هاتفية', 'Narrow band, like a voice on the phone', 'نطاق ضيق كصوت عبر الهاتف',
            '[0:a]highpass=f=300,lowpass=f=3400,acompressor=threshold=0.1:ratio=4:makeup=2,alimiter=limit=0.9[out]', 0.0],
        'radio' => ['g-radio', 'Old radio', 'راديو قديم', 'Warm, slightly crunchy broadcast of the past', 'بث دافئ بخشونة خفيفة من الماضي',
            '[0:a]highpass=f=250,lowpass=f=4500,acrusher=bits=12:mode=log:aa=1:mix=0.35,acompressor=threshold=0.15:ratio=3:makeup=1.6,alimiter=limit=0.9[out]', 0.0],
        'megaphone' => ['g-megaphone', 'Megaphone', 'مكبر صوت', 'Loud, harsh and boxy, like a loudspeaker', 'صوت عالٍ وحاد كمكبر الصوت',
            '[0:a]highpass=f=600,lowpass=f=3800,acrusher=bits=8:mode=log:aa=1:mix=0.25,volume=1.6,alimiter=limit=0.9[out]', 0.0],
        'distant' => ['g-far', 'Far away', 'من بعيد', 'A voice coming from across the room', 'صوت قادم من آخر المكان',
            '[0:a]apad=pad_dur={tail},lowpass=f=1800,volume=0.6,aecho=0.8:0.6:90:0.25[out]', 0.4],
        'tape' => ['g-tape', 'Cassette tape', 'شريط كاسيت', 'Soft hiss and a gentle wobble of old tape', 'خشخشة خفيفة واهتزاز الأشرطة القديمة',
            '[0:a]highpass=f=90,lowpass=f=7000,vibrato=f=0.6:d=0.05[v];anoisesrc=color=pink:amplitude=0.006[n];[v][n]amix=inputs=2:duration=first:normalize=0[out]', 0.0],
        'underwater' => ['g-water', 'Underwater', 'تحت الماء', 'Muffled and wavering, as if under water', 'مكتوم ومتموج كأنه تحت الماء',
            '[0:a]lowpass=f=600,chorus=0.6:0.9:50:0.4:0.25:2,volume=1.3,alimiter=limit=0.9[out]', 0.0],
    ];

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function isValid(?string $effect): bool
    {
        return $effect !== null && isset(self::ALL[$effect]);
    }

    public static function available(): bool
    {
        return AudioFormats::serverCanConvert();
    }

    public static function label(string $effect, bool $arabic): string
    {
        return (string) (self::ALL[$effect][$arabic ? 2 : 1] ?? $effect);
    }

    /**
     * Rows for the effect select (value, label), in the page's language.
     *
     * @return array<int, array{value: string, label: string, label_ar: string}>
     */
    public static function options(): array
    {
        $rows = [];
        foreach (self::ALL as $key => $row) {
            $rows[] = ['value' => $key, 'label' => $row[1], 'label_ar' => $row[2]];
        }

        return $rows;
    }

    /**
     * @var array<string, array{en: string, ar: string}>
     */
    public const GROUPS = [
        'clean' => ['en' => 'Clean up', 'ar' => 'تنقية'],
        'space' => ['en' => 'Places', 'ar' => 'أماكن'],
        'device' => ['en' => 'Devices', 'ar' => 'أجهزة'],
    ];

    /**
     * What the picker shows for each effect.
     *
     * @return array<string, array{i: string, g: string, d: string}>
     */
    public static function forPicker(bool $arabic): array
    {
        $out = [];
        foreach (self::ALL as $key => $row) {
            $group = match ($key) {
                'studio' => 'clean',
                'phone', 'radio', 'megaphone', 'tape' => 'device',
                default => 'space',
            };
            $out[$key] = ['i' => $row[0], 'g' => $group, 'd' => $arabic ? $row[4] : $row[3]];
        }

        return $out;
    }

    /**
     * Adds an effect to a recording on the public disk. Returns the new file's
     * path on that disk, or null when it could not be done (the caller keeps
     * the clean recording).
     */
    public static function apply(string $relativePath, string $effect): ?string
    {
        if (! self::isValid($effect)) {
            return null;
        }
        $ffmpeg = AudioFormats::ffmpegPath();
        $disk = Storage::disk('public');
        if ($ffmpeg === null || ! $disk->exists($relativePath)) {
            return null;
        }

        $extension = strtolower((string) pathinfo($relativePath, PATHINFO_EXTENSION)) === 'wav' ? 'wav' : 'mp3';
        $codec = $extension === 'wav' ? ['-codec:a', 'pcm_s16le'] : ['-codec:a', 'libmp3lame', '-b:a', '192k'];
        $target = 'audio/effects/'.pathinfo($relativePath, PATHINFO_FILENAME).'-'.$effect.'-'.bin2hex(random_bytes(4)).'.'.$extension;
        $absoluteTarget = $disk->path($target);
        if (! is_dir(dirname($absoluteTarget))) {
            mkdir(dirname($absoluteTarget), 0775, true);
        }
        [, , , , , $graph, $tail] = self::ALL[$effect];

        try {
            $process = new Process(array_merge(
                [$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-i', $disk->path($relativePath),
                    '-filter_complex', str_replace('{tail}', (string) $tail, $graph), '-map', '[out]', '-ac', '1', '-ar', '44100'],
                $codec,
                [$absoluteTarget],
            ));
            $process->setTimeout(120);
            $process->run();

            if (! $process->isSuccessful() || ! is_file($absoluteTarget) || filesize($absoluteTarget) < 1000) {
                Log::warning('Audio effect failed; keeping the clean recording.', ['effect' => $effect, 'error' => trim($process->getErrorOutput())]);
                if (is_file($absoluteTarget)) {
                    unlink($absoluteTarget);
                }

                return null;
            }

            return $target;
        } catch (\Throwable $e) {
            Log::warning('Audio effect failed; keeping the clean recording.', ['effect' => $effect, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
