<?php

namespace App\Support;

use App\Models\Plan;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * File types members can download their audio in. Each plan chooses its own in the
 * admin (Plans → Downloads). MP3 and WAV are made in the browser, so they always
 * work; the others are converted on the server and are offered only when the
 * server has ffmpeg.
 */
class AudioFormats
{
    public const DEFAULT = ['mp3'];

    /**
     * @var array<string, array{label:string, mime:string, browser:bool, args:array<int,string>}>
     */
    public const ALL = [
        'mp3' => ['label' => 'MP3', 'mime' => 'audio/mpeg', 'browser' => true, 'args' => ['-codec:a', 'libmp3lame', '-b:a', '192k', '-f', 'mp3']],
        'wav' => ['label' => 'WAV', 'mime' => 'audio/wav', 'browser' => true, 'args' => ['-codec:a', 'pcm_s16le', '-f', 'wav']],
        'm4a' => ['label' => 'M4A (AAC)', 'mime' => 'audio/mp4', 'browser' => false, 'args' => ['-codec:a', 'aac', '-b:a', '160k', '-movflags', '+faststart', '-f', 'ipod']],
        'ogg' => ['label' => 'OGG (Opus)', 'mime' => 'audio/ogg', 'browser' => false, 'args' => ['-codec:a', 'libopus', '-b:a', '96k', '-f', 'ogg']],
        'flac' => ['label' => 'FLAC', 'mime' => 'audio/flac', 'browser' => false, 'args' => ['-codec:a', 'flac', '-f', 'flac']],
    ];

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_map(fn (array $format): string => $format['label'], self::ALL);
    }

    /**
     * The types a plan offers, in a fixed order; a plan with none chosen keeps MP3.
     *
     * @return array<int, string>
     */
    public static function chosenFor(?Plan $plan): array
    {
        $chosen = $plan?->download_formats;
        $chosen = is_array($chosen) ? array_values(array_intersect(array_keys(self::ALL), $chosen)) : [];

        return $chosen !== [] ? $chosen : self::DEFAULT;
    }

    /**
     * The chosen types this server can make right now.
     *
     * @return array<int, string>
     */
    public static function availableFor(?Plan $plan): array
    {
        $available = array_values(array_filter(
            self::chosenFor($plan),
            fn (string $format): bool => self::ALL[$format]['browser'] || self::serverSupports($format),
        ));

        return $available !== [] ? $available : self::DEFAULT;
    }

    /**
     * What the download menu needs: each type, its label, and whether the browser makes it.
     *
     * @return array<int, array{key:string, label:string, browser:bool}>
     */
    public static function menuFor(?Plan $plan): array
    {
        return array_map(fn (string $format): array => [
            'key' => $format,
            'label' => self::ALL[$format]['label'],
            'browser' => self::ALL[$format]['browser'],
        ], self::availableFor($plan));
    }

    public static function isServerFormat(string $format): bool
    {
        return isset(self::ALL[$format]) && ! self::ALL[$format]['browser'];
    }

    public static function serverCanConvert(): bool
    {
        return once(fn (): bool => self::ffmpeg() !== null);
    }

    /**
     * Whether this server's ffmpeg has the encoder for a type (some builds lack Opus).
     */
    public static function serverSupports(string $format): bool
    {
        if (! self::isServerFormat($format) || ! self::serverCanConvert()) {
            return false;
        }
        $codec = self::ALL[$format]['args'][1];

        return in_array($codec, self::serverEncoders(), true);
    }

    /**
     * @return array<int, string>
     */
    private static function serverEncoders(): array
    {
        return once(fn (): array => \Illuminate\Support\Facades\Cache::remember('audio_formats.encoders', now()->addDay(), function (): array {
            try {
                $process = new Process([self::ffmpeg(), '-hide_banner', '-encoders']);
                $process->setTimeout(10);
                $process->run();
                preg_match_all('/^\s*A[.A-Z]{5}\s+(\S+)/m', $process->getOutput(), $matches);

                return $matches[1] ?? [];
            } catch (\Throwable) {
                return [];
            }
        }));
    }

    /**
     * Converts an audio file to the given type; null when it could not be done.
     */
    public static function convert(string $absolutePath, string $format): ?string
    {
        $ffmpeg = self::ffmpeg();
        if ($ffmpeg === null || ! isset(self::ALL[$format]) || ! is_file($absolutePath)) {
            return null;
        }

        $output = sys_get_temp_dir().'/listen-download-'.bin2hex(random_bytes(8)).'.'.$format;

        try {
            $process = new Process(array_merge(
                // The file's own channels: a voice in a sound environment is stereo
                // (folding it to mono drops the room's width and 3 dB of level).
                [$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-i', $absolutePath, '-vn'],
                self::ALL[$format]['args'],
                [$output],
            ));
            $process->setTimeout(60);
            $process->run();

            if (! $process->isSuccessful() || ! is_file($output)) {
                return null;
            }

            $bytes = file_get_contents($output);

            return is_string($bytes) && $bytes !== '' ? $bytes : null;
        } catch (\Throwable) {
            return null;
        } finally {
            if (is_file($output)) {
                unlink($output);
            }
        }
    }

    /**
     * The server's ffmpeg, or null when there is none (or it may not be run).
     */
    public static function ffmpegPath(): ?string
    {
        return self::ffmpeg();
    }

    private static function ffmpeg(): ?string
    {
        if (! function_exists('proc_open') || in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return null;
        }

        $configured = trim((string) config('services.gemini_tts.ffmpeg_path', ''));
        // FFMPEG_PATH=none: run as a server without ffmpeg (audio is kept as the engines send it).
        if (strtolower($configured) === 'none') {
            return null;
        }
        if ($configured !== '' && is_executable($configured)) {
            return $configured;
        }

        // storage/app/bin is where the "Server deploy" workflow's ffmpeg mode
        // puts a copy on hosts that have none. The usual system folders are
        // named too: web PHP (PHP-FPM) often runs with an empty PATH, so a
        // server ffmpeg the command line finds would otherwise be missed.
        return (new ExecutableFinder())->find('ffmpeg', null, [storage_path('app/bin'), '/usr/bin', '/usr/local/bin', '/bin']);
    }
}
