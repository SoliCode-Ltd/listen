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
     * At a join, a piece's own quiet is cut to this much before its first
     * word and after its last (so no breath or decay is clipped), and the
     * pause is made up to its length with silence.
     */
    private const KEEP_BEFORE = 0.02;

    private const KEEP_AFTER = 0.04;

    /**
     * @param  iterable<int, string|array{bytes: string, mime_type?: ?string}>  $pieces  in reading order
     * @param  array<int, float>  $pauses  seconds of quiet after piece $k (by its key); $pause when not given
     * @param  array<int, int>  $groups  the part of the script piece $k reads (by its key)
     * @param  bool  $trimJoins  cut each piece's own quiet at a join, so the pause is the whole pause
     *                           between the last word of one piece and the first of the next
     * @return array{path: string, mime_type: string, pieces: array<int, array{0: int, 1: int, 2: int}>}
     *         the temp file (the caller removes it) and, for a WAV, where each piece sits in it:
     *         its first frame, the frame after its last one, and its part
     */
    public static function toFile(iterable $pieces, array $pauses = [], array $groups = [], float $pause = self::PAUSE, bool $trimJoins = false): array
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
        // The previous piece's quiet after its last word: cut once another piece follows.
        $tailCut = 0;
        $tailKept = 0.0;

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
                    $rate = $format['rate'];
                    $from = 0;
                    $to = intdiv($pcm['length'], $frame);
                    $leadKept = 0.0;
                    $nextTailCut = 0;
                    $nextTailKept = 0.0;
                    if ($trimJoins && ($sound = self::sound($bytes, $pcm)) !== null) {
                        if ($previous !== null) {
                            $from = max(0, $sound[0] - (int) round(self::KEEP_BEFORE * $rate));
                            $leadKept = ($sound[0] - $from) / $rate;
                        }
                        $end = min($to, $sound[1] + (int) round(self::KEEP_AFTER * $rate));
                        $nextTailCut = ($to - $end) * $frame;
                        $nextTailKept = ($end - $sound[1]) / $rate;
                    }
                    if ($previous !== null) {
                        if ($tailCut > 0) {
                            // Another piece follows: the previous one's quiet after its last word goes.
                            $size = (int) ftell($file) - $tailCut;
                            if (! ftruncate($file, $size) || fseek($file, $size) !== 0) {
                                throw new \RuntimeException('The voice file could not be saved.');
                            }
                            $spans[count($spans) - 1][1] -= intdiv($tailCut, $frame);
                        }
                        $quiet = ($pauses[$previous] ?? $pause) - ($trimJoins ? $tailKept + $leadKept : 0.0);
                        self::write($file, str_repeat("\0", (int) round(max(0.0, $quiet) * $rate) * $frame));
                    }
                    $start = intdiv((int) ftell($file) - WavPcm::HEADER_BYTES, $frame);
                    $length = ($to - $from) * $frame;
                    self::write($file, $pcm['offset'] === 0 && $length === strlen($bytes) ? $bytes : substr($bytes, $pcm['offset'] + $from * $frame, $length));
                    $spans[] = [$start, $start + ($to - $from), (int) ($groups[$key] ?? 0)];
                    [$tailCut, $tailKept] = [$nextTailCut, $nextTailKept];
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
     * Where a piece's first word starts and its last one ends (frames), by
     * the same rule as the take's own edges (ShareableAudio); null when the
     * piece has no word.
     *
     * @param  array{offset: int, length: int, rate: int, channels: int}  $pcm
     * @return array{0: int, 1: int}|null
     */
    private static function sound(string $bytes, array $pcm): ?array
    {
        $source = fopen('php://memory', 'w+b');
        if ($source === false || fwrite($source, $bytes) !== strlen($bytes)) {
            return null;
        }

        try {
            $frames = intdiv($pcm['length'], 2 * $pcm['channels']);
            $first = ShareableAudio::firstSound($source, $pcm, 0, $frames);
            if ($first === null) {
                return null;
            }
            // A last word of just 80 ms may be missed counting back: then the piece keeps its end.
            $last = ShareableAudio::lastSound($source, $pcm, $first, $frames) ?? $frames;

            return [$first, max($last, $first + 1)];
        } finally {
            fclose($source);
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
