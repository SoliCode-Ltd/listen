<?php

namespace App\Support;

use Symfony\Component\Process\Process;

/**
 * Generated speech is stored as MPEG audio so it can be sent on WhatsApp.
 * WhatsApp accepts MP3, AAC, and Opus, and rejects WAV.
 */
class ShareableAudio
{
    /**
     * Trims quiet (under -50 dB; voice is counted from 80 ms of sound above it)
     * before the first word down to 0.2 s and after the last word down to
     * 0.35 s. Speech sits far above that; the voice itself is untouched.
     */
    private const EDGE_SILENCE_TRIM = 'silenceremove=start_periods=1:start_duration=0.08:start_threshold=-50dB:start_silence=0.2,'
        .'areverse,silenceremove=start_periods=1:start_duration=0.08:start_threshold=-50dB:start_silence=0.35,areverse';

    /**
     * @return array{bytes: string, extension: string}
     */
    public static function package(string $bytes): array
    {
        if (self::isMp3($bytes)) {
            return ['bytes' => $bytes, 'extension' => 'mp3'];
        }

        if (self::isWav($bytes)) {
            $mp3 = self::transcodeWavToMp3($bytes);
            if (is_string($mp3) && self::isMp3($mp3)) {
                return ['bytes' => $mp3, 'extension' => 'mp3'];
            }
        }

        return [
            'bytes' => $bytes,
            'extension' => self::isWav($bytes) ? 'wav' : 'mp3',
        ];
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

    private static function transcodeWavToMp3(string $wav): ?string
    {
        if (strlen($wav) < 44) {
            return null;
        }

        // The same ffmpeg the download formats use. Web PHP often runs with
        // an empty PATH, and looking only there kept HD audio as WAV.
        $ffmpeg = AudioFormats::ffmpegPath();
        if ($ffmpeg === null) {
            return null;
        }

        $id = bin2hex(random_bytes(8));
        $input = sys_get_temp_dir().'/listen-'.$id.'.wav';
        $output = sys_get_temp_dir().'/listen-'.$id.'.mp3';

        try {
            if (file_put_contents($input, $wav) === false) {
                return null;
            }

            // Long silence before the first word or after the last one is cut
            // (members pay by the second: a take once ended in 3 minutes of hiss),
            // keeping a natural 0.2 s lead and 0.35 s tail; anything shorter is
            // left alone. If that fails, the take is encoded as it came.
            foreach ([true, false] as $trim) {
                $process = new Process(array_merge(
                    [$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-i', $input],
                    $trim ? ['-af', self::EDGE_SILENCE_TRIM] : [],
                    ['-codec:a', 'libmp3lame', '-b:a', '192k', '-ac', '1', '-f', 'mp3', $output],
                ));
                $process->setTimeout(30);
                $process->run();

                $mp3 = $process->isSuccessful() && is_file($output) ? file_get_contents($output) : false;
                // A trim leaving under about a quarter second (192 kbps is 24,000
                // bytes a second) means the take was all quiet: keep it whole.
                if (is_string($mp3) && $mp3 !== '' && (! $trim || strlen($mp3) > 6000)) {
                    return $mp3;
                }
            }

            return null;
        } catch (\Throwable) {
            return null;
        } finally {
            if (is_file($input)) {
                unlink($input);
            }
            if (is_file($output)) {
                unlink($output);
            }
        }
    }
}
