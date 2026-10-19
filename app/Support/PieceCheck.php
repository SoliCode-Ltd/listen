<?php

namespace App\Support;

/**
 * Looks at each piece a long premium voice is read in before it is joined,
 * so one odd take does not spoil the recording.
 *
 * Every piece is its own take of the same voice with the same direction, so
 * the voice and its tone carry over; but a take can now and then go wrong on
 * its own. It can stop early or skip words, so it is much shorter than its
 * text needs. It can repeat words or stall, so it is much longer, or hold a
 * long dead silence. Or it can be read at another pace or pitch than the
 * pieces around it. Such a piece is taken once more and the better take is
 * kept (GeminiTtsProvider, VoiceCloningService).
 *
 * A piece is measured from its own audio, in PHP, without holding more than a
 * few milliseconds of it at a time as numbers. Its length is set against what
 * its text needs (UsageEstimator, without the margin); its pace (syllable
 * beats a second of speech) and its pitch against the median of the voice's
 * pieces so far, once there are three to go by, or against the other piece
 * when a direction is read in just two. Measured on live (Server
 * deploy join-check, October 2026): within one take the halves of a long piece
 * differ by up to about 13% in pace and 2.5 semitones in pitch, while one
 * long voice in three had a piece further off than that.
 */
final class PieceCheck
{
    /** A take under this share of what its text needs has stopped early or skipped words. */
    public const SHORTEST = 0.55;

    /** A take over this share of what its text needs has repeated words or stalled. */
    public const LONGEST = 1.9;

    /** Seconds of dead silence inside a take that no pause in its text asks for. */
    public const LONGEST_SILENCE = 3.0;

    /** A take whose pace (syllable beats a second) is off the voice's by more than this share. */
    public const PACE_DIFFERENCE = 0.25;

    /** A take whose pitch is off the voice's by more than this many semitones. */
    public const PITCH_SEMITONES = 2.5;

    /**
     * Two pieces of a direction read in only two, further apart in pitch than
     * this many semitones (within one take the halves of a piece differ by
     * about 2.5 at most; one test voice's two pieces were 6.6 apart).
     */
    public const PAIR_SEMITONES = 3.0;

    /** Pieces shorter than this (seconds of text) are too short to judge pace and pitch by. */
    public const SHORTEST_JUDGED = 6.0;

    /** A voice's pace and pitch are only judged against at least this many of its pieces. */
    public const PIECES_TO_JUDGE_BY = 3;

    private const RATE = 8000;

    /**
     * What a take holds: how long it speaks (first sound to last), its longest
     * silence inside that, its pace (syllable beats a second of speech) and
     * its pitch (median fundamental, Hz; null when it could not be told).
     * Null when the audio is not 16-bit PCM.
     *
     * @return array{seconds: float, silence: float, pace: ?float, pitch: ?float}|null
     */
    public static function measure(string $bytes, ?string $mimeType = null): ?array
    {
        $pcm = WavPcm::locate($bytes, $mimeType);
        if ($pcm === null || $pcm['rate'] < self::RATE) {
            return null;
        }
        $channels = $pcm['channels'];
        $frameBytes = 2 * $channels;
        $hop = intdiv($pcm['rate'], 100) * $frameBytes; // 10 ms
        $frames = intdiv($pcm['length'], $hop);
        if ($frames < 10) {
            return null;
        }

        $levels = [];
        for ($f = 0; $f < $frames; $f++) {
            $samples = unpack('s*', substr($bytes, $pcm['offset'] + $f * $hop, $hop)) ?: [];
            $sum = 0.0;
            foreach ($samples as $s) {
                $sum += $s * $s;
            }
            $levels[] = 10 * log10($sum / max(1, count($samples)) + 1e-9);
        }
        $sorted = $levels;
        sort($sorted);
        $loud = $sorted[(int) floor(0.95 * (count($sorted) - 1))];
        $gate = $loud - 35;
        $first = $last = null;
        foreach ($levels as $f => $level) {
            if ($level > $gate) {
                $first ??= $f;
                $last = $f;
            }
        }
        if ($first === null) {
            return ['seconds' => 0.0, 'silence' => 0.0, 'pace' => null, 'pitch' => null];
        }
        $silence = $run = 0;
        for ($f = $first; $f <= $last; $f++) {
            $run = $levels[$f] > $gate ? 0 : $run + 1;
            $silence = max($silence, $run);
        }

        return [
            'seconds' => ($last - $first + 1) / 100,
            'silence' => $silence / 100,
            'pace' => self::pace($levels, $loud),
            'pitch' => self::pitch($bytes, $pcm, $levels, $loud),
        ];
    }

