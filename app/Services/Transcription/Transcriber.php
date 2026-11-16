<?php

namespace App\Services\Transcription;

use App\Services\Dubbing\SpeechNotWrittenOut;
use App\Services\Dubbing\VideoDubber;
use App\Services\Tts\GeminiKeyManager;
use App\Support\ArabicDialects;
use App\Support\AudioFormats;
use App\Support\EnglishAccents;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Speech to text: what is said in a recording, word for word,
 * in the language and the words the speaker uses, as paragraphs with the
 * speaker of each, and every sentence placed on the recording's own clock.
 *
 * It asks the same text model, with the same keys, as the dubbing's write-out
 * (VideoDubber::MODELS, GeminiKeyManager): one request per part of the
 * recording. A recording longer than MAX_PART seconds is cut into parts on its
 * pauses (chunkPlan()), each heard with a little of its neighbours around it
 * (OVERLAP) so no word at a cut is lost, and the parts are put back together
 * without the words heard twice (withoutRepeat()).
 *
 * The model writes only the words and who says them, never times: asked for
 * times, it drifts over a long recording (measured on staging, November
 * 2026), and it costs more. Each sentence is placed on the speech found in
 * the sound itself instead (placeOnSpeech()): spread over the stretches of
 * speech by its length, each end drawn to the nearest pause, so the times
 * cannot drift. That is what the player follows, and the subtitles (SRT, VTT).
 *
 * What makes the words accurate, each measured on staging (transcribe:measure):
 *  - Arabic is written in the speaker's own dialect, as said: never rewritten
 *    into Modern Standard Arabic, with the dialect words of every region kept
 *    in their own spelling (dialectGuide()); where a dialect or accent is
 *    named, the site's own notes on it are given too (ArabicDialects::markers()).
 *  - The model is held to the answer's shape (schema()), and a part that
 *    comes back unreadable, or with far fewer words than its speech holds,
 *    is asked for again (hear()).
 *  - With the language detected, the first part's language is given for the
 *    rest (detection did worse on Egyptian and Iraqi than the language given).
 *  - Speaker letters stay the same from part to part: each part is told the
 *    voices heard so far.
 *  - With 'review', each part is heard a second time with its draft, to
 *    correct it; the correction is kept only when it drops nothing.
 *
 * A well-known text the speaker recites (a verse, a poem, a song) the model
 * may refuse to copy out (finish RECITATION, see SpeechNotWrittenOut): the
 * part is then asked for once more with only the first words of each
 * quotation written, and the result says so ('recited').
 */
final class Transcriber
{
    /** The longest part heard in one request, in seconds, and the earliest pause it is cut on. */
    public const MAX_PART = 300.0;

    private const MIN_PART = 200.0;

    /** Seconds of the neighbouring parts heard around each part. */
    public const OVERLAP = 2.0;

    /** A pause at least this long, this quiet (dB), is a place to cut, and where a sentence may end. */
    private const PAUSE_SECONDS = 0.3;

    private const PAUSE_DB = -35;

    /** How far (seconds of speech) a sentence's end worked out from its length may be from the pause it is matched to. */
    public const MATCH_SECONDS = 6.0;

    /** A sentence longer than this many letters is cut at a comma (a subtitle line, a click to play). */
    private const LONG_SENTENCE = 160;

    public function __construct(private GeminiKeyManager $keys) {}

    /**
     * The whole recording at once (for the measure, and short files): prepare(),
     * every step(), then result().
     *
     * $o: language ('auto' or a code such as 'ar', 'fr'), dialect (an
     * ArabicDialects or EnglishAccents code, optional), review (bool),
     * thinking ('low', 'high', 'minimal', ''), progress (fn (int $done, int $parts)).
     *
     * @param  array<string, mixed>  $o
     * @return array{text: string, paragraphs: array<int, array{speaker: string, text: string, start: float, end: float}>, segments: array<int, array{start: float, end: float, speaker: string, text: string, paragraph: int}>, speakers: int, voices: array<int, array{id: string, voice: string}>, language: string, seconds: float, parts: int, requests: int, recited: bool, usage: array<string, int>, model: string, finish: array<int, string>, notes: array<int, string>}
     */
    public function transcribe(string $file, array $o = []): array
    {
        $tmp = sys_get_temp_dir().'/listen-transcribe-'.bin2hex(random_bytes(6));
        @mkdir($tmp, 0700, true);
        $progress = $o['progress'] ?? static function (): void {};
        try {
            $clean = $tmp.'/clean.mp3';
            $state = $this->prepare($file, $clean, $o);
            while ($this->step($clean, $state)) {
                $progress($state['n'], count($state['plan']));
            }
            $progress($state['n'], count($state['plan']));
        } finally {
            foreach ((array) glob($tmp.'/*') as $f) {
                @unlink((string) $f);
            }
            @rmdir($tmp);
        }

        return self::result($state);
    }

    /**
     * The recording made ready to hear: written to $clean (16 kHz mono MP3,
     * what the model hears and the member plays back), measured, its pauses
     * found and its parts planned. The state returned is plain data (it can
     * be saved between steps, so a long file is heard one part per queue job).
     *
     * @param  array<string, mixed>  $o  language, dialect, review, thinking
     * @return array<string, mixed>
     */
    public function prepare(string $file, string $clean, array $o = []): array
    {
        $ffmpeg = self::ffmpeg();
        $this->normalise($ffmpeg, $file, $clean);
        $seconds = $this->duration($ffmpeg, $clean);
        $pauses = $this->pauses($ffmpeg, $clean);
        $language = strtolower(trim((string) ($o['language'] ?? 'auto'))) ?: 'auto';

        return [
            'ask' => ['language' => $language, 'dialect' => trim((string) ($o['dialect'] ?? '')), 'review' => (bool) ($o['review'] ?? false), 'thinking' => (string) ($o['thinking'] ?? 'low')],
            'asked' => $language,
            'seconds' => round($seconds, 2),
            'pauses' => $pauses,
            'plan' => self::chunkPlan($pauses, $seconds),
            'n' => 0,
            'run' => ['usage' => ['input_tokens' => 0, 'audio_tokens' => 0, 'output_tokens' => 0, 'thinking_tokens' => 0],
                'finish' => [], 'model' => '', 'requests' => 0, 'recited' => false, 'detected' => [], 'notes' => [], 'voices' => []],
            'paragraphs' => [],
            'segments' => [],
            'before' => '',
        ];
    }

