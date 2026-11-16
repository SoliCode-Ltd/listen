<?php

namespace App\Console\Commands;

use App\Services\Transcription\Transcriber;
use App\Support\AudioFormats;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * Measures what speech to text ("Transcribe") costs and how well it hears,
 * on STAGING, before the feature is built around it: it writes out real
 * recordings with App\Services\Transcription\Transcriber (the dubbing's text
 * model and keys) three ways (plain text; with timings; with timings and
 * speaker letters) and prints, for each, the model's tokens, US dollars per
 * audio minute at the dubbing's prices (listen.dubbing.prices), and the
 * first lines of what it wrote, so the owner can judge accuracy.
 *
 * For Listen's own recordings (the homepage samples, the long reads, the
 * dialogues, the environment samples, the agent call clips) the words said
 * are known: it also prints the word and character error rates against
 * them. Those voices are clean studio speech, so they flatter accuracy; the
 * public-domain human readings (LibriVox, downloaded from archive.org) are
 * the fairer test, and the only recordings in other languages.
 *
 * Read-only: it saves nothing in the database and makes no sound. It only
 * sends the recordings to the text model, on the Google account staging
 * shares with live (about 70 minutes of audio, 3 ways: some 80 requests),
 * so it runs by hand only, when the owner has said yes. It refuses to run
 * on the live site. The full report (every transcript) is written to
 * storage/app/transcribe-measure/<run>/report.json.
 *
 *   php artisan transcribe:measure
 *   php artisan transcribe:measure --only=ar-eg,fr-human --modes=text
 *   php artisan transcribe:measure --file="tr-human|tr|https://example.org/turkish.mp3|300"
 */
