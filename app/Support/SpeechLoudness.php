<?php

namespace App\Support;

use Symfony\Component\Process\Process;

/**
 * Every take, and every piece of a long one, at the same loudness.
 *
 * Takes come from the engines at very different levels (premium ones from
 * about -24 to -13 LUFS, often already touching full scale), and the pieces a
 * long text is read in each come at their own. On its way to MP3 (in
 * ShareableAudio, which every take passes through once) a take is measured
 * with ffmpeg's EBU R128 meter, then each piece is given one fixed gain that
 * brings it to -16 LUFS, the spoken-word streaming level, and a true-peak
 * limiter keeps the peaks under -1 dBTP.
 *
 * Measured, not a single-pass normaliser: one fixed gain keeps the voice's own
 * rise and fall (a normaliser that rides the level, as loudnorm does whenever
 * a take's peaks stand out, which is most of them, evens out the delivery),
 * and the one measurement gives the level of every piece. The limiter only
 * touches the few peaks that would cross the ceiling.
 */
final class SpeechLoudness
{
    /** Integrated loudness every finished take is brought to. */
    public const TARGET_LUFS = -16.0;

    /** The finished MP3's true peak stays under this. */
    public const CEILING_DBTP = -1.0;

    /**
     * Where the limiter holds the peaks, working at four times the sample
     * rate so it sees the peaks between samples: half a dB under the ceiling
     * leaves room for the way back to the take's rate and the MP3.
     */
    private const LIMIT_DB = -1.5;

    /**
     * A short take's integrated loudness reads low when the quiet kept around
     * its voice (0.2 s before, 0.35 s after) weighs in, so every take is
     * measured on its voice alone. With less than one 400 ms block of voice
     * there is no integrated loudness to go by: the take is then raised only
     * as far as its peaks allow, never into the limiter. A piece under 3 s is
     * not levelled on its own (a few blocks say more about its words than its
     * level): it takes its part's gain.
     */
    public const SHORT_SECONDS = 3.0;

    /** No take is raised or lowered by more than this. */
    private const MAX_GAIN_DB = 15.0;

    /** Pieces of one part are moved toward each other by at most this. */
    private const MAX_LEVELLING_DB = 10.0;

    /** Pieces within this of their part's level are left as they are. */
    private const SAME_LEVEL_DB = 0.5;

    /** Quieter than this is not speech (silence or hiss): left as it is. */
    private const QUIETEST_LUFS = -50.0;

    /**
     * The filters that bring the kept part of a take to the target, for the
     * one pass that encodes it: a gain (one per piece when pieces differ) and
     * the true-peak limiter. [] when the take cannot be measured.
     *
     * @param  array{offset: int, length: int, rate: int, channels: int}  $pcm
     * @param  array<int, int>  $voice  the first frame kept, the frame after the last kept,
     *                                  where the first word starts and where the last one ends
     * @param  array<int, array<int, int>>  $pieces  [first frame, end frame, part] of each piece
     * @return array<int, string>
     */
    public static function filters(string $ffmpeg, string $path, array $pcm, array $voice, array $pieces, float $timeout): array
    {
        $rate = $pcm['rate'];
        $kept = [$voice[0], $voice[1]];
        $spoken = [$voice[2] ?? $voice[0], $voice[3] ?? $voice[1]];
        $measured = self::measure($ffmpeg, $path, ($spoken[1] - $spoken[0]) / $rate < self::SHORT_SECONDS, $timeout);
        if ($measured === null) {
            return [];
        }

        $pieces = array_values(array_filter(
            array_map(fn (array $p): array => [max($p[0], $kept[0]), min($p[1], $kept[1]), (int) ($p[2] ?? 0)], $pieces),
            fn (array $p): bool => $p[1] > $p[0],
        ));
        $relative = count($pieces) > 1 ? self::levelling($measured['blocks'], $pieces, $rate) : [];

        // The voice, without the quiet kept around it; under one block of it, all that is kept.
        $blocks = self::within($measured['blocks'], $spoken, $rate);
        $unmeasured = $blocks === [];
        $levels = [];
        foreach ($unmeasured ? self::within($measured['blocks'], $kept, $rate) : $blocks as [$start, $level]) {
            // Levelled pieces count as they will be; a block across a join counts for neither.
            $move = $relative === [] ? 0.0 : self::pieceGain($start, $pieces, $relative, $rate);
            if ($move !== null) {
                $levels[] = $level + $move;
            }
        }
        $loudness = self::gated($levels);
        if ($loudness === null || $loudness < self::QUIETEST_LUFS) {
            return [];
        }

        // The encoder's own change is allowed for, so the stored MP3 measures the target.
        $gain = max(-self::MAX_GAIN_DB, min(self::MAX_GAIN_DB, self::TARGET_LUFS - self::encoderGainDb($rate) - $loudness));
        if ($unmeasured && $gain > 0) {
            $peak = max(array_column(self::within($measured['peaks'], $kept, $rate, 0.1), 1) ?: [-INF]);
            $gain = is_finite($peak) ? min($gain, max(0.0, self::LIMIT_DB - $peak)) : 0.0;
        }

        $filters = self::gainFilters($gain, $pieces, $relative, $rate);
        $filters[] = 'aresample='.(4 * $rate);
        $filters[] = 'alimiter=limit='.round(10 ** (self::LIMIT_DB / 20), 4).':attack=5:release=50:level=disabled'.(self::limiterCompensates($ffmpeg) ? ':latency=1' : '');
        $filters[] = 'aresample='.$rate;

        return $filters;
    }