    /**
     * The next part heard and its sentences placed on the recording's clock.
     * Returns whether parts are left.
     *
     * @param  array<string, mixed>  $state  from prepare(), changed in place
     */
    public function step(string $clean, array &$state): bool
    {
        $plan = $state['plan'];
        $n = (int) $state['n'];
        if ($n >= count($plan)) {
            return false;
        }
        $part = $plan[$n];
        $tmp = sys_get_temp_dir().'/listen-transcribe-'.bin2hex(random_bytes(6));
        @mkdir($tmp, 0700, true);
        try {
            $heard = $this->hearStretch(self::ffmpeg(), $clean, $tmp, $part, $state['pauses'], $state['ask'] + ['part' => count($plan) > 1], $state['run'], 'part '.($n + 1));
        } finally {
            foreach ((array) glob($tmp.'/*') as $f) {
                @unlink((string) $f);
            }
            @rmdir($tmp);
        }
        // Detected once, then given: the rest of the recording is heard in the language found.
        if ($state['ask']['language'] === 'auto' && $heard['language'] !== '' && self::languageName($heard['language']) !== '') {
            $state['ask']['language'] = $heard['language'];
        }
        $mine = self::withoutRepeatedStart((string) $state['before'], $heard['paragraphs']);
        $from = $n === 0 ? 0.0 : (float) $part['keep_from'];
        $to = $n === count($plan) - 1 ? (float) $state['seconds'] : min((float) $state['seconds'], (float) $part['keep_to'] + self::OVERLAP);
        $placed = self::placeOnSpeech(self::sentences($mine, count($state['paragraphs'])), self::speechIn($state['pauses'], $from, $to), $from, $to);
        // In order: a part never starts before the one before it ended.
        $floor = $state['segments'] !== [] ? (float) end($state['segments'])['end'] : 0.0;
        foreach ($placed as $s) {
            $s['start'] = max($s['start'], $floor);
            $s['end'] = max($s['end'], $s['start']);
            $floor = $s['end'];
            $state['segments'][] = $s;
        }
        array_push($state['paragraphs'], ...array_map(fn ($p) => ['speaker' => $p['speaker'], 'text' => $p['text']], $mine));
        $state['before'] = mb_substr(trim($state['before'].' '.implode(' ', array_column($mine, 'text'))), -2000);
        $state['n'] = $n + 1;

        return $state['n'] < count($plan);
    }

    /**
     * The finished transcript from the state after its last step.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed> as transcribe() returns
     */
    public static function result(array $state): array
    {
        $paragraphs = $state['paragraphs'];
        $segments = $state['segments'];
        foreach ($paragraphs as $i => $p) {
            $own = array_values(array_filter($segments, fn ($s) => $s['paragraph'] === $i));
            $paragraphs[$i] += ['start' => $own !== [] ? $own[0]['start'] : 0.0, 'end' => $own !== [] ? end($own)['end'] : 0.0];
        }
        $speakers = count(array_unique(array_column($paragraphs, 'speaker')));
        $detected = (array) $state['run']['detected'];
        arsort($detected);

        return [
            'text' => self::textOf($paragraphs, $speakers > 1),
            'paragraphs' => $paragraphs,
            'segments' => $segments,
            'speakers' => $speakers,
            'voices' => array_values((array) $state['run']['voices']),
            'language' => $state['asked'] !== 'auto' ? (string) $state['asked'] : (string) (array_key_first($detected) ?? ''),
            'seconds' => (float) $state['seconds'],
            'parts' => count($state['plan']),
            'requests' => (int) $state['run']['requests'],
            'recited' => (bool) $state['run']['recited'],
            'usage' => $state['run']['usage'],
            'model' => (string) $state['run']['model'],
            'finish' => $state['run']['finish'],
            'notes' => $state['run']['notes'],
        ];
    }

    /** The length of an audio or video file in seconds, read quickly from its header (0 when it cannot be read). */
    public static function lengthOf(string $file): float
    {
        $p = new Process([self::ffmpeg(), '-hide_banner', '-i', $file]);
        $p->setTimeout(60)->run();
        if (preg_match('/Duration: (\d+):(\d+):(\d+(?:\.\d+)?)/', $p->getErrorOutput(), $m) === 1) {
            return (int) $m[1] * 3600 + (int) $m[2] * 60 + (float) $m[3];
        }

        return 0.0;
    }

    /** Whether the file has a sound track ffmpeg can read. */
    public static function hasAudio(string $file): bool
    {
        $p = new Process([self::ffmpeg(), '-hide_banner', '-i', $file]);
        $p->setTimeout(60)->run();

        return preg_match('/Stream #\S+.*: Audio:/', $p->getErrorOutput()) === 1;
    }

    private static function ffmpeg(): string
    {
        $ffmpeg = AudioFormats::ffmpegPath();
        if (! $ffmpeg) {
            throw new \RuntimeException('ffmpeg is not available.');
        }

        return $ffmpeg;
    }

