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
 * Speech to text ("Transcribe"): what is said in a recording, word for word,
 * in the language and the words the speaker uses, as plain paragraphs or as
 * timed segments (with speaker letters where asked).
 *
 * It asks the same text model, with the same keys, as the dubbing's write-out
 * (VideoDubber::MODELS, GeminiKeyManager): one request per part of the
 * recording. A recording longer than MAX_PART seconds is cut into parts on its
 * pauses (chunkPlan()), each heard with a little of its neighbours around it
 * (OVERLAP) so no word at a cut is lost, and the parts are put back together
 * without the words heard twice (stitch(), stitchText()).
 *
 * What makes it accurate, each measured on staging (transcribe:measure):
 *  - Arabic is written in the speaker's own dialect, as said: never rewritten
 *    into Modern Standard Arabic, with the dialect words of every region kept
 *    in their own spelling (dialectGuide()); where the member names the
 *    dialect or accent, the site's own notes on it are given too
 *    (ArabicDialects::markers()).
 *  - The model is held to the answer's shape (schema()), and a part that
 *    comes back unreadable, or with far fewer words than its speech holds,
 *    is asked for again (hear()).
 *  - In a timed transcript, any stretch of speech no segment covers is cut
 *    out and heard again on its own (unheard()): a long part, or a part in a
 *    second language, now and then loses a sentence.
 *  - Speaker letters stay the same from part to part: each part is told
 *    the voices heard so far.
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
    /** The longest part heard in one request, in seconds. */
    public const MAX_PART = 300.0;

    /** A part is cut on a pause no earlier than this into it. */
    private const MIN_PART = 200.0;

    /** Seconds of the neighbouring parts heard around each part. */
    public const OVERLAP = 2.0;

    /** A pause at least this long, this quiet (dB), is a place to cut. */
    private const PAUSE_SECONDS = 0.35;

    private const PAUSE_DB = -35;

    /** Speech this long that no timed segment covers is heard again; at most this many stretches, this share of the recording. */
    public const UNHEARD_SECONDS = 2.5;

    private const MAX_UNHEARD = 8;

    private const MAX_UNHEARD_SHARE = 0.4;

    public function __construct(private GeminiKeyManager $keys) {}

    /**
     * $o: language ('auto' or a code such as 'ar', 'fr'), dialect (an
     * ArabicDialects or EnglishAccents code, optional), timings (bool),
     * speakers (bool, implies timings), review (bool), fill (bool, default
     * true: hear again the speech a timed transcript left out), thinking
     * ('low', 'medium', 'minimal', ''), progress (fn (int $done, int $parts)).
     *
     * @param  array<string, mixed>  $o
     * @return array{text: string, segments: array<int, array{start: float, end: float, speaker: ?string, text: string}>, language: string, seconds: float, parts: int, requests: int, recited: bool, usage: array<string, int>, model: string, finish: array<int, string>, notes: array<int, string>, timings: bool, speakers: bool, voices: array<int, array{id: string, voice: string}>}
     */
    public function transcribe(string $file, array $o = []): array
    {
        $ffmpeg = AudioFormats::ffmpegPath();
        if (! $ffmpeg) {
            throw new \RuntimeException('ffmpeg is not available.');
        }
        $speakers = (bool) ($o['speakers'] ?? false);
        $timings = $speakers || (bool) ($o['timings'] ?? false);
        $ask = [
            'language' => strtolower(trim((string) ($o['language'] ?? 'auto'))) ?: 'auto',
            'dialect' => trim((string) ($o['dialect'] ?? '')),
            'timings' => $timings, 'speakers' => $speakers,
            'review' => (bool) ($o['review'] ?? false),
            'thinking' => (string) ($o['thinking'] ?? 'low'),
        ];
        $progress = $o['progress'] ?? static function (): void {};

        $tmp = sys_get_temp_dir().'/listen-transcribe-'.bin2hex(random_bytes(6));
        @mkdir($tmp, 0700, true);
        $run = ['usage' => ['input_tokens' => 0, 'audio_tokens' => 0, 'output_tokens' => 0, 'thinking_tokens' => 0],
            'finish' => [], 'model' => '', 'requests' => 0, 'recited' => false, 'detected' => [], 'notes' => [], 'voices' => []];
        try {
            $clean = $tmp.'/clean.mp3';
            $this->normalise($ffmpeg, $file, $clean);
            $seconds = $this->duration($ffmpeg, $clean);
            $pauses = $this->pauses($ffmpeg, $clean);
            $plan = self::chunkPlan($pauses, $seconds);

            $pieces = [];
            foreach ($plan as $n => $part) {
                $heard = $this->hearStretch($ffmpeg, $clean, $tmp, $part['from'], $part['to'], $pauses, $ask + ['part' => count($plan) > 1], $run, 'part '.($n + 1));
                $pieces[] = $part + $heard;
                $progress($n + 1, count($plan));
            }
            $segments = $timings ? self::stitch($pieces) : [];

            // Speech no segment covers: heard again on its own, and its segments put in their place.
            if ($timings && ($o['fill'] ?? true)) {
                foreach (self::unheard($segments, $pauses, $seconds) as [$from, $to]) {
                    $heard = $this->hearStretch($ffmpeg, $clean, $tmp, max(0.0, $from - 0.6), min($seconds, $to + 0.6), $pauses,
                        $ask + ['part' => true, 'review' => false], $run, sprintf('%s-%s left out', self::clock($from), self::clock($to)));
                    $found = 0;
                    foreach ($heard['segments'] as $s) {
                        $start = round($s['start'] + max(0.0, $from - 0.6), 2);
                        $end = round($s['end'] + max(0.0, $from - 0.6), 2);
                        if (($start + $end) / 2 >= $from - 0.3 && ($start + $end) / 2 <= $to + 0.3 && ! self::covered($segments, $start, $end)) {
                            $segments[] = ['start' => $start, 'end' => max($start, $end), 'speaker' => $s['speaker'] ?? null, 'text' => $s['text']];
                            $found++;
                        }
                    }
                    $run['notes'][] = sprintf('%s-%s had no words: heard again, %d segment(s) found', self::clock($from), self::clock($to), $found);
                }
                usort($segments, fn ($a, $b) => $a['start'] <=> $b['start']);
            }
        } finally {
            foreach ((array) glob($tmp.'/*') as $f) {
                @unlink((string) $f);
            }
            @rmdir($tmp);
        }

        arsort($run['detected']);

        return [
            'text' => $timings ? self::textOf($segments, $speakers) : self::stitchText($pieces),
            'segments' => $segments,
            'language' => $ask['language'] !== 'auto' ? $ask['language'] : (string) (array_key_first($run['detected']) ?? ''),
            'seconds' => round($seconds, 2),
            'parts' => count($plan),
            'requests' => $run['requests'],
            'recited' => $run['recited'],
            'usage' => $run['usage'],
            'model' => $run['model'],
            'finish' => $run['finish'],
            'notes' => $run['notes'],
            'timings' => $timings,
            'speakers' => $speakers,
            'voices' => array_values($run['voices']),
        ];
    }

    /**
     * One stretch of the recording ($from-$to) cut out and heard, with what
     * it used added to $run; times are from the stretch's start.
     *
     * @param  array<int, array{0: float, 1: float}>  $pauses
     * @param  array<string, mixed>  $ask
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function hearStretch(string $ffmpeg, string $clean, string $tmp, float $from, float $to, array $pauses, array $ask, array &$run, string $label): array
    {
        $audio = $tmp.'/part-'.$run['requests'].'-'.(int) ($from * 100).'.mp3';
        $this->cut($ffmpeg, $clean, $from, $to - $from, $audio);
        $heard = $this->hear((string) file_get_contents($audio), $to - $from, self::speechSeconds($pauses, $from, $to), $ask + ['known' => array_values($run['voices'])]);
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

    /**
     * The timed segments of all parts as one list, on the recording's own
     * clock: a segment is kept by the part whose kept stretch holds its
     * middle, so a sentence heard by two parts is kept once.
     *
     * @param  array<int, array<string, mixed>>  $pieces  parts (chunkPlan()) with their 'segments'
     * @return array<int, array{start: float, end: float, speaker: ?string, text: string}>
     */
    public static function stitch(array $pieces): array
    {
        $all = [];
        $last = count($pieces) - 1;
        foreach (array_values($pieces) as $i => $piece) {
            foreach ((array) ($piece['segments'] ?? []) as $s) {
                $start = round((float) $s['start'] + (float) $piece['from'], 2);
                $end = round((float) $s['end'] + (float) $piece['from'], 2);
                $middle = ($start + $end) / 2;
                $inside = ($middle >= (float) $piece['keep_from'] || $i === 0) && ($middle < (float) $piece['keep_to'] || $i === $last);
                if ($inside) {
                    $all[] = ['start' => $start, 'end' => max($start, $end), 'speaker' => $s['speaker'] ?? null, 'text' => (string) $s['text']];
                }
            }
        }
        usort($all, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $all;
    }

    /**
     * Stretches of speech (the recording less its pauses) that no segment
     * covers, at least UNHEARD_SECONDS long, nearby ones joined: at most
     * MAX_UNHEARD of them and MAX_UNHEARD_SHARE of the recording, longest first.
     *
     * @param  array<int, array{start: float, end: float}>  $segments
     * @param  array<int, array{0: float, 1: float}>  $pauses
     * @return array<int, array{0: float, 1: float}>
     */
    public static function unheard(array $segments, array $pauses, float $seconds): array
    {
        // Speech: between the pauses.
        $speech = [];
        $at = 0.0;
        usort($pauses, fn ($a, $b) => $a[0] <=> $b[0]);
        foreach ($pauses as [$start, $end]) {
            if ($start > $at) {
                $speech[] = [$at, min($start, $seconds)];
            }
            $at = max($at, $end);
        }
        if ($at < $seconds) {
            $speech[] = [$at, $seconds];
        }
        // Less what the segments cover (with a little room: their times are a moment off).
        $left = [];
        foreach ($speech as [$from, $to]) {
            $pieces = [[$from, $to]];
            foreach ($segments as $s) {
                $cs = (float) $s['start'] - 0.5;
                $ce = (float) $s['end'] + 0.5;
                $next = [];
                foreach ($pieces as [$a, $b]) {
                    if ($ce <= $a || $cs >= $b) {
                        $next[] = [$a, $b];

                        continue;
                    }
                    if ($cs > $a) {
                        $next[] = [$a, $cs];
                    }
                    if ($ce < $b) {
                        $next[] = [$ce, $b];
                    }
                }
                $pieces = $next;
            }
            array_push($left, ...$pieces);
        }
        // Joined across short pauses, and only the long ones.
        $joined = [];
        foreach ($left as [$a, $b]) {
            if ($joined !== [] && $a - $joined[count($joined) - 1][1] < 1.0) {
                $joined[count($joined) - 1][1] = $b;
            } else {
                $joined[] = [$a, $b];
            }
        }
        $long = array_values(array_filter($joined, fn ($p) => $p[1] - $p[0] >= self::UNHEARD_SECONDS));
        usort($long, fn ($x, $y) => ($y[1] - $y[0]) <=> ($x[1] - $x[0]));
        $kept = [];
        $total = 0.0;
        foreach ($long as $p) {
            if (count($kept) >= self::MAX_UNHEARD || $total + $p[1] - $p[0] > self::MAX_UNHEARD_SHARE * $seconds) {
                break;
            }
            $kept[] = [round($p[0], 2), round($p[1], 2)];
            $total += $p[1] - $p[0];
        }
        usort($kept, fn ($x, $y) => $x[0] <=> $y[0]);

        return $kept;
    }

    /** Whether most of $start-$end is already covered by a segment. */
    private static function covered(array $segments, float $start, float $end): bool
    {
        foreach ($segments as $s) {
            $overlap = min($end, (float) $s['end']) - max($start, (float) $s['start']);
            if ($overlap > 0.5 * max(0.1, $end - $start)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The plain text of all parts as one: where a part was heard with the
     * end of the one before it, the words both give are written once.
     *
     * @param  array<int, array<string, mixed>>  $pieces  parts with their 'text'
     */
    public static function stitchText(array $pieces): string
    {
        $out = '';
        foreach (array_values($pieces) as $i => $piece) {
            $text = trim((string) ($piece['text'] ?? ''));
            if ($i > 0 && $out !== '' && $text !== '') {
                $text = self::withoutRepeat($out, $text);
            }
            $out = trim($out.($out !== '' && $text !== '' ? "\n\n" : '').$text);
        }

        return $out;
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

    /** The segments as text: a line each, with the speaker's letter where there are speakers. */
    public static function textOf(array $segments, bool $speakers): string
    {
        $lines = [];
        $was = null;
        foreach ($segments as $s) {
            $who = $speakers ? (string) ($s['speaker'] ?? '') : '';
            $lines[] = ($who !== '' && $who !== $was ? ($lines !== [] ? "\n" : '').$who.': ' : '').$s['text'];
            $was = $who;
        }

        return trim(implode("\n", $lines));
    }

    /**
     * One part heard by the model. It is asked once more when its answer
     * cannot be read or it would not answer (RECITATION: then with only the
     * first words of each quotation written), and when it wrote far fewer
     * words than the part's speech holds (it now and then writes only the
     * opening of a part): the fuller answer is kept. With 'review' the part
     * is heard once more with that answer, to correct it. Times are from the
     * part's start; $speech: seconds of it that are not pauses.
     *
     * @param  array<string, mixed>  $ask  language, dialect, timings, speakers, part, review, thinking, known
     * @return array{text: string, segments: array<int, array<string, mixed>>, language: string, voices: array<int, array{id: string, voice: string}>, usage: array<string, int>, model: string, finish: array<int, string>, requests: int, recited: bool, notes: array<int, string>}
     */
    private function hear(string $audio, float $length, float $speech, array $ask): array
    {
        $usage = [];
        $finish = [];
        $notes = [];
        $requests = 0;
        $best = null;
        $careful = false;
        $whole = false;
        $sound = ['inline_data' => ['mime_type' => 'audio/mpeg', 'data' => base64_encode($audio)]];
        $schema = self::schema($ask['timings'], $ask['speakers']);
        $take = function (array $prompt) use ($sound, $schema, $ask, &$usage, &$finish, &$requests): array {
            $answer = $this->askModel([$sound, ['text' => self::prompt($prompt + $ask)]], (string) $ask['thinking'], $schema);
            $requests++;
            $finish[] = $answer['finish'];
            foreach ($answer['usage'] as $key => $tokens) {
                $usage[$key] = ($usage[$key] ?? 0) + $tokens;
            }

            return $answer;
        };
        for ($try = 0; $try < 2; $try++) {
            $answer = $take(['careful' => $careful, 'speech' => $whole ? $speech : 0.0]);
            $heard = $answer['data'] !== null ? $this->readPart($answer['data'], $ask, $length) : null;
            if ($heard !== null) {
                $heard['recited'] = $careful;
                if ($best === null || $heard['words'] > $best['words']) {
                    $best = $heard;
                }
                if (! self::tooShort($heard['words'], $speech) || $try === 1) {
                    break;
                }
                $notes[] = sprintf('only %d words for %.0f s of speech (%s): asked again', $heard['words'], $speech, $answer['finish'] ?: 'no finish reason');
                $whole = true;

                continue;
            }
            $notes[] = 'no answer to read ('.($answer['finish'] ?: 'no finish reason').'): '.mb_substr(preg_replace('/\s+/u', ' ', $answer['raw']) ?? '', 0, 160);
            Log::warning('Transcribe: the text model gave no answer to read.', ['finish' => $answer['finish'], 'model' => $answer['model'], 'careful' => $careful]);
            $careful = $careful || $answer['finish'] === 'RECITATION';
        }
        if ($best === null) {
            throw new SpeechNotWrittenOut($finish);
        }
        if (self::tooShort($best['words'], $speech)) {
            $notes[] = sprintf('still only %d words for %.0f s of speech', $best['words'], $speech);
        }

        // A second listening with the draft: kept only when it drops nothing.
        if (($ask['review'] ?? false) && $best['words'] > 0) {
            $draft = $ask['timings'] ? ['language' => $best['language'], 'segments' => $best['segments']] + ($ask['speakers'] ? ['speakers' => $best['voices']] : [])
                : ['language' => $best['language'], 'paragraphs' => preg_split('/\n\n+/u', $best['text']) ?: []];
            $answer = $take(['careful' => $best['recited'], 'draft' => json_encode($draft, JSON_UNESCAPED_UNICODE)]);
            $checked = $answer['data'] !== null ? $this->readPart($answer['data'], $ask, $length) : null;
            if ($checked !== null && $checked['words'] >= 0.9 * $best['words']) {
                $notes[] = sprintf('checked again: %d words, was %d', $checked['words'], $best['words']);
                $best = $checked + ['recited' => $best['recited']];
            } else {
                $notes[] = 'checked again: the check dropped words, the first answer kept';
            }
        }
        unset($best['words']);

        return $best + ['usage' => $usage, 'model' => $answer['model'], 'finish' => $finish, 'requests' => $requests, 'notes' => $notes];
    }

    /** @return array<string, mixed> */
    private function readPart(array $data, array $ask, float $length): array
    {
        $heard = self::read($data, $ask['timings'], $ask['speakers']);
        // The model's times now and then come as minutes and seconds past the first minute.
        $heard['segments'] = $ask['timings'] ? VideoDubber::inSeconds($heard['segments'], $length) : [];
        $heard['words'] = self::wordCount($ask['timings'] ? implode(' ', array_column($heard['segments'], 'text')) : $heard['text']);

        return $heard;
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
     * The answer's shape, so the model keeps to it.
     *
     * @return array<string, mixed>
     */
    public static function schema(bool $timings, bool $speakers): array
    {
        if (! $timings) {
            return ['type' => 'OBJECT', 'properties' => ['language' => ['type' => 'STRING'], 'paragraphs' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']]],
                'required' => ['language', 'paragraphs'], 'propertyOrdering' => ['language', 'paragraphs']];
        }
        $segment = ['start' => ['type' => 'NUMBER'], 'end' => ['type' => 'NUMBER']] + ($speakers ? ['speaker' => ['type' => 'STRING']] : []) + ['text' => ['type' => 'STRING']];
        $properties = ['language' => ['type' => 'STRING']]
            + ($speakers ? ['speakers' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['id' => ['type' => 'STRING'], 'voice' => ['type' => 'STRING']],
                'required' => ['id', 'voice'], 'propertyOrdering' => ['id', 'voice']]]] : [])
            + ['segments' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => $segment, 'required' => array_keys($segment), 'propertyOrdering' => array_keys($segment)]]];

        return ['type' => 'OBJECT', 'properties' => $properties, 'required' => array_keys($properties), 'propertyOrdering' => array_keys($properties)];
    }

    /**
     * What the model is asked. $c: language, dialect, timings, speakers, part
     * (a part of a longer recording), careful (a well-known text recited is
     * written only by its first words: the model will not copy one out in
     * full), speech (seconds of speech, said when an answer came back too
     * short), known (voices heard in earlier parts), draft (an answer to check).
     *
     * @param  array<string, mixed>  $c
     */
    public static function prompt(array $c): string
    {
        $language = (string) ($c['language'] ?? 'auto');
        $timings = (bool) ($c['timings'] ?? false);
        $speakers = (bool) ($c['speakers'] ?? false);
        $named = $language === 'auto' ? '' : self::languageName($language);
        $variety = self::varietyNote((string) ($c['dialect'] ?? ''));
        $arabic = in_array($language, ['ar', 'auto'], true);
        $draft = (string) ($c['draft'] ?? '');

        $p = "You are a professional transcriber. Write out exactly what is said in this audio, every word, as it is spoken.\n"
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
            .'Write numbers as digits when said as numbers, and a phone number said digit by digit as one number (01012345678). Add normal punctuation. '
            .'Where a word cannot be made out, write [inaudible]. Do not describe music or noises.'."\n"
            .(($c['careful'] ?? false)
                ? "Where the speaker recites a well-known text (a verse of the Quran, a prayer, a poem, song lyrics), write only its first three words followed by \"…\".\n"
                : "Quotations (verses of the Quran, prayers, poems, songs) are written as heard too.\n");

        if ($draft !== '') {
            $p .= "Here is a first transcript of this audio:\n{$draft}\n"
                .'Listen to the whole audio again, from start to end, and return it corrected in the same JSON: fix every misheard word, add anything missing, '
                .'remove anything that was not said, put back any dialect word that was written in its Standard form, and fix the times'
                .($speakers ? ' and the speaker letters' : '').' where they are wrong. Keep everything that is right as it is.'."\n";
        }

        if (! $timings) {
            return $p.'Split the text into paragraphs where the topic or the speaker changes. '
                .'Return only JSON: {"language": "<ISO 639-1 code of the main language spoken>", "paragraphs": ["..."]}. '
                .'If nothing is spoken, return {"language": "", "paragraphs": []}.';
        }

        $known = array_filter((array) ($c['known'] ?? []), fn ($v) => is_array($v) && ($v['id'] ?? '') !== '');

        return $p.'Split what is said into segments at sentence or phrase boundaries, each 2 to 15 seconds long'
            .($speakers ? ' and spoken by one person' : '')
            .', with its start and end in seconds from the start of this audio, as plain seconds also past the first minute (75.4, never 1:15.4 or 115.4). '
            .'Cover everything that is said, from the first word to the last; a pause in the speech is never the end of it.'
            .($speakers
                ? ' Tell the speakers apart by their voices: give each a letter (A, B, ...) in the order they first speak, and in "speakers" describe each voice in a few words '
                    .'(gender, pitch, manner) so it can be told apart later. One person keeps one letter all through; give a new letter only when a clearly different voice speaks. '
                    .'One person reading or talking alone is A all through.'
                    .($known !== [] ? ' Voices heard earlier in this recording: '.implode('; ', array_map(fn ($v) => $v['id'].': '.$v['voice'], $known))
                        .'. Give the same letter to the same voice here; a voice not among them gets the next letter.' : '')
                : '')
            .' Return only JSON: {"language": "<ISO 639-1 code of the main language spoken>", '.($speakers ? '"speakers": [{"id": "A", "voice": "..."}], ' : '')
            .'"segments": [{"start": 0.0, "end": 3.2'.($speakers ? ', "speaker": "A"' : '').', "text": "..."}]}. '
            .'If nothing is spoken, return {"language": "", '.($speakers ? '"speakers": [], ' : '').'"segments": []}.';
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
            .'Maghrebi: بزاف، واش، دابا، ديال، كيفاش، مزيان، والو، شحال، برشا، توا، باهي، ياسر، نتا، نتي، French words in Latin letters as said (normal, ça va). '
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
     * The model's answer made safe: paragraphs of text, or segments with a
     * start, an end not before it, words, and a speaker letter where asked.
     *
     * @param  array<string, mixed>  $data
     * @return array{text: string, segments: array<int, array{start: float, end: float, speaker: ?string, text: string}>, language: string, voices: array<int, array{id: string, voice: string}>}
     */
    public static function read(array $data, bool $timings, bool $speakers): array
    {
        if (array_is_list($data)) {
            $data = $timings ? ['segments' => $data] : ['paragraphs' => $data];
        }
        $language = strtolower(substr(preg_replace('/[^a-zA-Z-]/', '', (string) (is_scalar($data['language'] ?? null) ? $data['language'] : '')) ?? '', 0, 8));
        $language = explode('-', $language)[0];
        $voices = [];
        foreach ((array) ($data['speakers'] ?? []) as $v) {
            $id = is_array($v) && is_scalar($v['id'] ?? null) ? mb_substr(trim((string) $v['id']), 0, 8) : '';
            if ($speakers && $id !== '') {
                $voices[] = ['id' => $id, 'voice' => mb_substr(trim(is_scalar($v['voice'] ?? null) ? (string) $v['voice'] : ''), 0, 120)];
            }
        }
        if (! $timings) {
            $paragraphs = array_values(array_filter(array_map(fn ($p) => is_scalar($p) ? trim((string) $p) : (is_array($p) && is_scalar($p['text'] ?? null) ? trim((string) $p['text']) : ''),
                (array) ($data['paragraphs'] ?? $data['segments'] ?? [])), 'strlen'));
            foreach (['text', 'transcript'] as $key) {
                if ($paragraphs === [] && is_scalar($data[$key] ?? null)) {
                    $paragraphs = [trim((string) $data[$key])];
                }
            }

            return ['text' => implode("\n\n", $paragraphs), 'segments' => [], 'language' => $language, 'voices' => []];
        }

        $segments = [];
        foreach ((array) ($data['segments'] ?? []) as $s) {
            $text = is_array($s) && is_scalar($s['text'] ?? null) ? trim((string) $s['text']) : '';
            $start = is_array($s) ? self::seconds($s['start'] ?? null) : null;
            if ($text === '' || $start === null) {
                continue;
            }
            $end = self::seconds($s['end'] ?? null) ?? $start;
            $speaker = $speakers ? (mb_substr(trim(is_scalar($s['speaker'] ?? null) ? (string) $s['speaker'] : ''), 0, 8) ?: 'A') : null;
            $segments[] = ['start' => max(0.0, $start), 'end' => max($start, $end), 'speaker' => $speaker, 'text' => $text];
        }
        usort($segments, fn ($a, $b) => $a['start'] <=> $b['start']);

        return ['text' => '', 'segments' => $segments, 'language' => $language, 'voices' => $voices];
    }

    /** A time as seconds: a number, or "1:15.4" / "0:01:15.4" written as a clock; null when it is neither. */
    public static function seconds(mixed $t): ?float
    {
        if (is_int($t) || is_float($t) || (is_string($t) && is_numeric(trim($t)))) {
            return (float) $t;
        }
        if (is_string($t) && preg_match('/^\s*(?:(\d+):)?(\d+):(\d+(?:[.,]\d+)?)\s*s?\s*$/', $t, $m) === 1) {
            return (int) $m[1] * 3600 + (int) $m[2] * 60 + (float) str_replace(',', '.', $m[3]);
        }

        return null;
    }

    private static function clock(float $t): string
    {
        return sprintf('%d:%02d', intdiv((int) $t, 60), (int) $t % 60);
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