    /**
     * Syllable beats a second of speech: the peaks of the take's loudness,
     * smoothed over 30 ms, that rise 2 dB over the dip before them and come
     * 80 ms or more apart. Null for under 2 s of speech.
     *
     * @param  array<int, float>  $levels  per 10 ms
     */
    private static function pace(array $levels, float $loud): ?float
    {
        $gate = $loud - 25;
        $frames = count($levels);
        $speech = $beats = 0;
        $low = INF;
        $last = -100;
        $smooth = static fn (int $f): float => ($levels[max(0, $f - 1)] + $levels[$f] + $levels[min($frames - 1, $f + 1)]) / 3;
        for ($f = 1; $f < $frames - 1; $f++) {
            $here = $smooth($f);
            if ($levels[$f] > $gate) {
                $speech++;
            }
            $low = min($low, $here);
            if ($here > $gate && $here >= $smooth($f - 1) && $here > $smooth($f + 1) && $here - $low >= 2 && $f - $last >= 8) {
                $beats++;
                $last = $f;
                $low = $here;
            }
        }

        return $speech >= 200 ? $beats / ($speech / 100) : null;
    }

    /**
     * Why a take stands out, if it does: 'short', 'long', 'silence', 'pace'
     * or 'pitch'. $expected is the seconds its text needs; $voice is the
     * median pace and pitch of the voice's pieces, when there are enough.
     *
     * @param  array{seconds: float, silence: float, pace: ?float, pitch: ?float}  $take
     * @param  array{pace?: ?float, pitch?: ?float}  $voice
     * @return array<int, string>
     */
    public static function problems(array $take, float $expected, array $voice = [], bool $pausesAsked = false): array
    {
        $problems = [];
        $share = $expected > 0 ? $take['seconds'] / $expected : null;
        if ($share !== null && $share < self::SHORTEST) {
            $problems[] = 'short';
        }
        if ($share !== null && $share > self::LONGEST) {
            $problems[] = 'long';
        }
        if (! $pausesAsked && $take['silence'] > self::LONGEST_SILENCE) {
            $problems[] = 'silence';
        }
        if ($expected >= self::SHORTEST_JUDGED) {
            if (($take['pace'] ?? null) !== null && ! empty($voice['pace']) && abs($take['pace'] / $voice['pace'] - 1) > self::PACE_DIFFERENCE) {
                $problems[] = 'pace';
            }
            if ($take['pitch'] !== null && ! empty($voice['pitch']) && abs(12 * log($take['pitch'] / $voice['pitch'], 2)) > self::PITCH_SEMITONES) {
                $problems[] = 'pitch';
            }
        }

        return $problems;
    }

