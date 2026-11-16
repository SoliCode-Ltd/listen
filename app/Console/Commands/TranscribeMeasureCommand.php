<?php

namespace App\Console\Commands;

use App\Services\Transcription\Transcriber;
use App\Support\ArabicDialects;
use App\Support\AudioFormats;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * Measures what speech to text ("Transcribe") costs and how well it hears,
 * on STAGING: it writes out test recordings with
 * App\Services\Transcription\Transcriber (the dubbing's text model and keys)
 * and prints, for each, the model's tokens, US dollars per audio minute at
 * the dubbing's prices (listen.dubbing.prices), the first lines of what it
 * wrote, and the word and character error rates where the words are known.
 *
 * Three sets (--set):
 *  - default: 14 recordings (Listen's own samples, and public-domain human
 *    readings from LibriVox on archive.org in Arabic, English, French,
 *    Spanish, German and Urdu).
 *  - dialects: every Arabic dialect and English accent the site has, from
 *    the voice library's previews and the homepage samples (whose lines are
 *    known): one file per country (Arabic) or accent group (English), its
 *    clips 2.5 s apart, so each clip is scored on its own too, and the
 *    sentence times are checked against where each clip really starts.
 *  - languages: the previews spoken natively in other languages (French,
 *    Spanish, German, Italian, Portuguese, Dutch, Turkish, Russian, Hindi,
 *    Japanese, Korean, Chinese).
 * Every recording is written out once for each --variants
 * entry, to compare what makes it more accurate: "auto" (the language
 * detected, as the page's default), "lang" (the language given), and with
 * "+dialect" (the dialect or accent named), "+review" (each part heard again
 * with its draft) or "+think" (the model thinks longer), e.g. lang+dialect+review.
 *
 * Listen's own voices are clean studio speech, so they flatter accuracy;
 * the human readings are the fairer test.
 *
 * Read-only: it saves nothing in the database and makes no sound. It only
 * sends the recordings to the text model, on the Google account staging
 * shares with live, so it runs by hand only, when the owner has said yes. It
 * refuses to run on the live site. The full report (every transcript) is
 * written to storage/app/transcribe-measure/<run>/report.json.
 *
 *   php artisan transcribe:measure
 *   php artisan transcribe:measure --set=dialects,languages --variants=auto,lang+dialect,lang+dialect+review
 *   php artisan transcribe:measure --only=ar-eg,fr-human --variants=lang
 *   php artisan transcribe:measure --file="tr-human|tr|https://example.org/turkish.mp3|300"
 */
class TranscribeMeasureCommand extends Command
{
    protected $signature = 'transcribe:measure
        {--set=default : Which recordings: default, dialects, languages, or several (comma separated), or all}
        {--variants=lang : The ways to ask, compared (auto, lang, +dialect, +review, +think)}
        {--only= : Only these recordings, by label (comma separated; dia-ar-* for every label starting so)}
        {--file=* : An extra recording: "label|language|path or URL|longest seconds" (language "auto" to detect)}
        {--thinking=low : How much the model thinks first (low, minimal, or empty for its own); +think asks for high}
        {--full : Print every plain-text transcript in full at the end}
        {--prepare-only : Only make and time the test files: nothing is sent to the model}';

    protected $description = 'Write out test recordings and measure what speech to text costs and how accurate it is (staging only).';

    private const ARCHIVE = 'https://archive.org/download/';

    /** Seconds of silence between the clips of a dialect file. */
    private const CLIP_GAP = 2.5;

    /** English accent groups of their own; every other accent is in "en-world". */
    private const ENGLISH_GROUPS = ['en-US', 'en-GB', 'en-IE', 'en-AU', 'en-NZ', 'en-CA', 'en-ZA', 'en-IN'];

    public function handle(Transcriber $transcriber): int
    {
        if (str_contains(strtolower((string) config('app.url')), (string) config('listen.live_host', 'listenai.studio'))
            || filter_var(config('listen.live_actions'), FILTER_VALIDATE_BOOLEAN)) {
            $this->error('This is the live site: transcribe:measure runs on staging only.');

            return self::FAILURE;
        }
        $ffmpeg = AudioFormats::ffmpegPath();
        if (! $ffmpeg) {
            $this->error('ffmpeg is not available.');

            return self::FAILURE;
        }

        $run = now()->utc()->format('Ymd-His');
        $dir = storage_path('app/transcribe-measure/'.$run);
        @mkdir($dir, 0755, true);
        $variants = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('variants')))));
        $sets = array_map('trim', explode(',', strtolower((string) $this->option('set'))));
        if (in_array('all', $sets, true)) {
            $sets = ['default', 'dialects', 'languages'];
        }
        $recordings = array_merge(
            in_array('default', $sets, true) ? self::recordings() : [],
            in_array('dialects', $sets, true) ? self::dialectRecordings() : [],
            in_array('languages', $sets, true) ? self::languageRecordings() : [],
            $this->extraRecordings());
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        if ($only !== []) {
            // A label ending in * is a prefix (dia-ar-*: every Arabic country file).
            $recordings = array_values(array_filter($recordings, fn (array $r) => array_filter($only,
                fn (string $o) => str_ends_with($o, '*') ? str_starts_with($r['label'], rtrim($o, '*')) : $r['label'] === $o) !== []));
        }
        $prices = (array) config('listen.dubbing.prices', []);
        $this->line(sprintf('Run %s: %d recording(s) from %s, asked as %s; thinking %s. Prices a million tokens: audio in $%.2f, text in $%.2f, out $%.2f.',
            $run, count($recordings), implode(', ', $sets), implode(' | ', $variants), $this->option('thinking') ?: 'default',
            $prices['audio_in'] ?? 0, $prices['text_in'] ?? 0, $prices['text_out'] ?? 0));

        $results = [];
        foreach ($recordings as $r) {
            try {
                [$file, $windows] = $this->prepare($ffmpeg, $r, $dir, $transcriber);
            } catch (\Throwable $e) {
                $this->warn(sprintf('%-18s could not be prepared: %s', $r['label'], mb_substr($e->getMessage(), 0, 200)));
                $results[] = ['label' => $r['label'], 'error' => $e->getMessage()];

                continue;
            }
            $this->newLine();
            $this->line(sprintf('■ %s (%s%s, %s) — %s', $r['label'], $r['language'], $r['dialect'] !== '' ? ' '.$r['dialect'] : '', $r['kind'], $r['about']));
            if ($this->option('prepare-only')) {
                $this->line(sprintf('  ready: %.2f min, %d clip(s), %d KB, words known: %s', $transcriber->duration($ffmpeg, $file) / 60, count($r['parts']), filesize($file) / 1024,
                    $r['reference'] !== null ? count(self::words($r['reference'])) : 'no'));
                $results[] = ['label' => $r['label'], 'prepared' => true];

                continue;
            }
            $ways = array_map(fn ($v) => ['name' => $v, 'ask' => self::variant($v, $r)], $variants);
            foreach ($ways as $way) {
                $t = microtime(true);
                try {
                    $out = $transcriber->transcribe($file, $way['ask'] + ['thinking' => (string) $this->option('thinking')]);
                } catch (\Throwable $e) {
                    $this->warn(sprintf('  %-22s FAILED: %s', $way['name'], mb_substr($e->getMessage(), 0, 300)));
                    $results[] = ['label' => $r['label'], 'way' => $way['name'], 'error' => $e->getMessage()];

                    continue;
                }
                $minutes = max(0.01, $out['seconds'] / 60);
                $cost = Transcriber::cost($out['usage'], $prices);
                $u = $out['usage'];
                $errors = $r['reference'] !== null ? self::errorRates($r['reference'], $out['text']) : null;
                $clips = $r['clips'] !== [] && $out['segments'] !== [] ? self::clipScores($r, $out['segments']) : [];
                $timing = $windows !== [] ? self::timing($windows, $out['segments']) : null;
                $this->line(sprintf('  %-22s %5.2f min  %d part(s) %d req  in %6d (audio %6d) out %5d think %5d  $%.4f = $%.4f/min  %4.0fs%s%s  lang %s  speakers %d%s',
                    $way['name'], $minutes, $out['parts'], $out['requests'], $u['input_tokens'], $u['audio_tokens'], $u['output_tokens'], $u['thinking_tokens'],
                    $cost, $cost / $minutes, microtime(true) - $t,
                    $errors !== null ? sprintf('  WER %4.1f%% CER %4.1f%%', $errors['wer'] * 100, $errors['cer'] * 100) : '',
                    $timing !== null ? sprintf('  clip starts %d/%d within 1 s (off %.1f s on average)', $timing['within'], $timing['clips'], $timing['mean']) : '',
                    $out['language'] ?: '?', $out['speakers'], $out['recited'] ? '  RECITATION: quotations shortened' : ''));
                $this->line('      '.self::firstLines($out, 320));
                foreach ($out['notes'] as $note) {
                    $this->line('      note: '.$note);
                }
                $results[] = ['label' => $r['label'], 'set' => $r['set'], 'language' => $r['language'], 'dialect' => $r['dialect'], 'kind' => $r['kind'],
                    'way' => $way['name'], 'timing' => $timing, 'seconds' => $out['seconds'], 'parts' => $out['parts'], 'requests' => $out['requests'], 'usage' => $u,
                    'cost' => round($cost, 6), 'cost_per_minute' => round($cost / $minutes, 6), 'errors' => $errors, 'clips' => $clips, 'detected' => $out['language'],
                    'speakers' => $out['speakers'], 'recited' => $out['recited'], 'model' => $out['model'], 'finish' => $out['finish'], 'notes' => $out['notes'],
                    'text' => $out['text'], 'paragraphs' => $out['paragraphs'], 'segments' => $out['segments']];
            }
        }

        $this->summary($results);
        if ($this->option('full')) {
            $this->newLine();
            $this->line('Full transcripts ('.($variants[0] ?? '').'):');
            foreach ($results as $res) {
                if (($res['way'] ?? '') === ($variants[0] ?? '') && isset($res['text'])) {
                    $this->newLine();
                    $this->line('■ '.$res['label']);
                    $this->line($res['text']);
                }
            }
        }
        file_put_contents($dir.'/report.json', json_encode(['run' => $run, 'prices' => $prices, 'results' => $results],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->line('Report: '.$dir.'/report.json');

        return array_filter($results, fn ($r) => ! isset($r['error'])) === [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * What a --variants entry asks: "auto" or "lang" first, then any of
     * "+dialect", "+review", "+think".
     *
     * @return array<string, mixed>
     */
    public static function variant(string $name, array $r): array
    {
        $bits = array_map('trim', explode('+', strtolower($name)));
        $ask = ['language' => in_array('auto', $bits, true) ? 'auto' : $r['language']];
        if (in_array('dialect', $bits, true) && $r['dialect'] !== '') {
            $ask['dialect'] = $r['dialect'];
        }
        if (in_array('review', $bits, true)) {
            $ask['review'] = true;
        }
        if (in_array('think', $bits, true)) {
            $ask['thinking'] = 'high';
        }

        return $ask;
    }

    /**
     * Each clip of a joined file scored on its own: the transcript's words
     * lined up with the clips' words (an edit-distance alignment, so the
     * model's times, which drift, do not matter), each clip taking the words
     * lined up with its own.
     *
     * @param  array<int, array{text: string}>  $segments
     * @return array<int, array{slug: string, dialect: string, wer: float, cer: float, words: int, said: string, heard: string}>
     */
    public static function clipScores(array $r, array $segments): array
    {
        $ref = [];
        $owner = [];
        foreach ($r['clips'] as $i => $clip) {
            foreach (self::words($clip['said']) as $w) {
                $ref[] = $w;
                $owner[] = $i;
            }
        }
        $hyp = self::words(implode(' ', array_column($segments, 'text')));
        $n = count($ref);
        $m = count($hyp);
        if ($n === 0) {
            return [];
        }
        // Edit distance with the way back.
        $d = [range(0, $m)];
        for ($i = 1; $i <= $n; $i++) {
            $d[$i] = [$i];
            for ($j = 1; $j <= $m; $j++) {
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + ($ref[$i - 1] === $hyp[$j - 1] ? 0 : 1));
            }
        }
        $heard = array_fill(0, count($r['clips']), []);
        $i = $n;
        $j = $m;
        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $d[$i][$j] === $d[$i - 1][$j - 1] + ($ref[$i - 1] === $hyp[$j - 1] ? 0 : 1)) {
                array_unshift($heard[$owner[$i - 1]], $hyp[$j - 1]);
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $d[$i][$j] === $d[$i][$j - 1] + 1)) {
                // A word not in the clips: it goes with the clip it sits in.
                array_unshift($heard[$owner[max(0, $i - 1)]], $hyp[$j - 1]);
                $j--;
            } else {
                $i--;
            }
        }
        $scores = [];
        foreach ($r['clips'] as $k => $clip) {
            if ($clip['said'] === '') {
                continue;
            }
            $text = implode(' ', $heard[$k]);
            $scores[] = ['slug' => $clip['slug'], 'dialect' => $clip['dialect']] + self::errorRates($clip['said'], $text) + ['said' => $clip['said'], 'heard' => $text];
        }

        return $scores;
    }

    /**
     * How well the sentence times fit the sound: each clip of a joined file
     * starts after a pause, so a sentence should start there; how many clip
     * starts have one within a second, and how far off the nearest is on average.
     *
     * @param  array<int, array{0: float, 1: float}>  $windows
     * @param  array<int, array{start: float}>  $segments
     * @return array{clips: int, within: int, mean: float}|null
     */
    public static function timing(array $windows, array $segments): ?array
    {
        $starts = array_map(fn ($s) => (float) $s['start'], $segments);
        if ($starts === [] || count($windows) < 2) {
            return null;
        }
        $offs = [];
        foreach (array_slice($windows, 1) as [$from]) {
            $offs[] = min(array_map(fn ($t) => abs($t - $from), $starts));
        }

        return ['clips' => count($offs), 'within' => count(array_filter($offs, fn ($o) => $o <= 1.0)), 'mean' => round(array_sum($offs) / count($offs), 2)];
    }

    /** @param  array<int, array<string, mixed>>  $results */
    private function summary(array $results): void
    {
        $done = array_values(array_filter($results, fn ($r) => ! isset($r['error']) && isset($r['way'])));
        if ($done === []) {
            return;
        }
        $this->newLine();
        $this->line('Per audio minute, by way of asking (the planning figure is $0.005; above $0.008 the allowances are cut by a third):');
        foreach (array_unique(array_column($done, 'way')) as $way) {
            $rows = array_values(array_filter($done, fn ($r) => $r['way'] === $way));
            $minutes = array_sum(array_column($rows, 'seconds')) / 60;
            $sum = fn (string $key) => array_sum(array_map(fn ($r) => (int) $r['usage'][$key], $rows));
            $per = array_column($rows, 'cost_per_minute');
            $this->line(sprintf('  %-24s $%.4f/min (cheapest $%.4f, dearest $%.4f) over %.1f min; tokens a minute: audio %d, other in %d, out %d, thinking %d; %d request(s)',
                $way, array_sum(array_column($rows, 'cost')) / $minutes, min($per), max($per), $minutes,
                $sum('audio_tokens') / $minutes, ($sum('input_tokens') - $sum('audio_tokens')) / $minutes, $sum('output_tokens') / $minutes, $sum('thinking_tokens') / $minutes,
                array_sum(array_column($rows, 'requests'))));
        }

        $timed = array_filter($done, fn ($r) => $r['timing'] !== null);
        if ($timed !== []) {
            $this->newLine();
            $this->line('Sentence times against where each clip starts (joined files):');
            foreach (array_unique(array_column($done, 'way')) as $way) {
                $rows = array_filter($timed, fn ($r) => $r['way'] === $way);
                $clips = array_sum(array_map(fn ($r) => $r['timing']['clips'], $rows));
                $this->line(sprintf('  %-24s %d of %d clip starts within 1 s; off %.2f s on average', $way, array_sum(array_map(fn ($r) => $r['timing']['within'], $rows)), $clips,
                    $clips > 0 ? array_sum(array_map(fn ($r) => $r['timing']['mean'] * $r['timing']['clips'], $rows)) / $clips : 0));
            }
        }
        $this->newLine();
        $this->line('Accuracy where the words are known, WER / CER (lower is better):');
        $ways = array_values(array_unique(array_column($done, 'way')));
        $this->line(sprintf('  %-20s %s', '', implode('', array_map(fn ($w) => str_pad(mb_substr($w, 0, 21), 22), $ways))));
        $labels = array_values(array_unique(array_column(array_filter($done, fn ($r) => $r['errors'] !== null), 'label')));
        $totals = [];
        foreach ($labels as $label) {
            $cells = [];
            foreach ($ways as $way) {
                $row = current(array_filter($done, fn ($r) => $r['label'] === $label && $r['way'] === $way && $r['errors'] !== null));
                if ($row) {
                    $cells[] = str_pad(sprintf('%5.1f%% / %4.1f%%', $row['errors']['wer'] * 100, $row['errors']['cer'] * 100), 22);
                    if ($row['set'] === 'dialects') {
                        $totals[$way]['errors'] = ($totals[$way]['errors'] ?? 0) + $row['errors']['wer'] * $row['errors']['words'];
                        $totals[$way]['words'] = ($totals[$way]['words'] ?? 0) + $row['errors']['words'];
                    }
                } else {
                    $cells[] = str_pad('-', 22);
                }
            }
            $this->line(sprintf('  %-20s %s', mb_substr($label, 0, 20), implode('', $cells)));
        }
        if ($totals !== []) {
            $this->line(sprintf('  %-20s %s', 'all dialects (WER)', implode('', array_map(fn ($w) => str_pad(isset($totals[$w]) ? sprintf('%5.1f%%', 100 * $totals[$w]['errors'] / max(1, $totals[$w]['words'])) : '-', 22), $ways))));
            asort($totals);
            $best = (string) array_key_first(array_map(fn ($t) => $t['errors'] / max(1, $t['words']), $totals) + []);
            $byRate = array_map(fn ($t) => $t['errors'] / max(1, $t['words']), $totals);
            asort($byRate);
            $best = (string) array_key_first($byRate);
            $clips = [];
            foreach ($done as $r) {
                if ($r['way'] === $best) {
                    array_push($clips, ...$r['clips']);
                }
            }
            usort($clips, fn ($a, $b) => $b['wer'] <=> $a['wer']);
            $this->newLine();
            $this->line(sprintf('The clips heard worst with "%s" (%d clips scored):', $best, count($clips)));
            foreach (array_slice($clips, 0, 25) as $c) {
                $this->line(sprintf('  %-10s %-16s WER %5.1f%% CER %5.1f%%', $c['dialect'], $c['slug'], $c['wer'] * 100, $c['cer'] * 100));
                $this->line('      said:  '.mb_substr((string) preg_replace('/\s+/u', ' ', $c['said']), 0, 200));
                $this->line('      heard: '.mb_substr((string) preg_replace('/\s+/u', ' ', $c['heard']), 0, 200));
            }
        }
        $failed = array_filter($results, fn ($r) => isset($r['error']));
        if ($failed !== []) {
            $this->line('Failed: '.implode(', ', array_unique(array_map(fn ($r) => $r['label'].(isset($r['way']) ? ' ('.$r['way'].')' : ''), $failed))));
        }
    }

    /** The opening of a transcript on one line: the text, or the first timed segments. */
    private static function firstLines(array $out, int $chars): string
    {
        if ($out['segments'] !== []) {
            $bits = [];
            foreach (array_slice($out['segments'], 0, 4) as $s) {
                $bits[] = sprintf('[%s-%s%s] %s', self::clock($s['start']), self::clock($s['end']), $s['speaker'] ? ' '.$s['speaker'] : '', $s['text']);
            }
            $line = implode('  ', $bits);
        } else {
            $line = (string) preg_replace('/\s+/u', ' ', $out['text']);
        }

        return mb_strlen($line) > $chars ? mb_substr($line, 0, $chars).'…' : ($line !== '' ? $line : '(nothing written)');
    }

    private static function clock(float $t): string
    {
        return sprintf('%d:%04.1f', intdiv((int) $t, 60), fmod($t, 60));
    }

    /**
     * Word and character error rates of $heard against $said, after both are
     * written the same way: lower case, no punctuation, sound tags or Arabic
     * diacritics, and the Arabic letters people write either way made one.
     *
     * @return array{wer: float, cer: float, words: int}
     */
    public static function errorRates(string $said, string $heard): array
    {
        $a = self::words($said);
        $b = self::words($heard);
        $ca = mb_str_split(implode('', $a));
        $cb = mb_str_split(implode('', $b));

        return [
            'wer' => $a === [] ? 0.0 : round(self::distance($a, $b) / count($a), 4),
            'cer' => $ca === [] ? 0.0 : round(self::distance($ca, $cb) / count($ca), 4),
            'words' => count($a),
        ];
    }

    /** @return array<int, string> */
    public static function words(string $text): array
    {
        $text = (string) preg_replace('/\[[^\[\]]*\]|<[^<>]*>/u', ' ', $text);
        $text = (string) preg_replace('/^\s*[\p{L} ]{1,20}:\s/mu', ' ', $text);
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $text);
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي', 'گ' => 'ق', '’' => "'"]);
        $text = (string) preg_replace("/[^\p{L}\p{N}\s']+/u", ' ', $text);

        return array_values(array_filter(preg_split('/\s+/u', $text) ?: [], 'strlen'));
    }

    /** Edit distance between two lists. */
    private static function distance(array $a, array $b): int
    {
        $n = count($b);
        $prev = range(0, $n);
        foreach ($a as $i => $x) {
            $cur = [$i + 1];
            foreach ($b as $j => $y) {
                $cur[$j + 1] = min($prev[$j + 1] + 1, $cur[$j] + 1, $prev[$j] + ($x === $y ? 0 : 1));
            }
            $prev = $cur;
        }

        return $prev[$n];
    }

    /**
     * The recording as one local file: its parts downloaded or found on the
     * site, joined (with the set's gap of silence after each), cut to its
     * longest length, and noise laid over it where asked; with where each
     * part starts and ends in it.
     *
     * @return array{0: string, 1: array<int, array{0: float, 1: float}>}
     */
    private function prepare(string $ffmpeg, array $r, string $dir, Transcriber $transcriber): array
    {
        $inputs = [];
        foreach ($r['parts'] as $k => $part) {
            if (preg_match('#^https?://#', $part) === 1) {
                $local = $dir.'/'.$r['label'].'-'.$k.'.src';
                $response = Http::timeout(300)->withHeaders(['User-Agent' => 'transcribe-measure/1.0'])->sink($local)->get($part);
                if (! $response->ok() || ! is_file($local) || filesize($local) < 1000) {
                    throw new \RuntimeException('download failed (HTTP '.$response->status().'): '.$part);
                }
                $inputs[] = $local;
            } else {
                $path = str_starts_with($part, '/') ? $part : public_path($part);
                if (! is_file($path)) {
                    throw new \RuntimeException('not found: '.$part);
                }
                $inputs[] = $path;
            }
        }
        $gap = (float) ($r['gap'] ?? 0);
        $windows = [];
        if ($gap > 0) {
            $at = 0.0;
            foreach ($inputs as $in) {
                $length = $transcriber->duration($ffmpeg, $in);
                $windows[] = [round($at, 2), round($at + $length, 2)];
                $at += $length + $gap;
            }
        }
        $out = $dir.'/'.$r['label'].'.mp3';
        $cmd = [$ffmpeg, '-v', 'error', '-y'];
        foreach ($inputs as $in) {
            $cmd[] = '-i';
            $cmd[] = $in;
        }
        $joined = '';
        foreach (array_keys($inputs) as $i) {
            $joined .= '['.$i.':a]aformat=sample_rates=44100:channel_layouts=mono'.($gap > 0 ? ',apad=pad_dur='.$gap : '').'[a'.$i.'];';
        }
        $joined .= implode('', array_map(fn ($i) => '[a'.$i.']', array_keys($inputs))).'concat=n='.count($inputs).':v=0:a=1[speech]';
        if ($r['noise']) {
            // Pink noise at about 10 dB under the speech, and a low hum: a noisy room, not a studio.
            $joined .= ';anoisesrc=color=pink:amplitude=0.06:sample_rate=44100[n];sine=frequency=50:sample_rate=44100,volume=0.05[h];'
                .'[speech][n][h]amix=inputs=3:duration=first:normalize=0[out]';
        } else {
            $joined .= ';[speech]anull[out]';
        }
        array_push($cmd, '-filter_complex', $joined, '-map', '[out]');
        if ($r['max'] > 0) {
            array_push($cmd, '-t', (string) $r['max']);
        }
        array_push($cmd, '-ac', '1', '-c:a', 'libmp3lame', '-b:a', '96k', $out);
        $p = new Process($cmd);
        $p->setTimeout(600)->run();
        if (! $p->isSuccessful()) {
            throw new \RuntimeException('ffmpeg: '.mb_substr(trim($p->getErrorOutput()), 0, 300));
        }
        foreach ($inputs as $in) {
            if (str_ends_with($in, '.src')) {
                @unlink($in);
            }
        }

        return [$out, $windows];
    }

    /** @return array<int, array<string, mixed>> */
    private function extraRecordings(): array
    {
        $extra = [];
        foreach ((array) $this->option('file') as $spec) {
            $bits = array_map('trim', explode('|', (string) $spec));
            if (count($bits) < 3 || $bits[0] === '' || $bits[2] === '') {
                $this->warn('Skipped --file "'.$spec.'": use "label|language|path or URL|longest seconds".');

                continue;
            }
            $extra[] = self::recording($bits[0], $bits[1] ?: 'auto', 'given', 'a recording given with --file', [$bits[2]], (int) ($bits[3] ?? 0));
        }

        return $extra;
    }

    /**
     * The test recordings: Listen's own (whose words are known) and
     * public-domain human readings in other languages.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recordings(): array
    {
        $show = fn (string $prefix): array => array_map(fn ($f) => 'audio/showcase/'.basename($f), glob(public_path('audio/showcase/'.$prefix.'*.mp3')) ?: []);
        $env = fn (string $lang): array => array_map(fn ($f) => 'audio/environments/'.basename($f), glob(public_path('audio/environments/env-'.$lang.'-*.mp3')) ?: []);
        $calls = array_map(fn ($f) => 'audio/agents/calls/'.basename($f), glob(public_path('audio/agents/calls/ar-eg-*.mp3')) ?: []);
        $lv = fn (string $id, string $file): string => self::ARCHIVE.$id.'/'.$file;

        return [
            self::recording('ar-eg', 'ar', 'listen', 'Egyptian Arabic (Cairo, Alexandria, Port Said, Upper Egypt) and Egyptian call clips', array_merge($show('ar-eg-'), $calls)),
            self::recording('ar-sa', 'ar', 'listen', 'Saudi Arabic (Najdi, Hejazi, Eastern, Northern, Qassimi, Southern)', $show('ar-sa-')),
            self::recording('ar-msa-human', 'ar', 'human', 'Modern Standard Arabic, a person reading (Kalila wa Dimna, LibriVox)', [$lv('kalilawadimna_2608_librivox', 'kalilawadimna_01_ibnalmuqaffa_64kb.mp3')]),
            self::recording('ar-quotes-human', 'ar', 'human', 'Classical Arabic with Quran and hadith quotations, a person reading (Adab al-Dunya wal-Din, LibriVox)', [$lv('kitab_adab_dunya_1107_librivox', 'adabaldunyawaldin_00_almawardi_64kb.mp3')]),
            self::recording('en-long', 'en', 'listen', 'English long read, 9.5 minutes (cut into two parts)', ['audio/long-form/long-en-clockmaker.mp3']),
            self::recording('en-human', 'en', 'human', 'English, a person reading, 20 minutes (The Untempered Wind, LibriVox)', [$lv('untempered_wind_2102_librivox', 'untemperedwind_02_wood_64kb.mp3')], 1200),
            self::recording('dialogue-en', 'en', 'listen', 'English two-person scenes (podcast, support call, drama ...)', $show('dlg-en-')),
            self::recording('dialogue-ar', 'ar', 'listen', 'Arabic two-person scenes in several dialects', $show('dlg-ar-')),
            self::recording('noisy-places', 'auto', 'listen', 'English and Arabic voices in a stadium, on the phone, on the radio, in a cave, a hall, a church', array_merge($env('en'), $env('ar'))),
            self::recording('noisy-room', 'en', 'listen', 'English long read with steady noise and hum laid over it (about 10 dB under the voice)', ['audio/long-form/long-en-deep-sea.mp3'], 180, null, true),
            self::recording('fr-human', 'fr', 'human', 'French, a person reading (La Fontaine, LibriVox)', [$lv('fables_de_la_fontaine_02_jl_0809_librivox', 'fables02_01_lafontaine_jl_64kb.mp3')]),
            self::recording('es-human', 'es', 'human', 'Spanish, a person reading (Zaragoza, Pérez Galdós, LibriVox)', [$lv('zaragoza_2109_librivox', 'zaragoza_01_perezgaldos_64kb.mp3')]),
            self::recording('ur-human', 'ur', 'human', 'Urdu poetry recited by a person (Ghalib ghazals, LibriVox)', [$lv('ghazals_ghalib_0809_librivox', 'aahkochaahyea_ghalib_64kb.mp3'), $lv('ghazals_ghalib_0809_librivox', 'aashiq_ghalib_64kb.mp3')]),
            self::recording('de-human', 'de', 'human', 'German, a person reading, 5 minutes (Pushkin stories, LibriVox)', [$lv('ausgewahlte_erzahlungen_puschkin_1505_librivox', 'erzaehlungen_01_puschkin_64kb.mp3')], 300),
        ];
    }

    /**
     * Every Arabic dialect and English accent the site has, from the voice
     * library's previews and the homepage samples: one file per Arabic
     * country (Fusha as one) and per English accent group, its clips
     * CLIP_GAP apart, each with its dialect and words.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function dialectRecordings(): array
    {
        $groups = [];
        foreach (self::clipCatalog() as $clip) {
            if (! preg_match('/^(ar|en)-([A-Z]{2})/', $clip['dialect'], $m) || ($m[1] === 'en' && in_array($clip['dialect'], self::NATIVE_ONLY, true))) {
                continue;
            }
            $key = $m[1] === 'ar' ? 'ar-'.$m[2] : (in_array('en-'.$m[2], self::ENGLISH_GROUPS, true) ? 'en-'.$m[2] : 'en-world');
            $groups[$key][] = $clip;
        }
        ksort($groups);
        $recordings = [];
        foreach ($groups as $key => $clips) {
            $arabic = str_starts_with($key, 'ar');
            $hint = $key === 'en-world' ? '' : ($arabic && ArabicDialects::prompt($key) === null ? (string) ($clips[0]['dialect'] ?? '') : $key);
            $names = array_values(array_unique(array_column($clips, 'dialect')));
            $recordings[] = array_replace(self::recording('dia-'.$key, $arabic ? 'ar' : 'en', 'listen', count($clips).' clip(s): '.implode(', ', $names),
                array_column($clips, 'file'), 0, implode("\n", array_column($clips, 'said'))), ['set' => 'dialects', 'dialect' => $hint, 'clips' => $clips, 'gap' => self::CLIP_GAP]);
        }

        return $recordings;
    }

    /** Previews in another language, spoken natively: the languages set, not an English accent. */
    private const NATIVE_ONLY = ['fr-FR', 'fr-CA', 'es-ES', 'es-MX', 'de-DE', 'it-IT', 'pt-BR', 'pt-PT', 'nl-NL', 'tr-TR', 'ru-RU', 'hi-IN', 'ja-JP', 'ko-KR', 'zh-CN'];

    /**
     * The previews spoken natively in other languages: one file per language.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function languageRecordings(): array
    {
        $groups = [];
        foreach (self::clipCatalog() as $clip) {
            if (preg_match('/^([a-z]{2})-/', $clip['dialect'], $m) && ! in_array($m[1], ['ar', 'en'], true)) {
                $groups[$m[1]][] = $clip;
            }
        }
        ksort($groups);
        $recordings = [];
        foreach ($groups as $language => $clips) {
            $recordings[] = array_replace(self::recording('lang-'.$language, $language, 'listen', count($clips).' clip(s): '.implode(', ', array_column($clips, 'dialect')),
                array_column($clips, 'file'), 0, implode("\n", array_column($clips, 'said'))), ['set' => 'languages', 'dialect' => '', 'clips' => $clips, 'gap' => self::CLIP_GAP]);
        }

        return $recordings;
    }

    /**
     * Single-voice clips whose words are known: the voice library previews
     * and the homepage's single-voice samples (with their sign-off).
     *
     * @return array<int, array{slug: string, dialect: string, file: string, said: string}>
     */
    public static function clipCatalog(): array
    {
        $clips = [];
        $data = database_path('seeders/data/');
        if (is_file($data.'voice_previews.php')) {
            foreach ((array) ((require $data.'voice_previews.php')['previews'] ?? []) as $p) {
                $file = 'audio/voice-previews/'.($p['slug'] ?? '').'.mp3';
                if (isset($p['slug'], $p['dialect'], $p['text']) && is_file(public_path($file))) {
                    $clips[] = ['slug' => (string) $p['slug'], 'dialect' => (string) $p['dialect'], 'file' => $file, 'said' => (string) $p['text']];
                }
            }
        }
        $known = self::knownWords();
        if (is_file($data.'showcase_samples.php')) {
            foreach ((array) ((require $data.'showcase_samples.php')['samples'] ?? []) as $s) {
                $file = 'audio/showcase/'.($s['slug'] ?? '').'.mp3';
                if (($s['kind'] ?? '') === 'single' && isset($s['dialect'], $known[$s['slug']]) && is_file(public_path($file))) {
                    $clips[] = ['slug' => (string) $s['slug'], 'dialect' => (string) $s['dialect'], 'file' => $file, 'said' => $known[$s['slug']]];
                }
            }
        }

        return $clips;
    }

    /**
     * @param  array<int, string>  $parts  paths under public/ or URLs
     * @param  string|null|false  $reference  the words said (false: look them up from the parts)
     */
    private static function recording(string $label, string $language, string $kind, string $about, array $parts, int $max = 0, string|null|false $reference = false, bool $noise = false): array
    {
        if ($reference === false) {
            $known = $kind === 'listen' ? self::knownWords() : [];
            $said = [];
            foreach ($parts as $part) {
                $said[] = $known[pathinfo($part, PATHINFO_FILENAME)] ?? null;
            }
            $reference = $said !== [] && ! in_array(null, $said, true) ? implode("\n", $said) : null;
        }

        return compact('label', 'language', 'kind', 'about', 'parts', 'max', 'reference', 'noise') + ['set' => 'default', 'dialect' => '', 'clips' => [], 'gap' => 0];
    }

    /**
     * The words of Listen's own recordings, by file name: the homepage samples
     * (with the sign-off each ends with), the long reads, the environment
     * samples and the agent call clips.
     *
     * @return array<string, string>
     */
    public static function knownWords(): array
    {
        static $known = null;
        if ($known !== null) {
            return $known;
        }
        $known = [];
        $data = database_path('seeders/data/');
        if (is_file($data.'showcase_samples.php')) {
            $show = require $data.'showcase_samples.php';
            foreach ((array) ($show['samples'] ?? []) as $s) {
                $dialogue = ($s['kind'] ?? '') === 'dialogue';
                $text = $dialogue ? implode("\n", (array) ($s['lines'] ?? [])) : (string) ($s['text'] ?? '');
                $sign = (string) ($show['sign_off'][($s['language'] ?? 'en').($dialogue ? '_dialogue' : '')] ?? '');
                $known[(string) $s['slug']] = trim($text."\n".$sign);
            }
        }
        foreach (['long_form_samples.php', 'environment_samples.php'] as $file) {
            if (is_file($data.$file)) {
                foreach ((array) ((require $data.$file)['samples'] ?? []) as $s) {
                    if (isset($s['slug'], $s['text'])) {
                        $known[(string) $s['slug']] = (string) $s['text'];
                    }
                }
            }
        }
        foreach (['en', 'ar'] as $locale) {
            foreach ((array) trans('agents_landing.real_calls', [], $locale) as $call) {
                foreach ((array) ($call['turns'] ?? []) as $turn) {
                    if (is_array($turn) && ($turn[3] ?? '') !== '') {
                        $known[pathinfo((string) $turn[3], PATHINFO_FILENAME)] = (string) $turn[1];
                    }
                }
            }
        }

        return $known;
    }
}
