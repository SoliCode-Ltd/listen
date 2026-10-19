<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * The real length of a stored audio file (WAV or MP3), read from the file
 * itself rather than guessed from the text.
 */
final class AudioDuration
{
    /** MPEG-1 Layer III bitrates in kbps, by the header's 4-bit index. */
    private const MPEG1_BITRATES = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320];

    /** MPEG-2 / 2.5 Layer III bitrates in kbps. */
    private const MPEG2_BITRATES = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];

    /**
     * Seconds of audio in a file on the public disk, or null when it cannot be read.
     */
    public static function ofStoredFile(?string $path): ?float
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($path)) {
                return null;
            }

            // A WAV is measured from its header: an hour of it is about 170 MB,
            // more than the web server's PHP should read in to count it.
            $stream = $disk->readStream($path);
            $head = is_resource($stream) ? (string) fread($stream, 65536) : '';
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (ShareableAudio::isWav($head)) {
                return self::wavSeconds($head, (int) $disk->size($path));
            }

            return self::seconds((string) $disk->get($path));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Seconds of audio in WAV or MP3 bytes, or null when the format is not recognised.
     */
    public static function seconds(string $bytes): ?float
    {
        if (ShareableAudio::isWav($bytes)) {
            return self::wavSeconds($bytes);
        }
        if (ShareableAudio::isMp3($bytes)) {
            return self::mp3Seconds($bytes);
        }

        return null;
    }

    /**
     * $total is the length of the whole file when $bytes is only its head.
     */
    private static function wavSeconds(string $bytes, ?int $total = null): ?float
    {
        $byteRate = null;
        $offset = 12;
        $length = $total ?? strlen($bytes);

        while ($offset + 8 <= strlen($bytes)) {
            $id = substr($bytes, $offset, 4);
            $size = unpack('V', substr($bytes, $offset + 4, 4))[1];

            if ($id === 'fmt ' && $offset + 20 <= strlen($bytes)) {
                $byteRate = unpack('V', substr($bytes, $offset + 16, 4))[1];
            }
            if ($id === 'data') {
                if (! $byteRate) {
                    return null;
                }
                // A streamed WAV can carry a placeholder size; trust the bytes present.
                $dataBytes = min($size, $length - $offset - 8);

                return $dataBytes / $byteRate;
            }

            $offset += 8 + $size + ($size % 2);
        }

        return null;
    }

    private static function mp3Seconds(string $bytes): ?float
    {
        $offset = 0;
        $length = strlen($bytes);

        // Skip an ID3v2 tag: its size is four 7-bit bytes.
        if ($length >= 10 && substr($bytes, 0, 3) === 'ID3') {
            $sizeBytes = array_values(unpack('C4', substr($bytes, 6, 4)));
            $offset = 10 + (($sizeBytes[0] << 21) | ($sizeBytes[1] << 14) | ($sizeBytes[2] << 7) | $sizeBytes[3]);
        }

        // Find the first frame header.
        while ($offset + 4 <= $length && ! (ord($bytes[$offset]) === 0xFF && (ord($bytes[$offset + 1]) & 0xE0) === 0xE0)) {
            $offset++;
        }
        if ($offset + 4 > $length) {
            return null;
        }

        $header = unpack('N', substr($bytes, $offset, 4))[1];
        $version = ($header >> 19) & 0x3; // 3 = MPEG-1, 2 = MPEG-2, 0 = MPEG-2.5
        $layer = ($header >> 17) & 0x3;   // 1 = Layer III
        $bitrateIndex = ($header >> 12) & 0xF;
        $rateIndex = ($header >> 10) & 0x3;
        $mono = (($header >> 6) & 0x3) === 3;

        if ($version === 1 || $layer !== 1 || $bitrateIndex === 0 || $bitrateIndex === 15 || $rateIndex === 3) {
            return null;
        }

        $sampleRate = [44100, 48000, 32000][$rateIndex] / match ($version) {
            3 => 1,
            2 => 2,
            default => 4,
        };
        $samplesPerFrame = $version === 3 ? 1152 : 576;

        // A Xing / Info header (which ffmpeg writes) holds the exact frame count.
        $sideInfo = $version === 3 ? ($mono ? 17 : 32) : ($mono ? 9 : 17);
        $tagAt = $offset + 4 + $sideInfo;
        $tag = substr($bytes, $tagAt, 4);
        if (($tag === 'Xing' || $tag === 'Info') && $tagAt + 12 <= $length) {
            // But pieces joined end to end each carry their own header, and the
            // first one counts only its own piece: so every frame is counted.
            $counted = self::countedMp3Seconds($bytes, $offset);
            if ($counted !== null) {
                return $counted;
            }

            $flags = unpack('N', substr($bytes, $tagAt + 4, 4))[1];
            if ($flags & 0x1) {
                $frames = unpack('N', substr($bytes, $tagAt + 8, 4))[1];
                if ($frames > 0) {
                    return $frames * $samplesPerFrame / $sampleRate;
                }
            }
        }

        // Otherwise assume a constant bitrate.
        $kbps = ($version === 3 ? self::MPEG1_BITRATES : self::MPEG2_BITRATES)[$bitrateIndex];
        $audioBytes = $length - $offset;
        if (substr($bytes, -128, 3) === 'TAG') {
            $audioBytes -= 128;
        }

        return $audioBytes * 8 / ($kbps * 1000);
    }

    /**
     * The length of an MP3 stream from its frames, from the first frame at
     * $offset; null when the bytes are not a clean run of frames.
     */
    private static function countedMp3Seconds(string $bytes, int $offset): ?float
    {
        $length = strlen($bytes);
        $seconds = 0.0;
        $frames = 0;
        $skipped = 0;

        while ($offset + 4 <= $length) {
            if (ord($bytes[$offset]) !== 0xFF || (ord($bytes[$offset + 1]) & 0xE0) !== 0xE0) {
                // An ID3 tag at the start of a joined piece.
                if ($offset + 10 <= $length && substr($bytes, $offset, 3) === 'ID3') {
                    $size = array_values(unpack('C4', substr($bytes, $offset + 6, 4)));
                    $offset += 10 + (($size[0] << 21) | ($size[1] << 14) | ($size[2] << 7) | $size[3]);

                    continue;
                }
                if (substr($bytes, $offset, 3) === 'TAG') {
                    break;
                }
                if (++$skipped > 4096) {
                    return null;
                }
                $offset++;

                continue;
            }

            $header = unpack('N', substr($bytes, $offset, 4))[1];
            $version = ($header >> 19) & 0x3;
            $layer = ($header >> 17) & 0x3;
            $bitrateIndex = ($header >> 12) & 0xF;
            $rateIndex = ($header >> 10) & 0x3;
            if ($version === 1 || $layer !== 1 || $bitrateIndex === 0 || $bitrateIndex === 15 || $rateIndex === 3) {
                if (++$skipped > 4096) {
                    return null;
                }
                $offset++;

                continue;
            }

            $sampleRate = [44100, 48000, 32000][$rateIndex] / match ($version) {
                3 => 1,
                2 => 2,
                default => 4,
            };
            $samples = $version === 3 ? 1152 : 576;
            $kbps = ($version === 3 ? self::MPEG1_BITRATES : self::MPEG2_BITRATES)[$bitrateIndex];
            $frameLength = (int) floor(($samples / 8) * $kbps * 1000 / $sampleRate) + (($header >> 9) & 0x1);

            // An Info or Xing frame describes the stream; it carries no sound.
            $mono = (($header >> 6) & 0x3) === 3;
            $sideInfo = $version === 3 ? ($mono ? 17 : 32) : ($mono ? 9 : 17);
            $tag = substr($bytes, $offset + 4 + $sideInfo, 4);
            if ($tag !== 'Xing' && $tag !== 'Info') {
                $seconds += $samples / $sampleRate;
                $frames++;
            }
            $offset += max(1, $frameLength);
        }

        return $frames > 0 ? $seconds : null;
    }

    /**
     * Whole seconds for storing: at least 1 for any audio.
     */
    public static function wholeSeconds(?float $seconds): ?int
    {
        return $seconds === null || $seconds <= 0 ? null : max(1, (int) round($seconds));
    }

    /**
     * "45 s", "3:07" or "1:02:05" for the admin.
     */
    public static function label(?int $seconds): string
    {
        if ($seconds === null || $seconds <= 0) {
            return '—';
        }
        if ($seconds < 60) {
            return $seconds.' s';
        }

        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }
}