    /**
     * One part of the recording cut out and heard, with what it used added to $run.
     *
     * @param  array{from: float, to: float}  $part
     * @param  array<int, array{0: float, 1: float}>  $pauses
     * @param  array<string, mixed>  $ask
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function hearStretch(string $ffmpeg, string $clean, string $tmp, array $part, array $pauses, array $ask, array &$run, string $label): array
    {
        [$from, $to] = [(float) $part['from'], (float) $part['to']];
        $audio = $tmp.'/part-'.$run['requests'].'-'.(int) ($from * 100).'.mp3';
        $this->cut($ffmpeg, $clean, $from, $to - $from, $audio);
        $heard = $this->hear((string) file_get_contents($audio), self::speechSeconds($pauses, $from, $to), $ask + ['known' => array_values($run['voices'])]);
        @unlink($audio);
        foreach ($heard['usage'] as $key => $tokens) {
            $run['usage'][$key] = ($run['usage'][$key] ?? 0) + $tokens;
        }
        $run['finish'] = array_merge($run['finish'], $heard['finish']);
        $run['model'] = $heard['model'];
        $run['requests'] += $heard['requests'];
        $run['recited'] = $run['recited'] || $heard['recited'];
        if ($heard['language'] !== '') {
            $run['detected'][$heard['language']] = ($run['detected'][$heard['language']] ?? 0) + ($to - $from);
        }
        foreach ($heard['voices'] as $voice) {
            $run['voices'][$voice['id']] ??= $voice;
        }
        foreach ($heard['notes'] as $note) {
            $run['notes'][] = $label.': '.$note;
        }

        return $heard;
    }

    /**
     * Where to cut a recording of $seconds into parts of at most MAX_PART:
     * each part ends in the middle of the longest pause late in it (from
     * MIN_PART on), or at MAX_PART when there is none; the audio sent for a
     * part ('from'-'to') reaches OVERLAP into its neighbours, while the words
     * it gives are kept only between 'keep_from' and 'keep_to'. 'clean' says
     * the cut before it fell in a pause (no word runs across it).
     *
     * @param  array<int, array{0: float, 1: float}>  $pauses  start and end of each pause
     * @return array<int, array{from: float, to: float, keep_from: float, keep_to: float, clean: bool}>
     */
    public static function chunkPlan(array $pauses, float $seconds, float $max = self::MAX_PART, float $min = self::MIN_PART): array
    {
        $cuts = [];
        $at = 0.0;
        while ($seconds - $at > $max) {
            $best = null;
            foreach ($pauses as [$start, $end]) {
                $middle = ($start + $end) / 2;
                if ($middle > $at + $min && $middle < $at + $max && ($best === null || $end - $start > $best[1])) {
                    $best = [$middle, $end - $start];
                }
            }
            $cut = $best !== null ? $best[0] : $at + $max;
            $cuts[] = [round($cut, 2), $best !== null];
            $at = $cut;
        }

        $plan = [];
        $edges = array_merge([[0.0, true]], $cuts, [[round($seconds, 2), true]]);
        for ($i = 0; $i < count($edges) - 1; $i++) {
            [$keepFrom, $cleanBefore] = $edges[$i];
            [$keepTo] = $edges[$i + 1];
            $plan[] = [
                'from' => max(0.0, round($keepFrom - ($i > 0 ? self::OVERLAP : 0), 2)),
                'to' => min(round($seconds, 2), round($keepTo + ($i < count($edges) - 2 ? self::OVERLAP : 0), 2)),
                'keep_from' => $keepFrom,
                'keep_to' => $keepTo,
                'clean' => (bool) $cleanBefore,
            ];
        }

        return $plan;
    }

