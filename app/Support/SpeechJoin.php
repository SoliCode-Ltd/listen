<?php

namespace App\Support;

/**
 * The pieces a long text is read in, joined into one recording on disk.
 *
 * Each piece is written to a temp file as it comes in and then let go, so
 * only the piece being added is ever held: an hour of 24 kHz speech is
 * about 170 MB, more than the web server's PHP may use on top of the app
 * (256 MB on live). A take of one piece goes the same way.
 */
final class SpeechJoin
{
    /** Seconds of quiet between two pieces. */
    public const PAUSE = 0.3;

    /**
     * @param  iterable<int, string|array{bytes: string, mime_type?: ?string}>  $pieces  in reading order
     * @param  array<int, float>  $pauses  seconds of quiet after piece $k (by its key); $pause when not given
     * @param  array<int, int>  $groups  the part of the script piece $k reads (by its key)
     * @return array{path: string, mime_type: string, pieces: array<int, array{0: int, 1: int, 2: int}>}
     *         the temp file (the caller removes it) and, for a WAV, where each piece sits in it:
     *         its first frame, the frame after its last one, and its part
     */
    public static function toFile(iterable $pieces, array $pauses = [], array $groups = [], float $pause = self::PAUSE): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'listen-take-');
        $file = fopen($path, 'w+b');
        if ($file === false) {
            throw new \RuntimeException('The voice file could not be saved.');
        }

        $format = null;
        $asIs = null;
        $spans = [];
        $previous = null;

        try {
            foreach ($pieces as $key => $piece) {
                [$bytes, $hint] = is_array($piece) ? [(string) $piece['bytes'], $piece['mime_type'] ?? null] : [(string) $piece, null];
                unset($piece);
                $pcm = WavPcm::locate($bytes, $hint);

                if ($previous === null) {
                    // MP3 (or a WAV in another sample format) cannot be joined sample by sample:
                    // it is kept as it came, and further MP3 pieces are added end to end.
                    $asIs = $pcm === null ? (WavPcm::isMpeg($bytes) ? 'audio/mpeg' : 'audio/wav') : null;
                    $format = $pcm !== null ? ['rate' => $pcm['rate'], 'channels' => $pcm['channels']] : null;
                    if ($format !== null) {
                        self::write($file, WavPcm::header(0, $format['rate'], $format['channels']));
                    }
                } elseif (($asIs === null) !== ($pcm !== null) || ($asIs !== null && ($asIs !== 'audio/mpeg' || ! WavPcm::isMpeg($bytes)))) {
                    throw new \RuntimeException('The pieces of this voice came back in different audio formats.');
                }

                if ($format === null) {
                    self::write($file, $bytes);
                } else {
                    $frame = 2 * $format['channels'];
                    if ($previous !== null) {
                        self::write($file, str_repeat("\0", (int) round(($pauses[$previous] ?? $pause) * $format['rate']) * $frame));
                    }
                    $start = intdiv((int) ftell($file) - WavPcm::HEADER_BYTES, $frame);
                    $length = $pcm['length'];
                    self::write($file, $pcm['offset'] === 0 && $length === strlen($bytes) ? $bytes : substr($bytes, $pcm['offset'], $length));
                    $spans[] = [$start, $start + intdiv($length, $frame), (int) ($groups[$key] ?? 0)];
                }

                unset($bytes, $pcm);
                $previous = $key;
            }

            if ($previous === null) {
                throw new \RuntimeException('There is no audio to save.');
            }
            if ($format !== null) {
                $dataBytes = (int) ftell($file) - WavPcm::HEADER_BYTES;
                if (fseek($file, 0) !== 0) {
                    throw new \RuntimeException('The voice file could not be saved.');
                }
                self::write($file, WavPcm::header($dataBytes, $format['rate'], $format['channels']));
            }
            if (! fclose($file)) {
                throw new \RuntimeException('The voice file could not be saved.');
            }
        } catch (\Throwable $e) {
            if (is_resource($file)) {
                fclose($file);
            }
            if (is_file($path)) {
                unlink($path);
            }

            throw $e;
        }

        return ['path' => $path, 'mime_type' => $asIs ?? 'audio/wav', 'pieces' => $spans];
    }

    /**
     * Hands an array's pieces over one by one and removes each from the
     * array as it goes, so a piece is freed as soon as it has been written.
     *
     * @param  array<int, mixed>  $pieces
     * @return \Generator<int, mixed>
     */
    public static function draining(array &$pieces): \Generator
    {
        foreach (array_keys($pieces) as $key) {
            $piece = $pieces[$key];
            unset($pieces[$key]);
            yield $key => $piece;
            unset($piece);
        }
    }

    /**
     * @param  resource  $file
     */
    private static function write($file, string $bytes): void
    {
        if ($bytes !== '' && fwrite($file, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('The voice file could not be saved.');
        }
    }
}
