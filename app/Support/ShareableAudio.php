<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Generated speech is stored as MPEG audio so it can be sent on WhatsApp.
 * WhatsApp accepts MP3, AAC, and Opus, and rejects WAV.
 */
class ShareableAudio
{
    /**
     * Quiet (under -50 dB; voice is counted from 80 ms of sound above it) is
     * trimmed before the first word down to 0.2 s and after the last word down
     * to 0.35 s. Speech sits far above that; the voice itself is untouched.
     */
    public const QUIET_DB = -50.0;

    private const VOICE_SECONDS = 0.08;

    private const LEAD_SECONDS = 0.2;

    private const TAIL_SECONDS = 0.35;

    /** Sound is looked for in windows of 10 ms. */
    private const WINDOW_SECONDS = 0.01;

    /**
     * @return array{bytes: string, extension: string}
     */
    public static function package(string $bytes): array
    {
        if (self::isMp3($bytes)) {
            return ['bytes' => $bytes, 'extension' => 'mp3'];
        }
        if (! self::isWav($bytes)) {
            return ['bytes' => $bytes, 'extension' => 'mp3'];
        }

        $input = self::temporaryFile();
        $packaged = ['path' => $input, 'extension' => 'wav'];
        try {
            if (file_put_contents($input, $bytes) !== strlen($bytes)) {
                return ['bytes' => $bytes, 'extension' => 'wav'];
            }
            $packaged = self::packageFile($input);
            $mp3 = $packaged['extension'] === 'mp3' ? file_get_contents($packaged['path']) : false;

            return is_string($mp3) && self::isMp3($mp3)
                ? ['bytes' => $mp3, 'extension' => 'mp3']
                : ['bytes' => $bytes, 'extension' => 'wav'];
        } finally {
            self::discard($input, $packaged['path']);
        }
    }