    /** $next without the words at its start that $before already ends with (up to 40 words). */
    public static function withoutRepeat(string $before, string $next): string
    {
        $norm = fn (string $w): string => mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $w));
        $tail = array_values(array_filter(preg_split('/\s+/u', $before) ?: [], 'strlen'));
        $head = array_values(array_filter(preg_split('/\s+/u', $next) ?: [], 'strlen'));
        $tail = array_slice($tail, -40);
        for ($k = min(40, count($tail), count($head)); $k >= 2; $k--) {
            $a = array_map($norm, array_slice($tail, -$k));
            $b = array_map($norm, array_slice($head, 0, $k));
            if ($a === $b) {
                return implode(' ', array_slice($head, $k));
            }
        }

        return $next;
    }

    /**
     * A part's paragraphs without the words at their start that the parts
     * before already end with (both heard the overlap); a paragraph left empty goes.
     *
     * @param  array<int, array{speaker: string, text: string}>  $paragraphs
     * @return array<int, array{speaker: string, text: string}>
     */
    public static function withoutRepeatedStart(string $before, array $paragraphs): array
    {
        if ($before === '' || $paragraphs === []) {
            return $paragraphs;
        }
        $paragraphs[0]['text'] = self::withoutRepeat($before, $paragraphs[0]['text']);

        return array_values(array_filter($paragraphs, fn ($p) => trim($p['text']) !== ''));
    }

    /**
     * The paragraphs cut into sentences, each with its speaker and paragraph
     * (numbered from $first); a very long sentence is cut at a comma.
     *
     * @param  array<int, array{speaker: string, text: string}>  $paragraphs
     * @return array<int, array{speaker: string, text: string, paragraph: int}>
     */
    public static function sentences(array $paragraphs, int $first = 0): array
    {
        $out = [];
        foreach (array_values($paragraphs) as $k => $p) {
            foreach (preg_split('/(?<=[.!?؟…])\s+/u', trim($p['text']), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $sentence) {
                foreach (self::shorter($sentence) as $piece) {
                    $out[] = ['speaker' => $p['speaker'], 'text' => $piece, 'paragraph' => $first + $k];
                }
            }
        }

        return $out;
    }

    /** @return array<int, string> a long sentence in pieces at its commas, none longer than LONG_SENTENCE letters where a comma allows */
    private static function shorter(string $sentence): array
    {
        if (mb_strlen($sentence) <= self::LONG_SENTENCE) {
            return [$sentence];
        }
        $pieces = [];
        $current = '';
        foreach (preg_split('/(?<=[,،؛;:])\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY) ?: [$sentence] as $bit) {
            if ($current !== '' && mb_strlen($current.' '.$bit) > self::LONG_SENTENCE) {
                $pieces[] = $current;
                $current = $bit;
            } else {
                $current = trim($current.' '.$bit);
            }
        }
        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * The stretches of speech between $from and $to: the recording less its pauses.
     *
     * @param  array<int, array{0: float, 1: float}>  $pauses
     * @return array<int, array{0: float, 1: float}>
     */
    public static function speechIn(array $pauses, float $from, float $to): array
    {
        usort($pauses, fn ($a, $b) => $a[0] <=> $b[0]);
        $speech = [];
        $at = $from;
        foreach ($pauses as [$start, $end]) {
            if ($end <= $from || $start >= $to) {
                continue;
            }
            if ($start > $at + 0.05) {
                $speech[] = [round($at, 2), round(min($start, $to), 2)];
            }
            $at = max($at, $end);
        }
        if ($at < $to - 0.05) {
            $speech[] = [round($at, 2), round($to, 2)];
        }

        return $speech !== [] ? $speech : [[round($from, 2), round($to, 2)]];
    }

    /**
     * Each sentence placed on the speech. Where each sentence should end is
     * first worked out from the sentences' lengths (the speech shared out by
     * their letters); those ends are then matched, all together and in order,
     * to the pauses in the speech (an alignment that keeps each end as close
     * as it can to a pause, MATCH_SECONDS at most), since a sentence ends in
     * a pause; an end no pause fits is shared out between its neighbours. A
     * sentence starts where speech starts again after the pause before it.
     * Every end on a pause puts the rest back in step, so the times cannot drift.
     *
     * @param  array<int, array<string, mixed>>  $sentences  with 'text'
     * @param  array<int, array{0: float, 1: float}>  $speech
     * @return array<int, array<string, mixed>> the sentences with 'start' and 'end'
     */
    public static function placeOnSpeech(array $sentences, array $speech, float $from, float $to): array
    {
        $sentences = array_values($sentences);
        $n = count($sentences);
        if ($n === 0) {
            return [];
        }
        $speech = $speech !== [] ? $speech : [[$from, $to]];
        $letters = array_map(fn ($s) => max(1, (int) preg_match_all('/[\p{L}\p{N}]/u', (string) $s['text'])), $sentences);
        $all = array_sum($letters);
        $total = array_sum(array_map(fn ($p) => $p[1] - $p[0], $speech));

        // Seconds of speech at each pause (the end of every stretch but the last), and where each sentence should end.
        $pauses = [];
        $u = 0.0;
        foreach (array_slice($speech, 0, -1) as [$a, $b]) {
            $u += $b - $a;
            $pauses[] = $u;
        }
        $targets = [];
        $sum = 0;
        for ($k = 0; $k < $n - 1; $k++) {
            $sum += $letters[$k];
            $targets[] = $total * $sum / $all;
        }

        // The ends matched to the pauses, in order, as closely as they can be.
        $m = count($pauses);
        $miss = self::MATCH_SECONDS ** 2;
        $cost = array_fill(0, $n, array_fill(0, $m + 1, INF));
        $way = [];
        for ($j = 0; $j <= $m; $j++) {
            $cost[0][$j] = 0.0;
        }
        for ($k = 1; $k < $n; $k++) {
            $cost[$k][0] = $cost[$k - 1][0] + $miss;
            $way[$k][0] = 'miss';
            for ($j = 1; $j <= $m; $j++) {
                $gap = abs($targets[$k - 1] - $pauses[$j - 1]);
                $options = [
                    'skip' => $cost[$k][$j - 1],
                    'miss' => $cost[$k - 1][$j] + $miss,
                    'match' => $gap <= self::MATCH_SECONDS ? $cost[$k - 1][$j - 1] + $gap ** 2 : INF,
                ];
                $best = array_keys($options, min($options))[0];
                $cost[$k][$j] = $options[$best];
                $way[$k][$j] = $best;
            }
        }
        $ends = array_fill(0, $n - 1, null);
        for ($k = $n - 1, $j = $m; $k > 0;) {
            $step = $way[$k][$j] ?? 'miss';
            if ($step === 'skip') {
                $j--;
            } elseif ($step === 'match') {
                $ends[$k - 1] = $pauses[$j - 1];
                $k--;
                $j--;
            } else {
                $k--;
            }
        }
        // An end no pause fits: shared out by letters between the ends either side of it.
        $bounds = array_merge([0.0], $ends, [$total]);
        $cumulative = [0];
        foreach ($letters as $l) {
            $cumulative[] = end($cumulative) + $l;
        }
        for ($k = 1; $k < $n; $k++) {
            if ($bounds[$k] !== null) {
                continue;
            }
            $prev = $k - 1;
            $next = $k + 1;
            while ($bounds[$next] === null) {
                $next++;
            }
            $bounds[$k] = $bounds[$prev] + ($bounds[$next] - $bounds[$prev]) * ($cumulative[$k] - $cumulative[$prev]) / max(1, $cumulative[$next] - $cumulative[$prev]);
        }

        // Seconds of speech to the recording's clock: a start goes to where speech starts again, an end to where it stops.
        $clock = function (float $u, bool $start) use ($speech): float {
            foreach ($speech as [$a, $b]) {
                if ($start ? $u < $b - $a - 0.005 : $u <= $b - $a + 0.005) {
                    return $a + max(0.0, $u);
                }
                $u -= $b - $a;
            }

            return (float) end($speech)[1];
        };
        $placed = [];
        foreach ($sentences as $k => $s) {
            $start = $clock((float) $bounds[$k], true);
            $end = $clock((float) $bounds[$k + 1], false);
            $placed[] = $s + ['start' => round($start, 2), 'end' => round(max($start, $end), 2)];
        }

        return $placed;
    }

    /** The paragraphs as text: a blank line between them, the speaker's letter before each where there are several speakers. */
    public static function textOf(array $paragraphs, bool $speakers): string
    {
        return trim(implode("\n\n", array_map(fn ($p) => ($speakers ? $p['speaker'].': ' : '').$p['text'], $paragraphs)));
    }

    /**
     * One part heard by the model. It is asked once more when its answer
     * cannot be read or it would not answer (RECITATION: then with only the
     * first words of each quotation written), and when it wrote far fewer
     * words than the part's speech holds (it now and then writes only the
     * opening of a part): the fuller answer is kept. With 'review' the part
     * is heard once more with that answer, to correct it. $speech: seconds of
     * the part that are not pauses.
     *
     * @param  array<string, mixed>  $ask  language, dialect, part, review, thinking, known
     * @return array{paragraphs: array<int, array{speaker: string, text: string}>, language: string, voices: array<int, array{id: string, voice: string}>, usage: array<string, int>, model: string, finish: array<int, string>, requests: int, recited: bool, notes: array<int, string>}
     */
    private function hear(string $audio, float $speech, array $ask): array
    {
        $usage = [];
        $finish = [];
        $notes = [];
        $requests = 0;
        $best = null;
        $careful = false;
        $whole = false;
        $sound = ['inline_data' => ['mime_type' => 'audio/mpeg', 'data' => base64_encode($audio)]];
        $take = function (array $prompt) use ($sound, $ask, &$usage, &$finish, &$requests): array {
            $answer = $this->askModel([$sound, ['text' => self::prompt($prompt + $ask)]], (string) $ask['thinking'], self::schema());
            $requests++;
            $finish[] = $answer['finish'];
            foreach ($answer['usage'] as $key => $tokens) {
                $usage[$key] = ($usage[$key] ?? 0) + $tokens;
            }

            return $answer;
        };
        $words = fn (array $heard): int => self::wordCount(implode(' ', array_column($heard['paragraphs'], 'text')));
        for ($try = 0; $try < 2; $try++) {
            $answer = $take(['careful' => $careful, 'speech' => $whole ? $speech : 0.0]);
            $heard = $answer['data'] !== null ? self::read($answer['data']) : null;
            if ($heard !== null) {
                $heard['recited'] = $careful;
                if ($best === null || $words($heard) > $words($best)) {
                    $best = $heard;
                }
                if (! self::tooShort($words($heard), $speech) || $try === 1) {
                    break;
                }
                $notes[] = sprintf('only %d words for %.0f s of speech (%s): asked again', $words($heard), $speech, $answer['finish'] ?: 'no finish reason');
                $whole = true;

                continue;
            }
            $notes[] = 'no answer to read ('.($answer['finish'] ?: 'no finish reason').'): '.mb_substr(preg_replace('/\s+/u', ' ', $answer['raw']) ?? '', 0, 160);
            Log::warning('Speech to text: the text model gave no answer to read.', ['finish' => $answer['finish'], 'model' => $answer['model'], 'careful' => $careful]);
            $careful = $careful || $answer['finish'] === 'RECITATION';
        }
        if ($best === null) {
            throw new SpeechNotWrittenOut($finish);
        }
        if (self::tooShort($words($best), $speech)) {
            $notes[] = sprintf('still only %d words for %.0f s of speech', $words($best), $speech);
        }

        // A second listening with the draft: kept only when it drops nothing.
        if (($ask['review'] ?? false) && $words($best) > 0) {
            $draft = json_encode(['language' => $best['language'], 'speakers' => $best['voices'], 'paragraphs' => $best['paragraphs']], JSON_UNESCAPED_UNICODE);
            $answer = $take(['careful' => $best['recited'], 'draft' => $draft]);
            $checked = $answer['data'] !== null ? self::read($answer['data']) : null;
            if ($checked !== null && $words($checked) >= 0.9 * $words($best)) {
                $notes[] = sprintf('checked again: %d words, was %d', $words($checked), $words($best));
                $best = $checked + ['recited' => $best['recited']];
            } else {
                $notes[] = 'checked again: the check dropped words, the first answer kept';
            }
        }

        return $best + ['usage' => $usage, 'model' => $answer['model'], 'finish' => $finish, 'requests' => $requests, 'notes' => $notes];
    }

    /** Far fewer words than the speech holds: under 0.6 a second of speech (people say 2 to 3), over 20 seconds of it. */
    public static function tooShort(int $words, float $speech): bool
    {
        return $speech >= 20 && $words < 0.6 * $speech;
    }

    /** Words in a text; in a script written without spaces (Chinese, Japanese, Thai), every two letters count as one. */
    public static function wordCount(string $text): int
    {
        $dense = preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}]/u', $text);
        $words = preg_match_all("/[\\p{L}\\p{N}\\p{M}']+/u", (string) preg_replace('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}]/u', ' ', $text));

        return (int) $words + intdiv((int) $dense, 2);
    }

    /**
     * Seconds between $from and $to that are not pauses.
     *
     * @param  array<int, array{0: float, 1: float}>  $pauses
     */
    public static function speechSeconds(array $pauses, float $from, float $to): float
    {
        $quiet = 0.0;
        foreach ($pauses as [$start, $end]) {
            $quiet += max(0.0, min($end, $to) - max($start, $from));
        }

        return max(0.0, $to - $from - $quiet);
    }

    /**
     * The answer's shape, so the model keeps to it: the language, the voices
     * (described, so later parts keep their letters), and the paragraphs with
     * the speaker of each.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $voice = ['type' => 'OBJECT', 'properties' => ['id' => ['type' => 'STRING'], 'voice' => ['type' => 'STRING']], 'required' => ['id', 'voice'], 'propertyOrdering' => ['id', 'voice']];
        $paragraph = ['type' => 'OBJECT', 'properties' => ['speaker' => ['type' => 'STRING'], 'text' => ['type' => 'STRING']], 'required' => ['speaker', 'text'], 'propertyOrdering' => ['speaker', 'text']];

        return ['type' => 'OBJECT', 'properties' => ['language' => ['type' => 'STRING'], 'speakers' => ['type' => 'ARRAY', 'items' => $voice], 'paragraphs' => ['type' => 'ARRAY', 'items' => $paragraph]],
            'required' => ['language', 'speakers', 'paragraphs'], 'propertyOrdering' => ['language', 'speakers', 'paragraphs']];
    }

    /**
     * What the model is asked. $c: language, dialect, part (a part of a longer
     * recording), careful (a well-known text recited is written only by its
     * first words: the model will not copy one out in full), speech (seconds
     * of speech, said when an answer came back too short), known (voices heard
     * in earlier parts), draft (an answer to check).
     *
     * @param  array<string, mixed>  $c
     */
    public static function prompt(array $c): string
    {
        $language = (string) ($c['language'] ?? 'auto');
        $named = $language === 'auto' ? '' : self::languageName($language);
        $variety = self::varietyNote((string) ($c['dialect'] ?? ''));
        $arabic = in_array($language, ['ar', 'auto'], true);
        $draft = (string) ($c['draft'] ?? '');
        $known = array_filter((array) ($c['known'] ?? []), fn ($v) => is_array($v) && ($v['id'] ?? '') !== '');

        return "You are a professional transcriber. Write out exactly what is said in this audio, every word, as it is spoken.\n"
            .(($c['part'] ?? false) ? "This audio is one part cut out of a longer recording: it may start or end in the middle of a word. Write every word from its first second to its last.\n" : '')
            .(($c['speech'] ?? 0) > 0 ? sprintf("This audio holds about %d seconds of speech: write all of it, not only its opening.\n", (int) round((float) $c['speech'])) : '')
            .($named !== ''
                ? "The audio is in {$named}. Write it in {$named}, in that language's own script. Words the speaker says in another language are written in that language.\n"
                : "Find the language spoken and write it in that language's own script.\n")
            ."The recording may move from one language to another: write every part, each in its own language and script; never leave a part out because it is in another language.\n"
            .$variety
            .'Write what you hear, not what you expect: never translate, correct, polish, sum up or leave out anything, and never add a word the speaker did not say. '
            ."Keep the speaker's own words, dialect, slang and grammar.\n"
            .($arabic ? self::dialectGuide() : '')
            .'Leave out pure hesitation sounds (um, uh, ehh, اممم) and false starts of one syllable; keep every real word, also repeated ones. '
            .'Write numbers as digits when said as numbers, and a phone number said digit by digit as one number (01012345678). Add normal punctuation, with a full stop or question mark at the end of every sentence. '
            .'Where a word cannot be made out, write [inaudible]. Do not describe music or noises, and do not write any times.'."\n"
            .(($c['careful'] ?? false)
                ? "Where the speaker recites a well-known text (a verse of the Quran, a prayer, a poem, song lyrics), write only its first three words followed by \"…\".\n"
                : "Quotations (verses of the Quran, prayers, poems, songs) are written as heard too.\n")
            .'Tell the speakers apart by their voices: give each a letter (A, B, ...) in the order they first speak, and in "speakers" describe each voice in a few words '
            .'(gender, pitch, manner) so it can be told apart later. One person keeps one letter all through; give a new letter only when a clearly different voice speaks. '
            .'One person reading or talking alone is A all through.'
            .($known !== [] ? ' Voices heard earlier in this recording: '.implode('; ', array_map(fn ($v) => $v['id'].': '.$v['voice'], $known))
                .'. Give the same letter to the same voice here; a voice not among them gets the next letter.' : '')
            ."\nStart a new paragraph whenever the speaker changes, and within one speaker where the topic changes.\n"
            .($draft !== '' ? "Here is a first transcript of this audio:\n{$draft}\n"
                .'Listen to the whole audio again, from start to end, and return it corrected in the same JSON: fix every misheard word, add anything missing, '
                .'remove anything that was not said, put back any dialect word that was written in its Standard form, and fix the speaker letters where they are wrong. '
                .'Keep everything that is right as it is.'."\n" : '')
            .'Return only JSON: {"language": "<ISO 639-1 code of the main language spoken>", "speakers": [{"id": "A", "voice": "..."}], '
            .'"paragraphs": [{"speaker": "A", "text": "..."}]}. If nothing is spoken, return {"language": "", "speakers": [], "paragraphs": []}.';
    }

    /**
     * How the words of each spoken Arabic are written, so the model keeps them
     * as said: the commonest dialect words, which it otherwise "corrects".
     */
    public static function dialectGuide(): string
    {
        return 'Arabic is written exactly in the dialect spoken (Gulf, Egyptian, Levantine, Iraqi, Yemeni, Sudanese, Maghrebi, ...), the way its own speakers write it in messages: '
            .'never rewrite it into Modern Standard Arabic, never put a Standard word in place of the dialect word said, and never add Standard words that were not said (أن، قد، سوف، إنّ). '
            .'Keep each dialect word in its own spelling, for example: '
            .'Egyptian: ده، دي، دول، عايز، عايزة، دلوقتي، أوي (never قوي)، إزاي، إيه، فين، مش، كده، بقى، هـ before a verb for the future (هيروح، هتعمل)، النهارده، بكرة، لسه؛ '
            .'Upper Egypt: يجول/يگول as said، دلوك؛ '
            .'Gulf: وايد، شلون، شنو، وش، إيش، الحين، دحين، أبي، أبغى، مب، مو، زين، ريّال، ياي، چذي where ك is said as ch؛ '
            .'Levantine: شو، هيك، هلّق، هلأ، كتير، بدّي، منيح، ليش، هاد، هيدا، إشي، تبعي؛ '
            .'Iraqi: شكو، ماكو، أكو، هواية، هسّه، كلش، شلونك، گ where ق is said as g؛ '
            .'Yemeni: ذلحين، أشتي، كيفك، زلط؛ Sudanese: زول، داير، شنو، ياخ، كدي؛ '
            .'Maghrebi: بزاف، واش، دابا، ديال، كيفاش، مزيان، والو، شحال، برشا، توا، باهي، ياسر، نتا، نتي، ف joined to the next word for "in" (فالدار، فمراكش, never في الدار), '
            .'كا/تا before a verb (كنمشي، تانسمعهم)، گاع، ذرك/درك؛ '
            .'a French or English word said inside an Arabic sentence is written in Arabic letters as it sounds (نورمال، ديجا، صافا، لوطوروت، الكليما), never in Latin letters. '
            .'Write ة, ى and أ/إ the usual way, and never add diacritics the speaker did not say.'."\n";
    }

    /** What the site knows of a chosen dialect or accent: its name and how it sounds and is written. */
    public static function varietyNote(string $code): string
    {
        if ($code === '') {
            return '';
        }
        if (str_starts_with(strtolower($code), 'ar')) {
            $name = ArabicDialects::prompt($code);
            $markers = ArabicDialects::markers($code);
            if ($name === null) {
                return '';
            }

            return "The speaker speaks {$name}".($markers ? ": {$markers}" : '.')
                ."\nWrite their words with that dialect's usual spelling; where ق is said as g or as a glottal stop it is still written ق (or گ where that is how people there write it).\n";
        }
        if (str_starts_with(strtolower($code), 'en')) {
            $name = EnglishAccents::prompt($code);

            return $name !== null ? "The speaker has this accent: {$name}. Write standard spelling for the words said, and keep local words and grammar as said.\n" : '';
        }

        return '';
    }

    /**
     * The model's answer made safe: paragraphs with words and a speaker letter
     * (A where it gave none), and the voices it described.
     *
     * @param  array<string, mixed>  $data
     * @return array{paragraphs: array<int, array{speaker: string, text: string}>, language: string, voices: array<int, array{id: string, voice: string}>}
     */
    public static function read(array $data): array
    {
        if (array_is_list($data)) {
            $data = ['paragraphs' => $data];
        }
        $language = strtolower(substr(preg_replace('/[^a-zA-Z-]/', '', (string) (is_scalar($data['language'] ?? null) ? $data['language'] : '')) ?? '', 0, 8));
        $language = explode('-', $language)[0];
        $voices = [];
        foreach ((array) ($data['speakers'] ?? []) as $v) {
            $id = is_array($v) && is_scalar($v['id'] ?? null) ? mb_substr(trim((string) $v['id']), 0, 8) : '';
            if ($id !== '') {
                $voices[] = ['id' => $id, 'voice' => mb_substr(trim(is_scalar($v['voice'] ?? null) ? (string) $v['voice'] : ''), 0, 120)];
            }
        }
        $paragraphs = [];
        foreach ((array) ($data['paragraphs'] ?? $data['segments'] ?? []) as $p) {
            $text = is_scalar($p) ? trim((string) $p) : (is_array($p) && is_scalar($p['text'] ?? null) ? trim((string) $p['text']) : '');
            if ($text === '') {
                continue;
            }
            $speaker = is_array($p) && is_scalar($p['speaker'] ?? null) ? mb_substr(trim((string) $p['speaker']), 0, 8) : '';
            $paragraphs[] = ['speaker' => $speaker !== '' ? $speaker : 'A', 'text' => $text];
        }
        foreach (['text', 'transcript'] as $key) {
            if ($paragraphs === [] && is_scalar($data[$key] ?? null) && trim((string) $data[$key]) !== '') {
                $paragraphs = [['speaker' => 'A', 'text' => trim((string) $data[$key])]];
            }
        }

        return ['paragraphs' => $paragraphs, 'language' => $language, 'voices' => $voices];
    }

    /** A language's English name from its code (fr → French), or '' when unknown. */
    public static function languageName(string $code): string
    {
        $code = strtolower(trim($code));
        if ($code === '' || $code === 'auto') {
            return '';
        }
        $name = class_exists(\Locale::class) ? (string) \Locale::getDisplayLanguage($code, 'en') : '';

        return $name !== '' && strtolower($name) !== $code ? $name : '';
    }

    /**
     * What a transcription's tokens cost in US dollars, at the dubbing's
     * prices (listen.dubbing.prices: audio_in, text_in, text_out a million).
     *
     * @param  array<string, int>  $usage
     * @param  array<string, float>|null  $prices
     */
    public static function cost(array $usage, ?array $prices = null): float
    {
        $prices ??= (array) config('listen.dubbing.prices', []);
        $audio = (int) ($usage['audio_tokens'] ?? 0);
        $text = max(0, (int) ($usage['input_tokens'] ?? 0) - $audio);
        $out = (int) ($usage['output_tokens'] ?? 0) + (int) ($usage['thinking_tokens'] ?? 0);

        return ($audio * (float) ($prices['audio_in'] ?? 0) + $text * (float) ($prices['text_in'] ?? 0) + $out * (float) ($prices['text_out'] ?? 0)) / 1_000_000;
    }

    /**
     * One request to the text model, the models tried in turn and each one's
     * keys (as VideoDubber does): its answer as JSON (null when there is none),
     * the raw answer, what it used, which model gave it and why it stopped.
     *
     * @param  array<int, array<string, mixed>>  $parts
     * @param  array<string, mixed>  $schema
     * @return array{data: ?array<string, mixed>, raw: string, usage: array<string, int>, model: string, finish: string}
     */
    private function askModel(array $parts, string $thinking, array $schema = []): array
    {
        $base = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $body = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0]
                + ($schema !== [] ? ['responseSchema' => $schema] : [])
                + ($thinking !== '' ? ['thinkingConfig' => ['thinkingLevel' => $thinking]] : []),
        ];
        $last = 'no key';
        foreach (VideoDubber::MODELS as $model) {
            foreach ($this->keys->keysInOrder($model) as $key) {
                $response = Http::timeout(240)->withHeaders(['x-goog-api-key' => $key['key']])->post($base.'/models/'.$model.':generateContent', $body);
                // A model that does not take that thinking level, or the answer's shape, is asked again without it.
                foreach (['thinking' => 'thinkingConfig', 'schema' => 'responseSchema'] as $word => $setting) {
                    if ($response->status() !== 400 || ! str_contains(strtolower($response->body()), $word) || ! isset($body['generationConfig'][$setting])) {
                        continue;
                    }
                    unset($body['generationConfig'][$setting]);
                    $response = Http::timeout(240)->withHeaders(['x-goog-api-key' => $key['key']])->post($base.'/models/'.$model.':generateContent', $body);
                }
                $this->keys->recordUsage($key['name']);
                if ($response->status() === 404) {
                    $last = 'model '.$model.' not found';

                    continue 2;
                }
                if (! $response->ok()) {
                    $last = 'HTTP '.$response->status();

                    continue;
                }
                $raw = '';
                foreach ((array) $response->json('candidates.0.content.parts', []) as $piece) {
                    $raw .= is_array($piece) && empty($piece['thought']) ? (string) ($piece['text'] ?? '') : '';
                }
                $raw = trim((string) preg_replace('/^```(?:json)?|```$/m', '', $raw));
                $data = json_decode($raw, true);
                if (! is_array($data) && preg_match('/\{.*\}/s', $raw, $m) === 1) {
                    $data = json_decode($m[0], true);
                }

                return ['data' => is_array($data) ? $data : null, 'raw' => mb_substr($raw, 0, 400), 'usage' => VideoDubber::usage((array) $response->json('usageMetadata')), 'model' => $model,
                    'finish' => (string) ($response->json('candidates.0.finishReason') ?? $response->json('promptFeedback.blockReason') ?? '')];
            }
        }

        throw new \RuntimeException('The speech could not be written out ('.$last.').');
    }

    /** Any audio (or a video's sound) as 16 kHz mono MP3: what the model hears, small to send. */
    private function normalise(string $ffmpeg, string $in, string $out): void
    {
        $p = new Process([$ffmpeg, '-v', 'error', '-y', '-i', $in, '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'libmp3lame', '-b:a', '48k', $out]);
        $p->setTimeout(600)->run();
        if (! $p->isSuccessful() || ! is_file($out) || filesize($out) === 0) {
            throw new \RuntimeException('The audio could not be read: '.mb_substr(trim($p->getErrorOutput()), 0, 300));
        }
    }

    private function cut(string $ffmpeg, string $in, float $from, float $length, string $out): void
    {
        $p = new Process([$ffmpeg, '-v', 'error', '-y', '-ss', sprintf('%.2f', $from), '-t', sprintf('%.2f', $length), '-i', $in, '-c:a', 'libmp3lame', '-b:a', '48k', $out]);
        $p->setTimeout(300)->run();
        if (! $p->isSuccessful()) {
            throw new \RuntimeException('The audio could not be cut: '.mb_substr(trim($p->getErrorOutput()), 0, 300));
        }
    }

    /** Seconds of audio, as ffmpeg reads them. */
    public function duration(string $ffmpeg, string $file): float
    {
        $p = new Process([$ffmpeg, '-hide_banner', '-i', $file, '-f', 'null', '-']);
        $p->setTimeout(600)->run();
        $times = [];
        preg_match_all('/time=(\d+):(\d+):(\d+(?:\.\d+)?)/', $p->getErrorOutput(), $times, PREG_SET_ORDER);
        $last = end($times);
        if ($last) {
            return (int) $last[1] * 3600 + (int) $last[2] * 60 + (float) $last[3];
        }
        if (preg_match('/Duration: (\d+):(\d+):(\d+(?:\.\d+)?)/', $p->getErrorOutput(), $m) === 1) {
            return (int) $m[1] * 3600 + (int) $m[2] * 60 + (float) $m[3];
        }

        return 0.0;
    }

    /**
     * The pauses in the audio, as ffmpeg's silence detection finds them.
     *
     * @return array<int, array{0: float, 1: float}>
     */
    private function pauses(string $ffmpeg, string $file): array
    {
        $p = new Process([$ffmpeg, '-hide_banner', '-i', $file, '-af', 'silencedetect=noise='.self::PAUSE_DB.'dB:d='.self::PAUSE_SECONDS, '-f', 'null', '-']);
        $p->setTimeout(600)->run();

        return self::readPauses($p->getErrorOutput());
    }

    /** @return array<int, array{0: float, 1: float}> */
    public static function readPauses(string $log): array
    {
        $pauses = [];
        $start = null;
        foreach (preg_split('/\R/', $log) ?: [] as $line) {
            if (preg_match('/silence_start: (-?\d+(?:\.\d+)?)/', $line, $m) === 1) {
                $start = max(0.0, (float) $m[1]);
            } elseif ($start !== null && preg_match('/silence_end: (\d+(?:\.\d+)?)/', $line, $m) === 1) {
                $pauses[] = [$start, (float) $m[1]];
                $start = null;
            }
        }

        return $pauses;
    }
}