    /**
     * Checks a round of takes against what their texts need and against the
     * voice's pieces so far, takes each one that stands out once more
     * ($retake), and keeps the better of the two. Pace and pitch are set
     * against the pieces read with the same direction (the same 'part' of
     * the script): a mood tag's part changes the delivery on purpose.
     * $history carries the paces and pitches from one round to the next;
     * $report counts what was taken again and why.
     *
     * @param  array<int, array{bytes: string, mime_type?: ?string}>  $takes  by piece
     * @param  array<int, array{expected: float, pauses: bool, part?: int}>  $texts  by piece
     * @param  callable(int): array{bytes: string, mime_type?: ?string}  $retake
     * @param  array{paces: array<int, array<int, float>>, pitches: array<int, array<int, float>>}  $history  by part
     * @param  array{pieces: int, retaken: int, kept_retake: int, reasons: array<string, int>}  $report
     * @return array<int, array{bytes: string, mime_type?: ?string}>
     */
    public static function review(array $takes, array $texts, callable $retake, array &$history, array &$report): array
    {
        $measured = [];
        foreach ($takes as $k => $take) {
            $measured[$k] = self::measure((string) $take['bytes'], $take['mime_type'] ?? null);
        }
        // Each direction's voice: its pieces so far and this round's, so an odd one stands out of either.
        $paces = $history['paces'];
        $pitches = $history['pitches'];
        $taken = [];
        foreach ($measured as $k => $m) {
            $part = (int) ($texts[$k]['part'] ?? 0);
            if ($m !== null && ($texts[$k]['expected'] ?? 0) >= self::SHORTEST_JUDGED) {
                if ($m['pace'] !== null) {
                    $paces[$part][] = $m['pace'];
                }
                if ($m['pitch'] !== null) {
                    $pitches[$part][] = $m['pitch'];
                }
            }
        }

        foreach ($takes as $k => $take) {
            $report['pieces']++;
            $m = $measured[$k];
            $part = (int) ($texts[$k]['part'] ?? 0);
            $voice = [
                'pace' => count($paces[$part] ?? []) >= self::PIECES_TO_JUDGE_BY ? self::median($paces[$part]) : null,
                'pitch' => count($pitches[$part] ?? []) >= self::PIECES_TO_JUDGE_BY ? self::median($pitches[$part]) : null,
            ];
            $expected = (float) ($texts[$k]['expected'] ?? 0);
            $problems = $m === null ? [] : self::problems($m, $expected, $voice, (bool) ($texts[$k]['pauses'] ?? false));
            if ($problems !== []) {
                foreach ($problems as $p) {
                    $report['reasons'][$p] = ($report['reasons'][$p] ?? 0) + 1;
                }
                $report['retaken']++;
                try {
                    $again = $retake($k);
                } catch (\Throwable $e) {
                    // The take it has is kept: a retake that fails never fails the voice.
                    report($e);
                    $again = null;
                }
                $second = $again === null ? null : self::measure((string) $again['bytes'], $again['mime_type'] ?? null);
                $secondProblems = $second === null ? $problems : self::problems($second, $expected, $voice, (bool) ($texts[$k]['pauses'] ?? false));
                if ($second !== null && (count($secondProblems) < count($problems)
                    || (count($secondProblems) === count($problems) && self::distance($second, $expected, $voice) < self::distance($m, $expected, $voice)))) {
                    $takes[$k] = $again;
                    $m = $second;
                    $report['kept_retake']++;
                }
                $taken[$k] = true;
            }
            $measured[$k] = $m;
        }

        // A direction read in just two pieces has no third to tell which one is off, but the two
        // must still sound like one reading: the later one is taken again, then the earlier one,
        // and the closer pair is kept.
        $judged = [];
        foreach ($measured as $k => $m) {
            if ($m !== null && ($texts[$k]['expected'] ?? 0) >= self::SHORTEST_JUDGED) {
                $judged[(int) ($texts[$k]['part'] ?? 0)][] = $k;
            }
        }
        foreach ($judged as $part => $keys) {
            $before = max(count($history['paces'][$part] ?? []), count($history['pitches'][$part] ?? []));
            if ($before + count($keys) !== 2) {
                continue;
            }
            $earlier = $before === 1 ? ['pace' => $history['paces'][$part][0] ?? null, 'pitch' => $history['pitches'][$part][0] ?? null] : $measured[$keys[0]];
            $later = $keys[count($keys) - 1];
            $order = $before === 1 ? [$later] : [$later, $keys[0]];
            foreach ($order as $k) {
                $other = $k === $later ? $earlier : $measured[$later];
                $apart = self::apart($measured[$k], $other);
                if ($apart === [] || isset($taken[$k])) {
                    continue;
                }
                foreach ($apart as $p) {
                    $report['reasons'][$p] = ($report['reasons'][$p] ?? 0) + 1;
                }
                $report['retaken']++;
                $taken[$k] = true;
                try {
                    $again = $retake($k);
                } catch (\Throwable $e) {
                    report($e);
                    $again = null;
                }
                $second = $again === null ? null : self::measure((string) $again['bytes'], $again['mime_type'] ?? null);
                $expected = (float) ($texts[$k]['expected'] ?? 0);
                $pauses = (bool) ($texts[$k]['pauses'] ?? false);
                if ($second !== null && count(self::problems($second, $expected, [], $pauses)) <= count(self::problems($measured[$k], $expected, [], $pauses))
                    && self::pairDistance($second, $other) < self::pairDistance($measured[$k], $other)) {
                    $takes[$k] = $again;
                    $measured[$k] = $second;
                    $report['kept_retake']++;
                }
                if ($k === $later && self::apart($measured[$later], $earlier) === []) {
                    break;
                }
            }
        }

        foreach ($measured as $k => $m) {
            if ($m !== null && ($texts[$k]['expected'] ?? 0) >= self::SHORTEST_JUDGED) {
                $part = (int) ($texts[$k]['part'] ?? 0);
                if ($m['pace'] !== null) {
                    $history['paces'][$part][] = $m['pace'];
                }
                if ($m['pitch'] !== null) {
                    $history['pitches'][$part][] = $m['pitch'];
                }
            }
        }

        // How far the kept pieces are from their direction's median so far: the worst one, in semitones and as a share of pace.
        foreach (['pitch_spread' => 'pitches', 'pace_spread' => 'paces'] as $key => $list) {
            $worst = null;
            foreach ($history[$list] as $values) {
                if (count($values) >= 2 && ($median = self::median($values)) > 0) {
                    foreach ($values as $v) {
                        $off = $list === 'pitches' ? abs(12 * log($v / $median, 2)) : abs($v / $median - 1);
                        $worst = max($worst ?? 0.0, $off);
                    }
                }
            }
            $report[$key] = $worst === null ? null : round($worst, 2);
        }

        return $takes;
    }

