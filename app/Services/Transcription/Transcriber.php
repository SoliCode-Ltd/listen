<?php

namespace App\Services\Transcription;

use App\Services\Dubbing\SpeechNotWrittenOut;
use App\Services\Dubbing\VideoDubber;
use App\Services\Tts\GeminiKeyManager;
use App\Support\ArabicDialects;
use App\Support\AudioFormats;
use App\Support\EnglishAccents;
use App\Support\Transcription\TranscriptionSettings;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
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
 * times over a long stretch, it drifts (measured on staging, November 2026).
 * The times come after, in short windows (timingWindows(), about 30 s, cut on
 * pauses), each heard on its own with the sentences near it: in so little
 * audio the start it hears for a sentence is close, and no error carries on
 * to the next window. The starts are then moved onto where speech begins in
 * the sound itself (placeOnStarts()). Where they cannot be had, the sentences
 * are spread over the speech by their length instead (placeOnSpeech()). That
 * is what the player follows, and the subtitles (SRT, VTT).
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

    /** The audio timed in one request: about this many seconds, cut on a pause between the two limits. */
    public const TIMING_WINDOW = 30.0;

    private const TIMING_WINDOW_MIN = 18.0;

    private const TIMING_WINDOW_MAX = 42.0;

    /** Timing requests sent at once. */
    private const TIMING_AT_ONCE = 5;

    /**
     * Below these a recording holds no voice to write out, and is not sent to the model at all
     * (on silence or a hum it can make up a sentence nobody said): less sound than this above
     * the pause level, or nothing in it louder than this.
     */
    public const LEAST_SPEECH_SECONDS = 0.4;

    public const QUIETEST_VOICE_DB = -42.0;

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
     * A finished transcript's sentences placed on its recording again (the words kept as they
     * are), timed in windows as a new transcript is: for one made before that timing. The new
     * sentences with their times, and what it used; null when they could not be timed.
     *
     * @param  string  $clean  the transcript's own audio (16 kHz mono MP3)
     * @param  array<int, array<string, mixed>>  $segments  its sentences, in order
     * @return array{segments: array<int, array<string, mixed>>, usage: array<string, int>, requests: int, notes: array<int, string>}|null
     */
    public function retime(string $clean, array $segments): ?array
    {
        $ffmpeg = self::ffmpeg();
        $seconds = $this->duration($ffmpeg, $clean);
        $pauses = $this->pauses($ffmpeg, $clean);
        $speech = self::speechIn($pauses, 0.0, $seconds);
        $sentences = array_map(fn ($s) => ['speaker' => (string) ($s['speaker'] ?? 'A'), 'text' => (string) ($s['text'] ?? ''), 'paragraph' => (int) ($s['paragraph'] ?? 0)], array_values($segments));
        $run = ['usage' => ['input_tokens' => 0, 'audio_tokens' => 0, 'output_tokens' => 0, 'thinking_tokens' => 0], 'requests' => 0, 'notes' => []];
        $tmp = sys_get_temp_dir().'/listen-retime-'.bin2hex(random_bytes(6));
        @mkdir($tmp, 0700, true);
        try {
            $starts = $this->alignStarts($ffmpeg, $clean, $tmp, 0.0, $seconds, $sentences, $pauses, $speech, $run);
            $placed = $starts !== null ? self::placeOnStarts($sentences, $starts, $speech, 0.0, $seconds) : null;
        } finally {
            foreach ((array) glob($tmp.'/*') as $f) {
                @unlink((string) $f);
            }
            @rmdir($tmp);
        }

        return $placed === null ? null : ['segments' => $placed, 'usage' => $run['usage'], 'requests' => $run['requests'], 'notes' => $run['notes']];
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
        $loudest = $this->loudest($ffmpeg, $clean);
        $heard = self::mergePauses($pauses, self::softPausesFrom($this->levels($ffmpeg, $clean), self::LEVEL_FRAME));
        $language = strtolower(trim((string) ($o['language'] ?? 'auto'))) ?: 'auto';

        return [
            'ask' => ['language' => $language, 'dialect' => trim((string) ($o['dialect'] ?? '')), 'review' => (bool) ($o['review'] ?? false), 'thinking' => (string) ($o['thinking'] ?? 'low')],
            'asked' => $language,
            'seconds' => round($seconds, 2),
            'pauses' => $heard,
            'speech_seconds' => round(self::speechSeconds($pauses, 0.0, $seconds), 2),
            'loudest_db' => round($loudest, 1),
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
        $from = $n === 0 ? 0.0 : (float) $part['keep_from'];
        $to = $n === count($plan) - 1 ? (float) $state['seconds'] : min((float) $state['seconds'], (float) $part['keep_to'] + self::OVERLAP);
        try {
            $heard = $this->hearStretch(self::ffmpeg(), $clean, $tmp, $part, $state['pauses'], $state['ask'] + ['part' => count($plan) > 1], $state['run'], 'part '.($n + 1));
            // Detected once, then given: the rest of the recording is heard in the language found.
            if ($state['ask']['language'] === 'auto' && $heard['language'] !== '' && self::languageName($heard['language']) !== '') {
                $state['ask']['language'] = $heard['language'];
            }
            $mine = self::withoutRepeatedStart((string) $state['before'], $heard['paragraphs']);
            $sentences = self::sentences($mine, count($state['paragraphs']));
            $speech = self::speechIn($state['pauses'], $from, $to);
            // Each sentence where it is heard to start (the words are not asked again, only the times);
            // shared out over the speech by its length when that answer does not hold together.
            $starts = $this->alignStarts(self::ffmpeg(), $clean, $tmp, $from, $to, $sentences, (array) $state['pauses'], $speech, $state['run']);
            $onStarts = $starts !== null ? self::placeOnStarts($sentences, $starts, $speech, $from, $to) : null;
            $placed = $onStarts ?? self::placeOnSpeech($sentences, $speech, $from, $to);
            if ($onStarts !== null) {
                $state['run']['aligned'] = ($state['run']['aligned'] ?? 0) + 1;
            } elseif ($starts !== null) {
                $state['run']['notes'][] = 'times: part '.($n + 1).' did not hold together, shared out over the speech';
            }
        } finally {
            foreach ((array) glob($tmp.'/*') as $f) {
                @unlink((string) $f);
            }
            @rmdir($tmp);
        }
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
            // Parts whose sentences were placed where they are heard to start (the rest: shared out over the speech).
            'aligned' => (int) ($state['run']['aligned'] ?? 0),
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
            $seconds = (int) $m[1] * 3600 + (int) $m[2] * 60 + (float) $m[3];
            if ($seconds > 0) {
                return $seconds;
            }
        }

        // A recording made in the browser (WebM, Ogg) is written as it is made, so its header has no
        // length ("Duration: N/A"): it is read to its end to find it, as the browser does to play it.
        $p = new Process([self::ffmpeg(), '-hide_banner', '-nostats', '-i', $file, '-vn', '-f', 'null', '-progress', 'pipe:1', '-']);
        $p->setTimeout(300)->run();
        if (preg_match_all('/out_time_us=(\d+)/', $p->getOutput(), $all) && $all[1] !== []) {
            return max(0.0, (int) end($all[1]) / 1_000_000);
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
     * When each sentence starts, heard by the model in short windows of the part's audio
     * (timingWindows()): each window is sent with the sentences that may fall in it (where
     * their length would put them, give or take a wide margin), numbered as in the part, and
     * answers with the times of those that begin inside it. The words are not asked again, so
     * they cannot change. The windows are asked at once; their answers are put together by
     * startsFromClaims(). Seconds on the recording's clock, one for each sentence, or null when
     * too few could be timed (the caller then shares the sentences out by their length).
     *
     * @param  array<int, array<string, mixed>>  $sentences  with 'text'
     * @param  array<int, array{0: float, 1: float}>  $pauses
     * @param  array<int, array{0: float, 1: float}>  $speech  where speech is heard in the part (speechIn())
     * @param  array<string, mixed>  $run  the run's counters, changed in place
     * @return array<int, float>|null
     */
    private function alignStarts(string $ffmpeg, string $clean, string $tmp, float $from, float $to, array $sentences, array $pauses, array $speech, array &$run): ?array
    {
        $sentences = array_values($sentences);
        if (count($sentences) < 2 || $to - $from < 1.0) {
            return null;
        }
        // Where each sentence would start if the speech were shared out by length: only to choose
        // which sentences each window is shown (the margin is wide, as that guess can be well off).
        $rough = array_column(self::placeOnSpeech($sentences, $speech, $from, $to), 'start');
        $margin = 25.0 + 0.1 * ($to - $from);
        $windows = self::timingWindows($pauses, $from, $to);
        $asks = [];
        $shown = [];
        foreach ($windows as $w => [$start, $end]) {
            $length = $end - $start;
            $heard = min($to, $end + 1.0) - $start;
            $lines = [];
            $shown[$w] = [];
            foreach ($sentences as $i => $sentence) {
                $at = (float) ($rough[$i] ?? $from);
                if (count($windows) === 1 || ($at >= $start - $margin && $at <= $end + $margin)) {
                    $shown[$w][] = $i;
                    $lines[] = ($i + 1).'. '.trim((string) $sentence['text']);
                }
            }
            if ($lines === []) {
                continue;
            }
            $audio = $tmp.'/time-'.$w.'.mp3';
            try {
                $this->cut($ffmpeg, $clean, $start, $heard, $audio);
            } catch (\Throwable $e) {
                $run['notes'][] = 'times: '.mb_substr($e->getMessage(), 0, 120);

                continue;
            }
            $prompt = 'This audio is '.number_format($heard, 1, '.', '').' seconds long, cut from a longer recording. '
                ."These are sentences of its transcript from around this point, numbered, in the order they are spoken; some of them are spoken before or after this audio:\n\n"
                .implode("\n", $lines)
                ."\n\nFor each of these sentences whose first word is spoken in this audio before "
                .number_format($length, 1, '.', '').' seconds, give that time in seconds from the start of this audio, with one decimal. '
                .'Leave out every sentence that is not heard beginning in that time: those spoken before or after it, and a sentence already under way when the audio opens. '
                .'Music, noise or silence before a sentence is not part of it.';
            $asks[$w] = [['inline_data' => ['mime_type' => 'audio/mpeg', 'data' => base64_encode((string) file_get_contents($audio))]], ['text' => $prompt]];
            @unlink($audio);
        }
        if ($asks === []) {
            return null;
        }
        $claims = [];
        $answered = 0;
        foreach ($this->askModelMany($asks, 'low', self::alignSchema()) as $w => $answer) {
            if ($answer === null) {
                continue;
            }
            $answered++;
            $run['requests']++;
            foreach ($answer['usage'] as $key => $tokens) {
                $run['usage'][$key] = ($run['usage'][$key] ?? 0) + $tokens;
            }
            [$start, $end] = $windows[$w];
            foreach ((array) ($answer['data']['sentences'] ?? []) as $row) {
                $i = is_array($row) && is_numeric($row['n'] ?? null) ? (int) $row['n'] - 1 : -1;
                $at = is_array($row) && is_numeric($row['start'] ?? null) ? (float) $row['start'] : -1.0;
                // Only a sentence it was shown, beginning inside its own stretch.
                if (in_array($i, $shown[$w], true) && $at >= 0 && $at <= $end - $start + 0.3) {
                    $claims[] = ['i' => $i, 'at' => $start + $at, 'window' => $w];
                }
            }
        }
        $starts = self::startsFromClaims($claims, $sentences, $from, $to);
        $timed = count(array_unique(array_column($claims, 'i')));
        $run['notes'][] = 'times: '.count($windows).' window'.(count($windows) === 1 ? '' : 's').', '.$answered.' answered, '.$timed.' of '.count($sentences).' sentences heard';

        return $starts;
    }

    /**
     * The windows a part is timed in: about TIMING_WINDOW seconds each, cut in the pause
     * nearest that (a longer pause preferred) between TIMING_WINDOW_MIN and TIMING_WINDOW_MAX,
     * or at TIMING_WINDOW when there is none (music under the voice); end to end, from $from to $to.
     *
     * @param  array<int, array{0: float, 1: float}>  $pauses
     * @return array<int, array{0: float, 1: float}>
     */
    public static function timingWindows(array $pauses, float $from, float $to): array
    {
        $windows = [];
        $at = $from;
        while ($to - $at > self::TIMING_WINDOW_MAX) {
            $cut = null;
            $best = INF;
            foreach ($pauses as [$start, $end]) {
                $middle = ($start + $end) / 2;
                if ($middle < $at + self::TIMING_WINDOW_MIN || $middle > $at + self::TIMING_WINDOW_MAX || $middle >= $to - 5.0) {
                    continue;
                }
                $score = abs($middle - ($at + self::TIMING_WINDOW)) - 4.0 * min(1.0, $end - $start);
                if ($score < $best) {
                    $best = $score;
                    $cut = $middle;
                }
            }
            $cut ??= $at + self::TIMING_WINDOW;
            $windows[] = [round($at, 2), round($cut, 2)];
            $at = $cut;
        }
        $windows[] = [round($at, 2), round($to, 2)];

        return $windows;
    }

    /**
     * The windows' answers put together: one start for each sentence, in order. A sentence
     * claimed by two windows (it begins near the cut) keeps the earlier window's time (the
     * later one may hear it already under way); claims out of order are dropped (the longest
     * run in order is kept); a sentence left without a time is placed between the sentences
     * either side of it, by its length. Null when fewer than half were timed.
     *
     * @param  array<int, array{i: int, at: float, window: int}>  $claims
     * @param  array<int, array<string, mixed>>  $sentences  with 'text'
     * @return array<int, float>|null
     */
    public static function startsFromClaims(array $claims, array $sentences, float $from, float $to): ?array
    {
        $sentences = array_values($sentences);
        $n = count($sentences);
        if ($n === 0) {
            return null;
        }
        usort($claims, fn ($a, $b) => [$a['window'], $a['at']] <=> [$b['window'], $b['at']]);
        $time = [];
        foreach ($claims as $c) {
            $time[$c['i']] ??= (float) $c['at'];
        }
        ksort($time);
        // The longest run of sentences whose times go up with them.
        $keys = array_keys($time);
        $length = [];
        $before = [];
        foreach ($keys as $a => $i) {
            $length[$a] = 1;
            $before[$a] = -1;
            for ($b = 0; $b < $a; $b++) {
                if ($time[$keys[$b]] <= $time[$i] + 0.05 && $length[$a] < $length[$b] + 1) {
                    $length[$a] = $length[$b] + 1;
                    $before[$a] = $b;
                }
            }
        }
        $kept = [];
        if ($keys !== []) {
            $a = (int) array_search(max($length), $length, true);
            for (; $a >= 0; $a = $before[$a]) {
                $kept[$keys[$a]] = $time[$keys[$a]];
            }
        }
        if (count($kept) * 2 < $n) {
            return null;
        }
        // The rest between their neighbours, by length.
        $size = array_map(fn ($s) => max(1, mb_strlen(trim((string) ($s['text'] ?? '')))), $sentences);
        $starts = [];
        $i = 0;
        while ($i < $n) {
            if (isset($kept[$i])) {
                $starts[$i] = $kept[$i];
                $i++;

                continue;
            }
            $j = $i;
            while ($j < $n && ! isset($kept[$j])) {
                $j++;
            }
            // Sentences $i..$j-1 have no time: they share the span from the one before (or the
            // part's start) to the one after (or the part's end) with the one before, by length.
            $left = $i > 0 ? $starts[$i - 1] : $from;
            $right = $j < $n ? $kept[$j] : $to;
            $first = $i > 0 ? $i - 1 : $i;
            $total = array_sum(array_slice($size, $first, $j - $first));
            $run = 0;
            for ($k = $first; $k < $j; $k++) {
                if ($k >= $i) {
                    $starts[$k] = round($left + ($right - $left) * $run / max(1, $total), 2);
                }
                $run += $size[$k];
            }
            $i = $j;
        }
        ksort($starts);

        return array_values($starts);
    }

    /** @return array<string, mixed> the shape of the times answer */
    public static function alignSchema(): array
    {
        $row = ['type' => 'OBJECT', 'properties' => ['n' => ['type' => 'INTEGER'], 'start' => ['type' => 'NUMBER']], 'required' => ['n', 'start'], 'propertyOrdering' => ['n', 'start']];

        return ['type' => 'OBJECT', 'properties' => ['sentences' => ['type' => 'ARRAY', 'items' => $row]], 'required' => ['sentences']];
    }

    /**
     * The sentences on the clock from the times heard for their starts: each start moved onto
     * where speech begins when one is within 0.6 s (the times heard are close, the pauses exact),
     * and each sentence ending where its speech ends before the next one starts. Null when the
     * times do not hold together (outside the audio, many going back, or most the same), so the
     * caller places them by their length instead.
     *
     * @param  array<int, array<string, mixed>>  $sentences
     * @param  array<int, float>  $starts  seconds on the recording's clock, one for each sentence
     * @param  array<int, array{0: float, 1: float}>  $speech  where speech is heard (speechIn())
     * @return array<int, array<string, mixed>>|null
     */
    public static function placeOnStarts(array $sentences, array $starts, array $speech, float $from, float $to): ?array
    {
        $sentences = array_values($sentences);
        $starts = array_values($starts);
        $n = count($sentences);
        if ($n === 0 || count($starts) !== $n) {
            return null;
        }
        $times = [];
        $back = 0;
        $same = 0;
        $prev = $from;
        foreach ($starts as $i => $start) {
            $start = (float) $start;
            if ($start < $from - 1.5 || $start > $to + 1.5) {
                return null;
            }
            if ($i > 0 && $start < $prev - 0.3) {
                $back++;
            }
            if ($i > 0 && abs($start - $prev) < 0.05) {
                $same++;
            }
            $start = min(max($start, $prev, $from), $to);
            $times[] = $start;
            $prev = $start;
        }
        if ($back > max(1, intdiv($n, 5)) || ($n > 2 && $same >= intdiv($n, 2))) {
            return null;
        }
        // Onto where speech begins after a pause, all together and in order (snapToOnsets()).
        $times = self::snapToOnsets($times, array_map(fn ($sp) => (float) $sp[0], $speech));
        $placed = [];
        foreach ($sentences as $i => $sentence) {
            $start = $times[$i];
            $next = $i + 1 < $n ? $times[$i + 1] : $to;
            // Where its speech ends before the next sentence starts (the pause between them is neither's).
            $end = $next;
            foreach ($speech as [$begin, $stop]) {
                if ($stop > $start + 0.2 && $stop <= $next - 0.05) {
                    $end = (float) $stop;
                }
            }
            $end = $i + 1 < $n ? min(max($end, $start + 0.2), $next) : max($end, $start + 0.2);
            $placed[] = $sentence + ['start' => round($start, 2), 'end' => round(min($end, max($to, $start + 0.2)), 2)];
        }

        return $placed;
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

    /** Whether a recording made ready (prepare()) holds no voice: too little sound, or none loud enough. */
    public static function heardNothing(array $state): bool
    {
        return (float) ($state['speech_seconds'] ?? self::LEAST_SPEECH_SECONDS) < self::LEAST_SPEECH_SECONDS
            || (float) ($state['loudest_db'] ?? 0.0) < self::QUIETEST_VOICE_DB;
    }

    /** Whether a finished transcript (result()) holds no word at all: nothing, or only [inaudible]. */
    public static function nothingWritten(array $result): bool
    {
        foreach ((array) ($result['paragraphs'] ?? []) as $p) {
            $text = str_ireplace('[inaudible]', '', (string) ($p['text'] ?? ''));
            if (preg_match('/[\p{L}\p{N}]/u', $text) === 1) {
                return false;
            }
        }

        return true;
    }

    /** Seconds in each loudness reading (levels()). */
    public const LEVEL_FRAME = 0.05;

    /** How loud a recording is every LEVEL_FRAME seconds, in dB (-120 for silence). @return array<int, float> */
    public function levels(string $ffmpeg, string $file): array
    {
        $p = new Process([$ffmpeg, '-hide_banner', '-nostats', '-i', $file, '-af',
            'aresample=8000,asetnsamples=n='.(int) round(8000 * self::LEVEL_FRAME).':p=0,astats=metadata=1:reset=1,ametadata=print:key=lavfi.astats.Overall.RMS_level:file=-', '-f', 'null', '-']);
        $p->setTimeout(600)->run();
        preg_match_all('/RMS_level=(-?inf|-?[\d.]+)/', $p->getOutput(), $m);

        return array_map(fn ($v) => str_contains($v, 'inf') ? -120.0 : max(-120.0, (float) $v), $m[1] ?? []);
    }

    /**
     * Pauses heard against the recording's own background (music or noise under the voice), where
     * the fixed level of pauses() finds none. The voice's own level is the middle reading; what is
     * clearly quieter than it (12 dB) is the background, when there is enough of it (2% of the
     * recording) to measure; a pause is then a stretch of at least 0.2 s within 6 dB of the
     * background and well under the voice. Nothing when the background is quiet (pauses() has those).
     *
     * @param  array<int, float>  $levels  dB every $frame seconds
     * @return array<int, array{0: float, 1: float}>
     */
    public static function softPausesFrom(array $levels, float $frame): array
    {
        $n = count($levels);
        if ($n < 20) {
            return [];
        }
        $sorted = $levels;
        sort($sorted);
        $voice = $sorted[intdiv($n, 2)];
        $quiet = array_values(array_filter($sorted, fn ($db) => $db < $voice - 12.0));
        if (count($quiet) < max(4, (int) ceil($n * 0.02))) {
            return [];
        }
        $background = $quiet[intdiv(count($quiet), 2)];
        $limit = min($background + 6.0, $voice - 8.0);
        if ($limit < self::PAUSE_DB - 3) {
            return [];
        }
        $pauses = [];
        $from = null;
        foreach (array_merge($levels, [0.0]) as $i => $db) {
            if ($db <= $limit) {
                $from ??= $i;

                continue;
            }
            if ($from !== null && ($i - $from) * $frame >= 0.2) {
                $pauses[] = [round($from * $frame, 2), round($i * $frame, 2)];
            }
            $from = null;
        }

        return $pauses;
    }

    /**
     * Two lists of pauses as one, in order, overlapping ones joined.
     *
     * @param  array<int, array{0: float, 1: float}>  $a
     * @param  array<int, array{0: float, 1: float}>  $b
     * @return array<int, array{0: float, 1: float}>
     */
    public static function mergePauses(array $a, array $b): array
    {
        $all = array_merge($a, $b);
        usort($all, fn ($x, $y) => $x[0] <=> $y[0]);
        $out = [];
        foreach ($all as [$start, $end]) {
            $last = count($out) - 1;
            if ($last >= 0 && $start <= $out[$last][1]) {
                $out[$last][1] = max($out[$last][1], (float) $end);
            } else {
                $out[] = [(float) $start, (float) $end];
            }
        }

        return $out;
    }

    /**
     * Sentence starts moved onto where speech begins after a pause, all at once: each start may
     * take one such moment up to $reach seconds away, no two the same, in order, and a start left
     * where it was heard costs $miss. So a start heard up to about a second off goes onto the pause
     * it belongs to, while one said without a pause before it keeps the time it was heard at.
     *
     * @param  array<int, float>  $times  heard starts, in order
     * @param  array<int, float>  $onsets  where speech begins again, in order
     * @return array<int, float>
     */
    public static function snapToOnsets(array $times, array $onsets, float $miss = 1.0, float $reach = 1.5): array
    {
        $times = array_values($times);
        sort($onsets);
        $n = count($times);
        $k = count($onsets);
        if ($n === 0 || $k === 0) {
            return $times;
        }
        // cost[i][j]: the least for the first i starts using the first j onsets; how: 0 an onset passed over, 1 a start kept, 2 matched.
        $cost = array_fill(0, $n + 1, array_fill(0, $k + 1, 0.0));
        $how = array_fill(0, $n + 1, array_fill(0, $k + 1, 0));
        for ($i = 1; $i <= $n; $i++) {
            $cost[$i][0] = $i * $miss;
            $how[$i][0] = 1;
            for ($j = 1; $j <= $k; $j++) {
                $best = $cost[$i][$j - 1];
                $way = 0;
                if ($cost[$i - 1][$j] + $miss < $best) {
                    $best = $cost[$i - 1][$j] + $miss;
                    $way = 1;
                }
                $d = abs($times[$i - 1] - $onsets[$j - 1]);
                if ($d <= $reach && $cost[$i - 1][$j - 1] + $d < $best) {
                    $best = $cost[$i - 1][$j - 1] + $d;
                    $way = 2;
                }
                $cost[$i][$j] = $best;
                $how[$i][$j] = $way;
            }
        }
        $out = $times;
        for ($i = $n, $j = $k; $i > 0;) {
            $way = $how[$i][$j];
            if ($way === 2) {
                $out[$i - 1] = (float) $onsets[$j - 1];
                $i--;
                $j--;
            } elseif ($way === 1 || $j === 0) {
                $i--;
            } else {
                $j--;
            }
        }
        // Still in order.
        for ($i = 1; $i < $n; $i++) {
            $out[$i] = max($out[$i], $out[$i - 1]);
        }

        return array_map(fn ($v) => round($v, 2), $out);
    }

    /**
     * Paragraphs of a transcript in another language, by the text model: a few paragraphs to a
     * request, all the requests at once, each answer held to the same number of paragraphs. One
     * translation for each, or null where a request did not come back whole.
     *
     * @param  array<int, string>  $texts
     * @return array<int, ?string>
     */
    public function translateTexts(array $texts, string $from, string $to): array
    {
        $texts = array_values($texts);
        $out = array_fill(0, count($texts), null);
        $groups = [];
        $group = [];
        $size = 0;
        foreach ($texts as $i => $text) {
            if ($group !== [] && ($size + mb_strlen($text) > 2500 || count($group) >= 12)) {
                $groups[] = $group;
                $group = [];
                $size = 0;
            }
            $group[$i] = $text;
            $size += mb_strlen($text);
        }
        if ($group !== []) {
            $groups[] = $group;
        }
        $fromName = self::languageName($from) ?: $from;
        $toName = self::languageName($to) ?: $to;
        $asks = [];
        foreach ($groups as $g => $group) {
            $lines = [];
            foreach (array_values($group) as $n => $text) {
                $lines[] = ($n + 1).'. '.$text;
            }
            $asks[$g] = [['text' => "Translate each of these numbered paragraphs of a spoken transcript from {$fromName} into {$toName}. "
                .'Keep the meaning and the way each person speaks, natural for a native reader; keep names, brands and numbers as they are. '
                .'Return JSON {"paragraphs": [...]} with exactly '.count($group)." strings, one for each paragraph, in the same order.\n\n".implode("\n", $lines)]];
        }
        $schema = ['type' => 'OBJECT', 'properties' => ['paragraphs' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']]], 'required' => ['paragraphs']];
        foreach ($this->askModelMany($asks, 'low', $schema) as $g => $answer) {
            $got = array_values((array) ($answer['data']['paragraphs'] ?? []));
            if ($answer === null || count($got) !== count($groups[$g])) {
                continue;
            }
            foreach (array_keys($groups[$g]) as $n => $i) {
                $out[$i] = is_string($got[$n]) && trim($got[$n]) !== '' ? trim($got[$n]) : null;
            }
        }

        return $out;
    }

    /** The loudest moment of a recording in dB (0 at full scale; -120 for digital silence). */
    public function loudest(string $ffmpeg, string $file): float
    {
        $p = new Process([$ffmpeg, '-hide_banner', '-nostats', '-i', $file, '-af', 'volumedetect', '-f', 'null', '-']);
        $p->setTimeout(300)->run();
        if (preg_match('/max_volume:\s*(-?inf|-?[\d.]+) dB/', $p->getErrorOutput(), $m) !== 1) {
            return 0.0;
        }

        return str_contains($m[1], 'inf') ? -120.0 : (float) $m[1];
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
        if (isset(TranscriptionSettings::LANGUAGES[$code])) {
            return TranscriptionSettings::LANGUAGES[$code][0];
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
        $base = self::endpoint();
        $body = self::requestBody($parts, $thinking, $schema);
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

                return self::answerFrom($response, $model);
            }
        }

        throw new \RuntimeException('The speech could not be written out ('.$last.').');
    }

    /**
     * Several requests to the text model at once (TIMING_AT_ONCE at a time), on the first model
     * and its first key; any that does not come back is asked again on its own (askModel(): the
     * other keys and models in turn). Its answer for each, by the same key, or null when none came.
     *
     * @param  array<int|string, array<int, array<string, mixed>>>  $asks  the parts of each request
     * @param  array<string, mixed>  $schema
     * @return array<int|string, array{data: ?array<string, mixed>, raw: string, usage: array<string, int>, model: string, finish: string}|null>
     */
    private function askModelMany(array $asks, string $thinking, array $schema = []): array
    {
        $answers = [];
        $model = VideoDubber::MODELS[0] ?? null;
        $key = $model !== null ? ($this->keys->keysInOrder($model)[0] ?? null) : null;
        if ($key !== null) {
            $url = self::endpoint().'/models/'.$model.':generateContent';
            foreach (array_chunk(array_keys($asks), self::TIMING_AT_ONCE) as $batch) {
                try {
                    $responses = Http::pool(function (Pool $pool) use ($batch, $asks, $thinking, $schema, $key, $url): array {
                        $sent = [];
                        foreach ($batch as $name) {
                            $sent[] = $pool->as((string) $name)->timeout(120)->withHeaders(['x-goog-api-key' => $key['key']])
                                ->post($url, self::requestBody($asks[$name], $thinking, $schema));
                        }

                        return $sent;
                    });
                } catch (\Throwable) {
                    $responses = [];
                }
                foreach ($batch as $name) {
                    $response = $responses[(string) $name] ?? null;
                    if ($response instanceof Response) {
                        $this->keys->recordUsage($key['name']);
                        if ($response->ok()) {
                            $answers[$name] = self::answerFrom($response, $model);
                        }
                    }
                }
            }
        }
        foreach ($asks as $name => $parts) {
            if (! array_key_exists($name, $answers)) {
                try {
                    $answers[$name] = $this->askModel($parts, $thinking, $schema);
                } catch (\Throwable) {
                    $answers[$name] = null;
                }
            }
        }

        return array_replace(array_fill_keys(array_keys($asks), null), $answers);
    }

    private static function endpoint(): string
    {
        return rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
    }

    /**
     * @param  array<int, array<string, mixed>>  $parts
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function requestBody(array $parts, string $thinking, array $schema = []): array
    {
        return [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0]
                + ($schema !== [] ? ['responseSchema' => $schema] : [])
                + ($thinking !== '' ? ['thinkingConfig' => ['thinkingLevel' => $thinking]] : []),
        ];
    }

    /** @return array{data: ?array<string, mixed>, raw: string, usage: array<string, int>, model: string, finish: string} */
    private static function answerFrom(Response $response, string $model): array
    {
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