    /**
     * What the MP3 encoder (LAME, at the constant bitrate used here) does to
     * the level: it scales the sound by 0.95 up to 160 kbps (any take at 24 kHz
     * or less) and by 0.97 at 192 kbps, to leave decoders room.
     */
    public static function encoderGainDb(int $rate): float
    {
        return $rate <= 24000 ? -0.45 : -0.26;
    }

    /**
     * The loudness of every 400 ms block (EBU R128 momentary loudness, a new
     * block each 100 ms) and, for a short take, the true peak of every 100 ms.
     *
     * @return array{blocks: array<int, array{0: float, 1: float}>, peaks: array<int, array{0: float, 1: float}>}|null
     *         [start in seconds, level], [start in seconds, peak dBTP]
     */
    private static function measure(string $ffmpeg, string $path, bool $peaks, float $timeout): ?array
    {
        try {
            $process = new Process([$ffmpeg, '-hide_banner', '-nostats', '-loglevel', 'info', '-i', $path,
                '-af', 'ebur128'.($peaks ? '=peak=true' : ''), '-f', 'null', '-']);
            $process->setTimeout($timeout);
            $process->run();
            if (! $process->isSuccessful()) {
                return null;
            }
            $log = $process->getErrorOutput();
        } catch (\Throwable) {
            return null;
        }

        // "t: 3.0999  TARGET:-23 LUFS  M: -17.4 S: ..." once each 100 ms; M is the last 400 ms.
        // (An hour is 36,000 lines: read as three flat lists, then let go.)
        preg_match_all('/\bt:\s*([\d.]+)\s+TARGET:\S+\s+LUFS\s+M:\s*(-?[\d.]+)'.($peaks ? '.*?FTPK:((?:\s+(?:-?[\d.]+|-inf))+)\s+dBFS' : '').'/', $log, $m);
        unset($log);
        $blocks = [];
        $truePeaks = [];
        foreach ($m[1] as $i => $time) {
            $end = (float) $time;
            $level = (float) $m[2][$i];
            if ($end >= 0.39 && $level > -70.0) {
                $blocks[] = [$end - 0.4, $level];
            }
            if ($peaks) {
                $values = array_map(fn (string $v): float => $v === '-inf' ? -INF : (float) $v, preg_split('/\s+/', trim($m[3][$i])) ?: []);
                $truePeaks[] = [max($end - 0.1, 0.0), max($values ?: [-INF])];
            }
        }
        unset($m);

        return $blocks === [] ? null : ['blocks' => $blocks, 'peaks' => $truePeaks];
    }

    /**
     * How far each piece is moved toward the others of its part (dB): each
     * piece long enough to measure toward its part's middle level; a short
     * one, or one already within half a dB, is left where it is.
     *
     * @param  array<int, array{0: float, 1: float}>  $blocks
     * @param  array<int, array{0: int, 1: int, 2: int}>  $pieces
     * @return array<int, float>
     */
    private static function levelling(array $blocks, array $pieces, int $rate): array
    {
        $levels = [];
        foreach ($pieces as $k => [$start, $end, $part]) {
            if (($end - $start) / $rate >= self::SHORT_SECONDS) {
                $levels[$part][$k] = self::gated(array_column(self::within($blocks, [$start, $end], $rate), 1));
            }
        }

        $moves = array_fill(0, count($pieces), 0.0);
        foreach ($levels as $partLevels) {
            $known = array_filter($partLevels, fn (?float $level): bool => $level !== null && $level >= self::QUIETEST_LUFS);
            if (count($known) < 2) {
                continue;
            }
            $middle = self::median(array_values($known));
            foreach ($known as $k => $level) {
                $move = max(-self::MAX_LEVELLING_DB, min(self::MAX_LEVELLING_DB, $middle - $level));
                $moves[$k] = abs($move) < self::SAME_LEVEL_DB ? 0.0 : $move;
            }
        }

        return array_filter($moves) === [] ? [] : $moves;
    }