    /**
     * Packages a take from its file, so a long one is never held in memory:
     * a WAV is brought to the common loudness and encoded to a new MP3 temp
     * file (the caller removes it); an MP3, or a WAV that cannot be converted,
     * is handed back as it is (not levelled: that would mean encoding it twice).
     *
     * $pieces says where each piece of a long text sits in the file (its first
     * frame, the frame after its last one, and its part of the script), so the
     * pieces can be levelled to each other.
     *
     * @param  array<int, array<int, int>>  $pieces
     * @return array{path: string, extension: string}
     */
    public static function packageFile(string $path, array $pieces = []): array
    {
        clearstatcache(true, $path);
        $size = is_file($path) ? (int) filesize($path) : 0;
        $head = self::head($path, 65536);
        if (! self::isWav($head)) {
            return ['path' => $path, 'extension' => self::isMp3($head) ? 'mp3' : 'wav'];
        }

        // The same ffmpeg the download formats use. Web PHP often runs with
        // an empty PATH, and looking only there kept HD audio as WAV.
        $ffmpeg = AudioFormats::ffmpegPath();
        if ($ffmpeg === null || $size < WavPcm::HEADER_BYTES) {
            return ['path' => $path, 'extension' => 'wav'];
        }

        $pcm = WavPcm::locate($head, null, $size);
        $seconds = $pcm !== null ? $pcm['length'] / (2 * $pcm['channels'] * $pcm['rate']) : $size / 48000;
        $voice = $pcm !== null ? self::voice($path, $pcm) : null;
        $output = self::temporaryFile();

        // Long quiet before the first word or after the last one is cut
        // (members pay by the second: a take once ended in 3 minutes of hiss),
        // keeping a natural 0.2 s lead and 0.35 s tail; anything shorter is
        // left alone. If that fails (or runs out of time), the take is encoded as it came.
        $trim = $voice !== null ? ['atrim=start_sample='.$voice[0].':end_sample='.$voice[1]] : [];
        // Every take is brought to the same loudness here, once (SpeechLoudness);
        // a run that fails is tried without it, then as the take came.
        $level = $voice !== null && $pcm !== null ? SpeechLoudness::filters($ffmpeg, $path, $pcm, $voice, $pieces, self::timeout($seconds)) : [];
        $attempts = array_values(array_unique([array_merge($trim, $level), $trim, []], SORT_REGULAR));
        foreach ($attempts as $filters) {
            try {
                $process = new Process(array_merge(
                    [$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-i', $path],
                    $filters !== [] ? ['-af', implode(',', $filters)] : [],
                    ['-codec:a', 'libmp3lame', '-b:a', '192k', '-ac', '1', '-f', 'mp3', $output],
                ));
                $process->setTimeout(self::timeout($seconds));
                $process->run();

                if ($process->isSuccessful() && self::isMp3(self::head($output, 16))) {
                    return ['path' => $output, 'extension' => 'mp3'];
                }
            } catch (\Throwable) {
                // Timed out or could not start: the next try encodes the take as it came.
            }
        }

        self::discard($output);

        return ['path' => $path, 'extension' => 'wav'];
    }

    /**
     * Writes a packaged take to the public disk as a stream (an hour of MP3
     * is about 70 MB, of WAV 170 MB).
     */
    public static function store(string $file, string $target): void
    {
        $stream = @fopen($file, 'rb');
        try {
            // The public disk reports a failed write instead of throwing: without this
            // the generation would point at a file that is not there.
            if ($stream === false || ! Storage::disk('public')->writeStream($target, $stream)) {
                throw new \RuntimeException('The voice file could not be saved.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Removes temp files, each once.
     */
    public static function discard(string ...$paths): void
    {
        foreach (array_unique($paths) as $path) {
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Seconds ffmpeg may take on a take: the configured base, plus a tenth
     * of the take's length (an hour of audio is encoded in well under a minute).
     */
    public static function timeout(float $seconds): float
    {
        return max(1.0, (float) config('listen.audio.encode_timeout_seconds', 30)) + max(0.0, $seconds) / 10;
    }

    public static function isWav(string $bytes): bool
    {
        return strlen($bytes) >= 12
            && substr($bytes, 0, 4) === 'RIFF'
            && substr($bytes, 8, 4) === 'WAVE';
    }

    public static function isMp3(string $bytes): bool
    {
        if (strlen($bytes) >= 3 && substr($bytes, 0, 3) === 'ID3') {
            return true;
        }

        return strlen($bytes) >= 2
            && ord($bytes[0]) === 0xFF
            && (ord($bytes[1]) & 0xE0) === 0xE0;
    }

    /**
     * The part of a take that is kept: from 0.2 s before the first word to
     * 0.35 s after the last one, in frames, then where the first word starts
     * and the last one ends. Only the quiet edges are read, a few blocks from
     * each end. Null when the take has no word (all quiet: it is kept whole).
     *
     * @param  array{offset: int, length: int, rate: int, channels: int}  $pcm
     * @return array{0: int, 1: int, 2: int, 3: int}|null
     */
    public static function voice(string $path, array $pcm): ?array
    {
        $file = @fopen($path, 'rb');
        if ($file === false) {
            return null;
        }

        try {
            $frames = intdiv($pcm['length'], 2 * $pcm['channels']);
            $first = self::firstSound($file, $pcm, 0, $frames);
            if ($first === null) {
                return null;
            }
            // Counted back from the end, the windows of a word of just 80 ms may
            // fall either side of it: then that word is the last one.
            [$window, $needed] = self::detector($pcm);
            $last = max(self::lastSound($file, $pcm, $first, $frames) ?? 0, min($frames, $first + $needed * $window));
        } finally {
            fclose($file);
        }

        return [
            max(0, $first - (int) round(self::LEAD_SECONDS * $pcm['rate'])),
            min($frames, $last + (int) round(self::TAIL_SECONDS * $pcm['rate'])),
            $first,
            $last,
        ];
    }

    /**
     * The first frame of the first word (80 ms of sound) at or after $from,
     * reading forward from there; null when there is none before $to.
     *
     * @param  resource  $file
     * @param  array{offset: int, length: int, rate: int, channels: int}  $pcm
     */
    public static function firstSound($file, array $pcm, int $from, int $to): ?int
    {
        [$window, $needed, $limit] = self::detector($pcm);
        $windowBytes = $window * 2 * $pcm['channels'];
        $run = 0;
        $runStart = $from;

        for ($at = $from; $at < $to; $at += 100 * $window) {
            $block = self::read($file, $pcm, $at, min($to, $at + 100 * $window));
            // A second with no sound at all (long hiss) is passed over at once.
            if (! self::loud($block, $limit)) {
                $run = 0;

                continue;
            }
            for ($offset = 0, $n = strlen($block); $offset < $n; $offset += $windowBytes) {
                if (self::loud(substr($block, $offset, $windowBytes), $limit)) {
                    if ($run++ === 0) {
                        $runStart = $at + intdiv($offset, 2 * $pcm['channels']);
                    }
                    if ($run >= $needed) {
                        return $runStart;
                    }
                } else {
                    $run = 0;
                }
            }
        }

        return null;
    }

    /**
     * The frame after the last word (80 ms of sound) between $from and $to,
     * reading backward from $to; null when there is none.
     *
     * @param  resource  $file
     * @param  array{offset: int, length: int, rate: int, channels: int}  $pcm
     */
    public static function lastSound($file, array $pcm, int $from, int $to): ?int
    {
        [$window, $needed, $limit] = self::detector($pcm);
        $frameBytes = 2 * $pcm['channels'];
        $run = 0;
        $runEnd = $to;

        // Windows are counted back from the end, so the last one is whole.
        for ($end = $to; $end > $from; $end -= 100 * $window) {
            $start = max($from, $end - 100 * $window);
            $block = self::read($file, $pcm, $start, $end);
            if (! self::loud($block, $limit)) {
                $run = 0;

                continue;
            }
            for ($stop = strlen($block); $stop > 0; $stop -= $window * $frameBytes) {
                $begin = max(0, $stop - $window * $frameBytes);
                if (self::loud(substr($block, $begin, $stop - $begin), $limit)) {
                    if ($run++ === 0) {
                        $runEnd = $start + intdiv($stop, $frameBytes);
                    }
                    if ($run >= $needed) {
                        return $runEnd;
                    }
                } else {
                    $run = 0;
                }
            }
        }

        return null;
    }

    /**
     * Window length (frames), windows of sound that make a word, and the
     * level (16-bit sample value) of -50 dB.
     *
     * @param  array{rate: int}  $pcm
     * @return array{0: int, 1: int, 2: int}
     */
    private static function detector(array $pcm): array
    {
        return [
            max(1, (int) round(self::WINDOW_SECONDS * $pcm['rate'])),
            (int) round(self::VOICE_SECONDS / self::WINDOW_SECONDS),
            (int) ceil(32768 * 10 ** (self::QUIET_DB / 20)),
        ];
    }

    /**
     * @param  resource  $file
     * @param  array{offset: int, channels: int}  $pcm
     */
    private static function read($file, array $pcm, int $from, int $to): string
    {
        $frameBytes = 2 * $pcm['channels'];
        if ($to <= $from || fseek($file, $pcm['offset'] + $from * $frameBytes) !== 0) {
            return '';
        }

        return (string) fread($file, ($to - $from) * $frameBytes);
    }

    /**
     * Whether any sample in the window reaches the limit. WAV is little-endian,
     * as is every machine this runs on ('s' reads the machine's order).
     */
    private static function loud(string $window, int $limit): bool
    {
        if (strlen($window) < 2) {
            return false;
        }
        $samples = unpack('s*', strlen($window) % 2 === 0 ? $window : substr($window, 0, -1));

        return max($samples) >= $limit || min($samples) <= -$limit;
    }

    private static function head(string $path, int $bytes): string
    {
        $file = @fopen($path, 'rb');
        if ($file === false) {
            return '';
        }
        $head = (string) fread($file, $bytes);
        fclose($file);

        return $head;
    }

    private static function temporaryFile(): string
    {
        return (string) tempnam(sys_get_temp_dir(), 'listen-');
    }
}