    /** @return array{pieces: int, retaken: int, kept_retake: int, reasons: array<string, int>, pitch_spread: ?float, pace_spread: ?float} */
    public static function emptyReport(): array
    {
        return ['pieces' => 0, 'retaken' => 0, 'kept_retake' => 0, 'reasons' => [], 'pitch_spread' => null, 'pace_spread' => null];
    }

    /**
     * Whether two takes of one direction sound apart: 'pitch' over PAIR_SEMITONES
     * or 'pace' over PACE_DIFFERENCE from each other.
     *
     * @param  array{pace?: ?float, pitch?: ?float}  $a
     * @param  array{pace?: ?float, pitch?: ?float}  $b
     * @return array<int, string>
     */
    private static function apart(array $a, array $b): array
    {
        $apart = [];
        if (! empty($a['pace']) && ! empty($b['pace']) && abs($a['pace'] / $b['pace'] - 1) > self::PACE_DIFFERENCE) {
            $apart[] = 'pace';
        }
        if (! empty($a['pitch']) && ! empty($b['pitch']) && abs(12 * log($a['pitch'] / $b['pitch'], 2)) > self::PAIR_SEMITONES) {
            $apart[] = 'pitch';
        }

        return $apart;
    }

    /** How far apart two takes sound, in pace and pitch: smaller is closer. */
    private static function pairDistance(array $a, array $b): float
    {
        $distance = 0.0;
        if (! empty($a['pace']) && ! empty($b['pace'])) {
            $distance += abs(log($a['pace'] / $b['pace'])) / log(1 + self::PACE_DIFFERENCE);
        }
        if (! empty($a['pitch']) && ! empty($b['pitch'])) {
            $distance += abs(12 * log($a['pitch'] / $b['pitch'], 2)) / self::PAIR_SEMITONES;
        }

        return $distance;
    }