    /**
     * One gain for the whole take, or one per piece, switching in the middle
     * of the pause between two pieces (in 10 ms steps).
     *
     * @param  array<int, array{0: int, 1: int, 2: int}>  $pieces
     * @param  array<int, float>  $relative
     * @return array<int, string>
     */
    private static function gainFilters(float $gain, array $pieces, array $relative, int $rate): array
    {
        if ($relative === []) {
            return ['volume='.round($gain, 2).'dB'];
        }

        $previous = 10 ** (($gain + $relative[0]) / 20);
        $expression = (string) round($previous, 5);
        for ($k = 1, $n = count($pieces); $k < $n; $k++) {
            $factor = 10 ** (($gain + $relative[$k]) / 20);
            if (abs($factor - $previous) > 1e-6) {
                $switch = ($pieces[$k - 1][1] + $pieces[$k][0]) / 2 / $rate;
                $expression .= sprintf('%+.5f*gte(t,%.4f)', $factor - $previous, $switch);
            }
            $previous = $factor;
        }

        return ['asetnsamples=n='.max(1, intdiv($rate, 100)).':p=0', "volume=volume='".$expression."':eval=frame"];
    }

    /**
     * The relative gain of the piece a block starting at $start (seconds) lies
     * in; null for a block that is not all inside one piece.
     *
     * @param  array<int, array{0: int, 1: int, 2: int}>  $pieces
     * @param  array<int, float>  $relative
     */
    private static function pieceGain(float $start, array $pieces, array $relative, int $rate): ?float
    {
        foreach ($pieces as $k => [$first, $end]) {
            if ($start * $rate >= $first - 1 && ($start + 0.4) * $rate <= $end + 1) {
                return $relative[$k];
            }
        }

        return null;
    }

    /**
     * The entries (by their start in seconds) that lie, all $length seconds of
     * them, within $range (frames).
     *
     * @param  array<int, array{0: float, 1: float}>  $entries
     * @param  array{0: int, 1: int}  $range
     * @return array<int, array{0: float, 1: float}>
     */
    private static function within(array $entries, array $range, int $rate, float $length = 0.4): array
    {
        $from = $range[0] / $rate - 0.001;
        $to = $range[1] / $rate - $length + 0.001;

        return array_values(array_filter($entries, fn (array $e): bool => $e[0] >= $from && $e[0] <= $to));
    }

    /**
     * Integrated loudness of a set of 400 ms blocks (ITU-R BS.1770): blocks
     * under -70 LUFS are left out, then those 10 LU under the mean of the rest.
     *
     * @param  array<int, float>  $levels
     */
    private static function gated(array $levels): ?float
    {
        $levels = array_values(array_filter($levels, fn (float $level): bool => $level > -70.0));
        if ($levels === []) {
            return null;
        }
        $threshold = self::energyMean($levels) - 10.0;
        $kept = array_values(array_filter($levels, fn (float $level): bool => $level > $threshold));

        return $kept === [] ? null : self::energyMean($kept);
    }

    /**
     * @param  array<int, float>  $levels
     */
    private static function energyMean(array $levels): float
    {
        $sum = 0.0;
        foreach ($levels as $level) {
            $sum += 10 ** ($level / 10);
        }

        return 10 * log10($sum / count($levels));
    }

    /**
     * @param  array<int, float>  $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);

        return $n % 2 === 1 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }

    /**
     * Whether this ffmpeg's limiter can make up for its look-ahead (newer
     * builds); older ones shift the take by 5 ms, which only moves silence.
     */
    private static function limiterCompensates(string $ffmpeg): bool
    {
        static $known = [];
        if (! isset($known[$ffmpeg])) {
            try {
                $help = new Process([$ffmpeg, '-hide_banner', '-h', 'filter=alimiter']);
                $help->setTimeout(10);
                $help->run();
                $known[$ffmpeg] = str_contains($help->getOutput().$help->getErrorOutput(), 'latency');
            } catch (\Throwable) {
                $known[$ffmpeg] = false;
            }
        }

        return $known[$ffmpeg];
    }
}
