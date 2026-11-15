<?php

namespace App\Services\Transcription;

use App\Services\Dubbing\SpeechNotWrittenOut;
use App\Services\Dubbing\VideoDubber;
use App\Services\Tts\GeminiKeyManager;
use App\Support\AudioFormats;
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
 * Arabic is written in the speaker's own dialect, as said: never rewritten
 * into Modern Standard Arabic. A well-known text the speaker recites (a verse,
 * a poem, a song) the model may refuse to copy out (finish RECITATION, see
 * SpeechNotWrittenOut): the part is then asked for once more with only the
 * first words of each quotation written, and the result says so ('recited').
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

    public function __construct(private GeminiKeyManager $keys) {}

    /**
     * $o: language ('auto' or a code such as 'ar', 'fr'), timings (bool),
     * speakers (bool, implies timings), thinking ('low', 'minimal', ''),
     * progress (fn (int $done, int $parts)).
     *
     * @param  array<string, mixed>  $o
     * @return array{text: string, segments: array<int, array{start: float, end: float, speaker: ?string, text: string}>, language: string, seconds: float, parts: int, requests: int, recited: bool, usage: array<string, int>, model: string, finish: array<int, string>, timings: bool, speakers: bool}
     */
    public function transcribe(string $file, array $o = []): array
    {
        $ffmpeg = AudioFormats::ffmpegPath();
        if (! $ffmpeg) {
            throw new \RuntimeException('ffmpeg is not available.');
        }
        $speakers = (bool) ($o['speakers'] ?? false);
        $timings = $speakers || (bool) ($o['timings'] ?? false);
        $language = strtolower(trim((string) ($o['language'] ?? 'auto'))) ?: 'auto';
        $progress = $o['progress'] ?? static function (): void {};

        $tmp = sys_get_temp_dir().'/listen-transcribe-'.bin2hex(random_bytes(6));
        @mkdir($tmp, 0700, true);
        try {
            $clean = $tmp.'/clean.mp3';
            $this->normalise($ffmpeg, $file, $clean);
            $seconds = $this->duration($ffmpeg, $clean);
            $plan = self::chunkPlan($this->pauses($ffmpeg, $clean), $seconds);

            $usage = ['input_tokens' => 0, 'audio_tokens' => 0, 'output_tokens' => 0, 'thinking_tokens' => 0];
            $finish = [];
            $model = '';
            $requests = 0;
            $recited = false;
            $detected = [];
            $pieces = [];
            foreach ($plan as $n => $part) {
                $audio = $tmp.'/part-'.$n.'.mp3';
                $this->cut($ffmpeg, $clean, $part['from'], $part['to'] - $part['from'], $audio);
                $heard = $this->hear((string) file_get_contents($audio), $part['to'] - $part['from'], $language, $timings, $speakers, count($plan) > 1, (string) ($o['thinking'] ?? 'low'));
                foreach ($heard['usage'] as $key => $tokens) {
                    $usage[$key] = ($usage[$key] ?? 0) + $tokens;
                }
                $finish = array_merge($finish, $heard['finish']);
                $model = $heard['model'];
                $requests += $heard['requests'];
                $recited = $recited || $heard['recited'];
                if ($heard['language'] !== '') {
                    $detected[$heard['language']] = ($detected[$heard['language']] ?? 0) + ($part['to'] - $part['from']);
                }
                $pieces[] = $part + $heard;
                $progress($n + 1, count($plan));
            }
        } finally {
            foreach ((array) glob($tmp.'/*') as $f) {
                @unlink((string) $f);
            }
            @rmdir($tmp);
        }

        arsort($detected);
        $segments = $timings ? self::stitch($pieces) : [];
        $text = $timings ? self::textOf($segments, $speakers) : self::stitchText($pieces);

        return [
            'text' => $text,
            'segments' => $segments,
            'language' => $language !== 'auto' ? $language : (string) (array_key_first($detected) ?? ''),
            'seconds' => round($seconds, 2),
            'parts' => count($plan),
            'requests' => $requests,
            'recited' => $recited,
            'usage' => $usage,
            'model' => $model,
            'finish' => $finish,
            'timings' => $timings,
            'speakers' => $speakers,
        ];
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
                $inside = $middle >= (float) $piece['keep_from'] && ($middle < (float) $piece['keep_to'] || $i === $last);
                if ($inside) {
                    $all[] = ['start' => $start, 'end' => max($start, $end), 'speaker' => $s['speaker'] ?? null, 'text' => (string) $s['text']];
                }
            }
        }
        usort($all, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $all;
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
     * One part heard by the model, asked once more with quotations shortened
     * when it would not answer (RECITATION). Times are from the part's start.
     *
     * @return array{text: string, segments: array<int, array<string, mixed>>, language: string, usage: array<string, int>, model: string, finish: array<int, string>, requests: int, recited: bool}
     */
    private function hear(string $audio, float $length, string $language, bool $timings, bool $speakers, bool $part, string $thinking): array
    {
        $usage = [];
        $finish = [];
        $requests = 0;
        foreach ([false, true] as $careful) {
            $answer = $this->askModel([['inline_data' => ['mime_type' => 'audio/mpeg', 'data' => base64_encode($audio)]],
                ['text' => self::prompt($language, $timings, $speakers, $part, $careful)]], $thinking);
            $requests++;
            $finish[] = $answer['finish'];
            foreach ($answer['usage'] as $key => $tokens) {
                $usage[$key] = ($usage[$key] ?? 0) + $tokens;
            }
            if ($answer['data'] !== null) {
                $heard = self::read($answer['data'], $timings, $speakers);
                // The model's times now and then come as minutes and seconds past the first minute.
                $heard['segments'] = $timings ? VideoDubber::inSeconds($heard['segments'], $length) : [];

                return $heard + ['usage' => $usage, 'model' => $answer['model'], 'finish' => $finish, 'requests' => $requests, 'recited' => $careful];
            }
            Log::warning('Transcribe: the text model gave no answer to read.', ['finish' => $answer['finish'], 'model' => $answer['model'], 'careful' => $careful]);
        }

        throw new SpeechNotWrittenOut($finish);
    }

    /**
     * What the model is asked. $careful: a well-known text recited is written
     * only by its first words (the model will not copy one out in full).
     */
    public static function prompt(string $language, bool $timings, bool $speakers, bool $part, bool $careful = false): string
    {
        $named = $language === 'auto' ? '' : self::languageName($language);
        $p = "You are a professional transcriber. Write out exactly what is said in this audio, every word, as it is spoken.\n"
            .($part ? "This audio is one part cut out of a longer recording: it may start or end in the middle of a word; write only the words you can hear in it.\n" : '')
            .($named !== ''
                ? "The audio is in {$named}. Write it in {$named}, in that language's own script. Words the speaker says in another language are written in that language.\n"
                : "Find the language spoken and write it in that language's own script. Words the speaker says in another language are written in that language.\n")
            ."Never translate, correct, polish, sum up or leave out anything. Keep the speaker's own words, dialect, slang and grammar. "
            .'Arabic is written exactly in the dialect spoken (Egyptian, Gulf, Levantine, Maghrebi, ...), the way its speakers write it in messages: '
            .'never rewrite it into Modern Standard Arabic and never add diacritics the speaker did not say. '
            .'Leave out pure hesitation sounds (um, uh, ehh) and false starts of one syllable; keep every real word. '
            .'Write numbers as digits when said as numbers. Add normal punctuation. Where a word cannot be made out, write [inaudible]. '
            .'Do not describe music or noises.'."\n"
            .($careful
                ? "Where the speaker recites a well-known text (a verse of the Quran, a prayer, a poem, song lyrics), write only its first three words followed by \"…\".\n"
                : "Quotations (verses of the Quran, prayers, poems, songs) are written as heard too.\n");

        if (! $timings) {
            return $p.'Split the text into paragraphs where the topic or the speaker changes. '
                .'Return only JSON: {"language": "<ISO 639-1 code of the main language spoken>", "paragraphs": ["..."]}. '
                .'If nothing is spoken, return {"language": "", "paragraphs": []}.';
        }

        return $p.'Split what is said into segments at sentence or phrase boundaries, each 2 to 15 seconds long'
            .($speakers ? ' and spoken by one person' : '')
            .', with its start and end in seconds from the start of this audio, as plain seconds also past the first minute (75.4, never 1:15.4 or 115.4). '
            .'Cover everything that is said, from the first word to the last.'
            .($speakers ? ' Tell the speakers apart: give each a letter (A, B, ...) in the order they first speak; one person keeps one letter all through.' : '')
            .' Return only JSON: {"language": "<ISO 639-1 code of the main language spoken>", "segments": [{"start": 0.0, "end": 3.2'
            .($speakers ? ', "speaker": "A"' : '').', "text": "..."}]}. If nothing is spoken, return {"language": "", "segments": []}.';
    }

    /**
     * The model's answer made safe: paragraphs of text, or segments with a
     * start, an end not before it, words, and a speaker letter where asked.
     *
     * @param  array<string, mixed>  $data
     * @return array{text: string, segments: array<int, array{start: float, end: float, speaker: ?string, text: string}>, language: string}
     */
    public static function read(array $data, bool $timings, bool $speakers): array
    {
        $language = strtolower(substr(preg_replace('/[^a-zA-Z-]/', '', (string) (is_scalar($data['language'] ?? null) ? $data['language'] : '')) ?? '', 0, 8));
        $language = explode('-', $language)[0];
        if (! $timings) {
            $paragraphs = array_values(array_filter(array_map(fn ($p) => is_scalar($p) ? trim((string) $p) : '', (array) ($data['paragraphs'] ?? [])), 'strlen'));
            if ($paragraphs === [] && is_scalar($data['text'] ?? null)) {
                $paragraphs = [trim((string) $data['text'])];
            }

            return ['text' => implode("\n\n", $paragraphs), 'segments' => [], 'language' => $language];
        }

        $segments = [];
        foreach ((array) ($data['segments'] ?? []) as $s) {
            $text = is_array($s) && is_scalar($s['text'] ?? null) ? trim((string) $s['text']) : '';
            if ($text === '' || ! is_numeric($s['start'] ?? null)) {
                continue;
            }
            $start = max(0.0, (float) $s['start']);
            $speaker = $speakers ? (mb_substr(trim(is_scalar($s['speaker'] ?? null) ? (string) $s['speaker'] : ''), 0, 8) ?: 'A') : null;
            $segments[] = ['start' => $start, 'end' => max($start, is_numeric($s['end'] ?? null) ? (float) $s['end'] : $start), 'speaker' => $speaker, 'text' => $text];
        }
        usort($segments, fn ($a, $b) => $a['start'] <=> $b['start']);

        return ['text' => '', 'segments' => $segments, 'language' => $language];
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
     * what it used, which model gave it and why it stopped.
     *
     * @param  array<int, array<string, mixed>>  $parts
     * @return array{data: ?array<string, mixed>, usage: array<string, int>, model: string, finish: string}
     */
    private function askModel(array $parts, string $thinking): array
    {
        $base = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $body = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0]
                + ($thinking !== '' ? ['thinkingConfig' => ['thinkingLevel' => $thinking]] : []),
        ];
        $last = 'no key';
        foreach (VideoDubber::MODELS as $model) {
            foreach ($this->keys->keysInOrder($model) as $key) {
                $response = Http::timeout(240)->withHeaders(['x-goog-api-key' => $key['key']])->post($base.'/models/'.$model.':generateContent', $body);
                if ($response->status() === 400 && str_contains(strtolower($response->body()), 'thinking')) {
                    unset($body['generationConfig']['thinkingConfig']);
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

                return ['data' => is_array($data) ? $data : null, 'usage' => VideoDubber::usage((array) $response->json('usageMetadata')), 'model' => $model,
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