    /** How far a take is from what its text needs and from the voice: smaller is better. */
    private static function distance(array $take, float $expected, array $voice): float
    {
        $distance = $expected > 0 ? abs(log(max(0.01, $take['seconds'] / $expected))) : 0.0;
        if (($take['pace'] ?? null) !== null && ! empty($voice['pace'])) {
            $distance += abs(log($take['pace'] / $voice['pace']));
        }
        if ($take['pitch'] !== null && ! empty($voice['pitch'])) {
            $distance += abs(log($take['pitch'] / $voice['pitch'], 2));
        }

        return $distance + $take['silence'] / 10;
    }

    /**
     * The median fundamental of the take's louder speech: normalised
     * autocorrelation over 50 ms windows, at 8 kHz.
     *
     * @param  array{offset: int, length: int, rate: int, channels: int}  $pcm
     * @param  array<int, float>  $levels  per 10 ms
     */
    private static function pitch(string $bytes, array $pcm, array $levels, float $loud): ?float
    {
        $channels = $pcm['channels'];
        $step = intdiv($pcm['rate'], self::RATE); // source samples per 8 kHz sample
        $window = 400; // 50 ms at 8 kHz
        $span = $window * $step * 2 * $channels;
        $candidates = [];
        foreach ($levels as $f => $level) {
            if ($f % 3 === 0 && $level > $loud - 15) {
                $candidates[] = $f;
            }
        }
        $every = max(1, intdiv(count($candidates), 60));
        $found = [];
        for ($c = 0; $c < count($candidates); $c += $every) {
            $start = $pcm['offset'] + $candidates[$c] * intdiv($pcm['rate'], 100) * 2 * $channels;
            if ($start + $span > $pcm['offset'] + $pcm['length']) {
                break;
            }
            $raw = unpack('s*', substr($bytes, $start, $span)) ?: [];
            // First channel, averaged down to 8 kHz.
            $x = [];
            $stride = $step * $channels;
            for ($i = 1, $n = count($raw); $i + $stride - 1 <= $n && count($x) < $window; $i += $stride) {
                $sum = 0;
                for ($j = 0; $j < $step; $j++) {
                    $sum += $raw[$i + $j * $channels];
                }
                $x[] = $sum / $step;
            }
            if (count($x) < $window) {
                continue;
            }
            $mean = array_sum($x) / $window;
            foreach ($x as $i => $v) {
                $x[$i] = $v - $mean;
            }
            $scores = [];
            $best = 0.0;
            $bestLag = 0;
            for ($lag = 16; $lag <= 100; $lag++) { // 500 Hz down to 80 Hz
                $xy = $xx = $yy = 0.0;
                for ($i = 0, $n = $window - $lag; $i < $n; $i++) {
                    $xy += $x[$i] * $x[$i + $lag];
                    $xx += $x[$i] * $x[$i];
                    $yy += $x[$i + $lag] * $x[$i + $lag];
                }
                $r = $xx > 0 && $yy > 0 ? $xy / sqrt($xx * $yy) : 0.0;
                $scores[$lag] = $r;
                if ($r > $best) {
                    $best = $r;
                    $bestLag = $lag;
                }
            }
            if ($best < 0.7) {
                continue;
            }
            // The shortest period nearly as strong: no octave below the voice.
            foreach ($scores as $lag => $r) {
                if ($lag < $bestLag && $r >= 0.9 * $best && ($scores[$lag - 1] ?? 0) <= $r && ($scores[$lag + 1] ?? 0) <= $r) {
                    $bestLag = $lag;
                    break;
                }
            }
            $a = $scores[$bestLag - 1] ?? $scores[$bestLag];
            $b = $scores[$bestLag];
            $d = $scores[$bestLag + 1] ?? $scores[$bestLag];
            $shift = ($a - 2 * $b + $d) != 0 ? 0.5 * ($a - $d) / ($a - 2 * $b + $d) : 0.0;
            $found[] = self::RATE / ($bestLag + max(-0.5, min(0.5, $shift)));
        }

        return count($found) >= 8 ? self::median($found) : null;
    }

    /** @param  array<int, float>  $values */
    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);

        return $n % 2 === 1 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }
}
