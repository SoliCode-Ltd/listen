<?php

namespace App\Support;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Generated speech is stored as MPEG audio so it can be sent on WhatsApp.
 * WhatsApp accepts MP3, AAC, and Opus, and rejects WAV.
 */
class ShareableAudio
{
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
        if (strlen($wav) < 44 || ! self::canRunProcesses()) {
            return null;
        }

        $ffmpeg = (new ExecutableFinder())->find('ffmpeg');
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

            $process = new Process([
                $ffmpeg,
                '-hide_banner',
                '-loglevel', 'error',
                '-y',
                '-i', $input,
                '-codec:a', 'libmp3lame',
                '-b:a', '192k',
                '-ac', '1',
                '-f', 'mp3',
                $output,
            ]);
            $process->setTimeout(30);
            $process->run();

            if (! $process->isSuccessful() || ! is_file($output)) {
                return null;
            }

            $mp3 = file_get_contents($output);

            return is_string($mp3) && $mp3 !== '' ? $mp3 : null;
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

    private static function canRunProcesses(): bool
    {
        if (! function_exists('proc_open')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('proc_open', $disabled, true);
    }
}