class TranscribeMeasureCommand extends Command
{
    protected $signature = 'transcribe:measure
        {--only= : Only these recordings, by label (comma separated)}
        {--modes=text,timed,speakers : Ways to write each one out}
        {--file=* : An extra recording: "label|language|path or URL|longest seconds" (language "auto" to detect)}
        {--language=given : given: tell the model each recording\'s language; auto: let it detect every one}
        {--thinking=low : How much the model thinks first (low, minimal, or empty for its own)}
        {--full : Print every plain-text transcript in full at the end}
        {--prepare-only : Only make and time the test files: nothing is sent to the model}';

    protected $description = 'Write out test recordings and measure what speech to text costs and how accurate it is (staging only).';

    private const ARCHIVE = 'https://archive.org/download/';

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
        $modes = array_values(array_intersect(array_map('trim', explode(',', (string) $this->option('modes'))), ['text', 'timed', 'speakers']));
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $recordings = array_merge(self::recordings(), $this->extraRecordings());
        if ($only !== []) {
            $recordings = array_values(array_filter($recordings, fn (array $r) => in_array($r['label'], $only, true)));
        }
        $prices = (array) config('listen.dubbing.prices', []);
        $this->line(sprintf('Run %s: %d recording(s) x %d way(s), thinking %s. Prices a million tokens: audio in $%.2f, text in $%.2f, out $%.2f.',
            $run, count($recordings), count($modes), $this->option('thinking') ?: 'default', $prices['audio_in'] ?? 0, $prices['text_in'] ?? 0, $prices['text_out'] ?? 0));

        $results = [];
        foreach ($recordings as $r) {
            try {
                $file = $this->prepare($ffmpeg, $r, $dir);
            } catch (\Throwable $e) {
                $this->warn(sprintf('%-18s could not be prepared: %s', $r['label'], mb_substr($e->getMessage(), 0, 200)));
                $results[] = ['label' => $r['label'], 'error' => $e->getMessage()];

                continue;
            }
            $this->newLine();
            $this->line(sprintf('■ %s (%s, %s) — %s', $r['label'], $r['language'], $r['kind'], $r['about']));
            if ($this->option('prepare-only')) {
                $this->line(sprintf('  ready: %.2f min, %d KB, words known: %s', $transcriber->duration($ffmpeg, $file) / 60, filesize($file) / 1024,
                    $r['reference'] !== null ? count(self::words($r['reference'])) : 'no'));
                $results[] = ['label' => $r['label'], 'prepared' => true];

                continue;
            }
            foreach ($modes as $mode) {
                $t = microtime(true);
                try {
                    $out = $transcriber->transcribe($file, [
                        'language' => $this->option('language') === 'auto' ? 'auto' : $r['language'],
                        'timings' => $mode !== 'text', 'speakers' => $mode === 'speakers', 'thinking' => (string) $this->option('thinking'),
                    ]);
                } catch (\Throwable $e) {
                    $this->warn(sprintf('  %-9s FAILED: %s', $mode, mb_substr($e->getMessage(), 0, 300)));
                    $results[] = ['label' => $r['label'], 'mode' => $mode, 'error' => $e->getMessage()];

                    continue;
                }
                $minutes = max(0.01, $out['seconds'] / 60);
                $cost = Transcriber::cost($out['usage'], $prices);
                $u = $out['usage'];
                $errors = $r['reference'] !== null ? self::errorRates($r['reference'], $out['text']) : null;
                $speakers = $mode === 'speakers' ? count(array_unique(array_filter(array_column($out['segments'], 'speaker')))) : null;
                $this->line(sprintf('  %-9s %5.2f min  %d part(s) %d req  in %6d (audio %6d) out %5d think %5d  $%.4f = $%.4f/min  %4.0fs%s  lang %s%s%s%s',
                    $mode, $minutes, $out['parts'], $out['requests'], $u['input_tokens'], $u['audio_tokens'], $u['output_tokens'], $u['thinking_tokens'],
                    $cost, $cost / $minutes, microtime(true) - $t,
                    $errors !== null ? sprintf('  WER %4.1f%% CER %4.1f%%', $errors['wer'] * 100, $errors['cer'] * 100) : '',
                    $out['language'] ?: '?', $speakers !== null ? '  speakers '.$speakers : '', $out['recited'] ? '  RECITATION: quotations shortened' : '',
                    $out['model'] !== '' ? '  ['.$out['model'].']' : ''));
                $this->line('      '.self::firstLines($out, 320));
                foreach ($out['notes'] as $note) {
                    $this->line('      note: '.$note);
                }
                $results[] = ['label' => $r['label'], 'language' => $r['language'], 'kind' => $r['kind'], 'mode' => $mode, 'seconds' => $out['seconds'],
                    'parts' => $out['parts'], 'requests' => $out['requests'], 'usage' => $u, 'cost' => round($cost, 6), 'cost_per_minute' => round($cost / $minutes, 6),
                    'errors' => $errors, 'detected' => $out['language'], 'speakers' => $speakers, 'recited' => $out['recited'], 'model' => $out['model'],
                    'finish' => $out['finish'], 'notes' => $out['notes'], 'text' => $out['text'], 'segments' => $out['segments']];
            }
        }

        $this->summary($results, $modes);
        if ($this->option('full')) {
            $this->newLine();
            $this->line('Full transcripts (plain text):');
            foreach ($results as $res) {
                if (($res['mode'] ?? '') === 'text' && isset($res['text'])) {
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

    /** @param  array<int, array<string, mixed>>  $results */
    private function summary(array $results, array $modes): void
    {
        $done = array_values(array_filter($results, fn ($r) => ! isset($r['error']) && isset($r['mode'])));
        if ($done === []) {
            return;
        }
        $this->newLine();
        $this->line('Per audio minute, all recordings together (the planning figure is $0.005; above $0.008 the allowances are cut by a third):');
        foreach ($modes as $mode) {
            $rows = array_values(array_filter($done, fn ($r) => $r['mode'] === $mode));
            if ($rows === []) {
                continue;
            }
            $minutes = array_sum(array_column($rows, 'seconds')) / 60;
            $sum = fn (string $key) => array_sum(array_map(fn ($r) => (int) $r['usage'][$key], $rows));
            $per = array_column($rows, 'cost_per_minute');
            $this->line(sprintf('  %-9s $%.4f/min (cheapest $%.4f, dearest $%.4f) over %.1f min; tokens a minute: audio %d, other in %d, out %d, thinking %d',
                $mode, array_sum(array_column($rows, 'cost')) / $minutes, min($per), max($per), $minutes,
                $sum('audio_tokens') / $minutes, ($sum('input_tokens') - $sum('audio_tokens')) / $minutes, $sum('output_tokens') / $minutes, $sum('thinking_tokens') / $minutes));
        }
        $this->newLine();
        $this->line('Accuracy where the words are known (plain text; lower is better):');
        foreach ($done as $r) {
            if ($r['mode'] === ($modes[0] ?? 'text') && $r['errors'] !== null) {
                $this->line(sprintf('  %-18s %-3s WER %5.1f%%  CER %5.1f%%', $r['label'], $r['language'], $r['errors']['wer'] * 100, $r['errors']['cer'] * 100));
            }
        }
        $failed = array_filter($results, fn ($r) => isset($r['error']));
        if ($failed !== []) {
            $this->line('Failed: '.implode(', ', array_unique(array_map(fn ($r) => $r['label'].(isset($r['mode']) ? ' ('.$r['mode'].')' : ''), $failed))));
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
     * site, joined, cut to its longest length, and noise laid over it where asked.
     */
    private function prepare(string $ffmpeg, array $r, string $dir): string
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
        $out = $dir.'/'.$r['label'].'.mp3';
        $cmd = [$ffmpeg, '-v', 'error', '-y'];
        foreach ($inputs as $in) {
            $cmd[] = '-i';
            $cmd[] = $in;
        }
        $joined = '';
        foreach (array_keys($inputs) as $i) {
            $joined .= '['.$i.':a]aformat=sample_rates=44100:channel_layouts=mono['.'a'.$i.'];';
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

        return $out;
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

        return compact('label', 'language', 'kind', 'about', 'parts', 'max', 'reference', 'noise');
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
