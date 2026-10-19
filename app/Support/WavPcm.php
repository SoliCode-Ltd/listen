<?php

namespace App\Support;

/**
 * Where the 16-bit PCM sits in a speech engine's audio, without copying it:
 * the data chunk of a WAV, or the whole of raw L16 audio (Gemini's audio/L16).
 */
final class WavPcm
{
    /** Bytes of the plain WAV header written(): RIFF, fmt and data. */
    public const HEADER_BYTES = 44;

    /**
     * The 16-bit PCM in $bytes: where it starts, how many bytes of whole
     * frames, its rate and channels. Null for anything else (MP3, a WAV in
     * another sample format, a WAV with no data), which is kept as it came.
     *
     * $total is the length of the file $bytes is the start of, when only its
     * head was read.
     *
     * @return array{offset: int, length: int, rate: int, channels: int}|null
     */
    public static function locate(string $bytes, ?string $mimeTypeHint = null, ?int $total = null): ?array
    {
        $available = strlen($bytes);
        $total ??= $available;

        if ($available >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WAVE') {
            $format = null;
            $offset = 12;
            while ($offset + 8 <= $available) {
                $id = substr($bytes, $offset, 4);
                $size = unpack('V', substr($bytes, $offset + 4, 4))[1];
                if ($id === 'fmt ' && $size >= 16 && $offset + 24 <= $available) {
                    $format = unpack('vtag/vchannels/Vrate/Vbyterate/valign/vbits', substr($bytes, $offset + 8, 16));
                } elseif ($id === 'data') {
                    if ($format === null || ! in_array($format['tag'], [1, 0xFFFE], true) || $format['bits'] !== 16
                        || $format['channels'] < 1 || $format['rate'] < 1) {
                        return null;
                    }
                    $start = $offset + 8;
                    // A streamed WAV can carry a placeholder size: then the bytes present count.
                    $length = $size === 0 || $size === 0xFFFFFFFF ? $total - $start : min($size, $total - $start);
                    $frame = 2 * $format['channels'];

                    return ['offset' => $start, 'length' => max(0, $length - $length % $frame), 'rate' => $format['rate'], 'channels' => $format['channels']];
                }
                $offset += 8 + $size + ($size % 2);
            }

            return null;
        }

        $hint = strtolower(trim((string) $mimeTypeHint));
        if (self::isMpeg($bytes) || str_starts_with($hint, 'audio/mpeg') || str_starts_with($hint, 'audio/mp3')) {
            return null;
        }

        // Raw PCM: its rate is in the type (audio/L16;codec=pcm;rate=24000), else 24 kHz, mono.
        $rate = preg_match('/rate=(\d+)/i', $hint, $m) ? max(1, (int) $m[1]) : 24000;

        return ['offset' => 0, 'length' => $total - $total % 2, 'rate' => $rate, 'channels' => 1];
    }

    /**
     * The header of a 16-bit PCM WAV holding $dataBytes of audio.
     */
    public static function header(int $dataBytes, int $rate, int $channels = 1): string
    {
        return 'RIFF'.pack('V', 36 + $dataBytes).'WAVE'
            .'fmt '.pack('VvvVVvv', 16, 1, $channels, $rate, $rate * $channels * 2, $channels * 2, 16)
            .'data'.pack('V', $dataBytes);
    }

    /**
     * An MP3 frame or ID3 tag. PCM can start with 0xFFFF, which matches a
     * loose MPEG sync mask, so a real frame header is required.
     */
    public static function isMpeg(string $bytes): bool
    {
        if (strlen($bytes) >= 3 && substr($bytes, 0, 3) === 'ID3') {
            return true;
        }
        if (strlen($bytes) < 4 || ord($bytes[0]) !== 0xFF || (ord($bytes[1]) & 0xE0) !== 0xE0) {
            return false;
        }

        $version = (ord($bytes[1]) >> 3) & 0x03;
        $layer = (ord($bytes[1]) >> 1) & 0x03;
        $bitrate = (ord($bytes[2]) >> 4) & 0x0F;
        $sampleRate = (ord($bytes[2]) >> 2) & 0x03;

        return $version !== 0x01 && $layer !== 0x00 && $bitrate !== 0x00 && $bitrate !== 0x0F && $sampleRate !== 0x03;
    }
}
