<?php

namespace App\Console\Commands;

use App\Models\SiteSetting;
use App\Models\VoiceSample;
use App\Services\Tts\GeminiTtsProvider;
use App\Support\ArabicDialects;
use App\Support\AudioEffects;
use App\Support\EnglishAccents;
use App\Support\GeminiTtsModel;
use App\Support\HeroCast;
use App\Support\VoicePreviews;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Homepage showcase samples made with Gemini 3.8 Flash TTS.
 *
 * Generating (--generate) spends Gemini quota and writes MP3s to
 * public/audio/showcase. Importing (--import) only writes voice_samples
 * rows for MP3s already on disk, so a server that received the files
 * can be updated without generating anything.
 */
class GenerateShowcaseSamplesCommand extends Command
{
    protected $signature = 'tts:showcase
        {--generate : Generate missing MP3s with Gemini 3.8 (uses Gemini quota)}
        {--import : Create or update the voice_samples rows for the MP3s on disk}
        {--key= : Gemini API key for --generate (default: GEMINI_TTS_API_KEY)}
        {--only=* : Only these sample slugs}
        {--set=showcase : Which samples: showcase (dialects, accents, dialogues), environments (the sound environments section), ad (the launch ad, marketing/launch-ad/lines.php), previews (the voice library, database/seeders/data/voice_previews.php) or pronunciation (the studio pronunciation panel, database/seeders/data/pronunciation_examples.php)}
        {--ad=launch-ad : ad set only: which ad folder under marketing/ (launch-ad, emotions-ad)}
        {--render : environments set only: place each clean clip in its sound environment}
        {--force : Regenerate MP3s that already exist}
        {--keep-old : On import, leave the older homepage samples active}
        {--max=0 : previews set only: make at most this many clips in this run (0 = all)}
        {--pause=7 : Seconds between Gemini requests}';

    protected $description = 'Generate and import the Gemini 3.8 homepage showcase samples (dialects, accents, dialogues)';

    private const AUDIO_DIR = 'audio/showcase';

    private const ENVIRONMENT_DIR = 'audio/environments';

    private const AD_DIR = 'audio/launch-ad';

    private string $dir = self::AUDIO_DIR;

    /** mp3 for the site; wav (lossless, 48 kHz) for the launch ad, which is mixed and encoded again later. */
    private string $ext = 'mp3';

    /** Voice library previews: small mono MP3s that start at once, remade whenever their line changes. */
    private bool $preview = false;

    public function handle(): int
    {
        if ($this->option('set') === 'environments') {
            return $this->environments();
        }
        if ($this->option('set') === 'ad') {
            return $this->ad();
        }
        if ($this->option('set') === 'previews') {
            return $this->previews();
        }
        if ($this->option('set') === 'pronunciation') {
            return $this->pronunciation();
        }

        if (! $this->option('generate') && ! $this->option('import')) {
            $this->error('Pass --generate, --import, or both.');

            return self::INVALID;
        }

        $data = require database_path('seeders/data/showcase_samples.php');
        // The hero clips are generated with the samples but are not gallery samples.
        $samples = array_merge($data['samples'], HeroCast::clips());
        $only = array_filter((array) $this->option('only'));
        if ($only !== []) {
            $samples = array_values(array_filter($samples, fn (array $s): bool => in_array($s['slug'], $only, true)));
        }

        if ($this->option('generate') && ! $this->generate($samples, $data['sign_off'])) {
            return self::FAILURE;
        }

        if ($this->option('import')) {
            $this->import($data['samples'], $data['sign_off'], $only === [] && ! $this->option('keep-old'));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $samples
     * @param  array<string, string>  $signOff
     * @param  (callable(array<string, mixed>): void)|null  $made  called after each clip is saved
     */
    private function generate(array $samples, array $signOff, ?callable $made = null): bool
    {
        $key = trim((string) ($this->option('key') ?: config('services.gemini_tts.api_key')));
        if ($key === '') {
            $this->error('No Gemini API key. Pass --key or set GEMINI_TTS_API_KEY.');

            return false;
        }

        @mkdir(public_path($this->dir), 0775, true);
        $pause = max(0, (int) $this->option('pause'));
        $failed = 0;
        $requested = false;

        foreach ($samples as $sample) {
            $target = public_path($this->dir.'/'.$sample['slug'].'.'.$this->ext);
            if (is_file($target) && ! $this->option('force') && ! $this->preview) {
                $this->line("skip  {$sample['slug']} (exists)");

                continue;
            }
            if ($requested && $pause > 0) {
                sleep($pause);
            }
            $requested = true;

            try {
                $pcm = $this->requestPcm($this->payload($sample, $signOff), $key);
                $this->encodeMp3($pcm, $target);
                $this->info("made  {$sample['slug']} (".round($this->durationOf($target), 1).'s)');
                if ($made !== null) {
                    $made($sample);
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->error("fail  {$sample['slug']}: ".$e->getMessage());
                if (str_starts_with($e->getMessage(), 'HTTP 429')) {
                    // Live reads with the same quota: stop instead of spending what is left.
                    $this->warn('Gemini quota reached: stopping here. Run again later for the rest.');
                    break;
                }
            }
        }

        if ($failed > 0) {
            $this->warn("{$failed} sample(s) failed. Run again to retry only the missing ones.");
        }

        return true;
    }

    /**
     * An ad's voices (marketing/<ad>/lines.php, --ad=launch-ad by default): one
     * Gemini request each, no sign-off, saved as WAV in public/audio/<ad>.
     */
    private function ad(): int
    {
        if (! $this->option('generate')) {
            $this->error('Pass --generate.');

            return self::INVALID;
        }
        $ad = (string) $this->option('ad');
        if (preg_match('/^[a-z0-9-]+$/', $ad) !== 1 || ! is_file(base_path("marketing/{$ad}/lines.php"))) {
            $this->error("No marketing/{$ad}/lines.php.");

            return self::INVALID;
        }
        $samples = (require base_path("marketing/{$ad}/lines.php"))['samples'];
        $only = array_filter((array) $this->option('only'));
        if ($only !== []) {
            $samples = array_values(array_filter($samples, fn (array $s): bool => in_array($s['slug'], $only, true)));
        }
        $samples = array_map(fn (array $s): array => $s + ['kind' => 'single', 'sign_off' => false], $samples);
        $this->dir = $ad === 'launch-ad' ? self::AD_DIR : "audio/{$ad}";
        $this->ext = 'wav';

        return $this->generate($samples, ['en' => '', 'ar' => '']) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The voice library previews (database/seeders/data/voice_previews.php):
     * one Gemini request each, no sign-off, saved as small MP3s in
     * public/audio/voice-previews. Only clips that are missing or whose line
     * changed are made; made.json records the line each clip was made from.
     */
    private function previews(): int
    {
        if (! $this->option('generate')) {
            $this->error('Pass --generate.');

            return self::INVALID;
        }
        $file = VoicePreviews::madePath();
        $made = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
        $only = array_filter((array) $this->option('only'));
        $samples = [];
        foreach (VoicePreviews::lines() as $slug => $line) {
            if ($only !== [] && ! in_array($slug, $only, true)) {
                continue;
            }
            $current = ($made[$slug] ?? null) === VoicePreviews::hash($line) && is_file(public_path(VoicePreviews::DIR."/{$slug}.mp3"));
            if ($current && ! $this->option('force')) {
                continue;
            }
            $samples[] = $line + ['kind' => 'single', 'sign_off' => false, 'speaker_gender' => GeminiTtsProvider::voiceGender($line['voice']),
                // The speaker (by the name in the slug): a child speaks as a child.
                'speaker' => substr($slug, 3)];
        }
        $max = max(0, (int) $this->option('max'));
        $this->info(count($samples).' preview(s) to make'.($max > 0 && count($samples) > $max ? ", {$max} in this run" : '').'.');
        if ($max > 0) {
            $samples = array_slice($samples, 0, $max);
        }
        $this->dir = VoicePreviews::DIR;
        $this->preview = true;

        return $this->generate($samples, ['en' => '', 'ar' => ''], function (array $sample) use (&$made, $file): void {
            $made[$sample['slug']] = VoicePreviews::hash($sample);
            ksort($made);
            file_put_contents($file, json_encode($made, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        }) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The pronunciation panel's examples (database/seeders/data/pronunciation_examples.php):
     * one word each, one Gemini request each, saved as small MP3s in public/audio/pronunciation.
     */
    private function pronunciation(): int
    {
        if (! $this->option('generate')) {
            $this->error('Pass --generate.');

            return self::INVALID;
        }
        $samples = (require database_path('seeders/data/pronunciation_examples.php'))['samples'];
        $only = array_filter((array) $this->option('only'));
        if ($only !== []) {
            $samples = array_values(array_filter($samples, fn (array $s): bool => in_array($s['slug'], $only, true)));
        }
        $samples = array_map(fn (array $s): array => $s + ['kind' => 'single', 'sign_off' => false,
            'speaker_gender' => GeminiTtsProvider::voiceGender($s['voice'])], $samples);
        $this->dir = 'audio/pronunciation';
        // Small mono MP3s that start at once, like the voice library's previews.
        $this->preview = true;

        return $this->generate($samples, ['en' => '', 'ar' => '']) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The homepage sound environments: --generate makes the clean clips (one
     * Gemini request each, no sign-off), --render places each one in its
     * environment with the studio's own engine (no Gemini).
     */
    private function environments(): int
    {
        $samples = (require database_path('seeders/data/environment_samples.php'))['samples'];
        $only = array_filter((array) $this->option('only'));
        if ($only !== []) {
            $samples = array_values(array_filter($samples, fn (array $s): bool => in_array($s['slug'], $only, true)));
        }
        $samples = array_map(fn (array $s): array => $s + ['kind' => 'single', 'sign_off' => false], $samples);

        if ($this->option('generate')) {
            $this->dir = self::ENVIRONMENT_DIR.'/clean';
            if (! $this->generate($samples, ['en' => '', 'ar' => ''])) {
                return self::FAILURE;
            }
        }
        if (! $this->option('render')) {
            return self::SUCCESS;
        }

        if (! AudioEffects::available()) {
            $this->error('No ffmpeg here: the environments cannot be rendered.');

            return self::FAILURE;
        }
        $disk = Storage::disk('public');
        $failed = 0;
        foreach ($samples as $sample) {
            $clean = public_path(self::ENVIRONMENT_DIR.'/clean/'.$sample['slug'].'.mp3');
            if (! is_file($clean)) {
                $this->warn("no clean clip for {$sample['slug']}");
                $failed++;

                continue;
            }
            $work = 'environment-render/'.$sample['slug'].'.mp3';
            $disk->put($work, (string) file_get_contents($clean));
            $placed = AudioEffects::apply($work, $sample['effect']);
            $disk->delete($work);
            if ($placed === null) {
                $this->error("fail  {$sample['slug']} ({$sample['effect']})");
                $failed++;

                continue;
            }
            rename($disk->path($placed), public_path(self::ENVIRONMENT_DIR.'/'.$sample['slug'].'.mp3'));
            $this->info("placed {$sample['slug']} in {$sample['effect']}");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Gemini 3.8 request: the script and the sign-off as separate parts, so
     * the sign-off gets its own calm delivery after a pause.
     *
     * @param  array<string, mixed>  $sample
     * @param  array<string, string>  $signOff
     * @return array<string, mixed>
     */
    private function payload(array $sample, array $signOff): array
    {
        $language = $sample['language'];
        $isDialogue = ($sample['kind'] ?? 'single') === 'dialogue';
        $closing = $signOff[$language.($isDialogue ? '_dialogue' : '')] ?? '';
        $closingStyle = $this->accentDirection($language, $sample['dialect'])
            .' Calm, clear, friendly sign-off at a natural pace, like the closing line of a professional production.';

        if (! $isDialogue) {
            $style = trim($this->accentDirection($language, $sample['dialect']).' '.$sample['style']);
            $aged = GeminiTtsProvider::asSpeaker((string) ($sample['speaker'] ?? ''), $style);
            if ($aged !== $style) {
                $style = $aged;
            } elseif (($sample['speaker_gender'] ?? null) === 'female') {
                // A deep, mature or punchy direction can make a woman's voice sound like a man's.
                $style .= ' The speaker is a woman: keep a natural, clearly female voice.';
            }
            $speechConfig = ['voiceConfig' => ['voice' => $sample['voice']]];
            $parts = [['text' => $sample['text'], 'speech_metadata' => ['style' => $style]]];
            if ($sample['sign_off'] ?? true) {
                $parts[] = ['text' => '<long pause> '.$closing, 'speech_metadata' => ['style' => trim($closingStyle)]];
            }
        } else {
            $parts = [];
            $voices = [];
            foreach ($sample['speakers'] as $speaker) {
                $voices[] = ['speaker' => $speaker['name'], 'voiceConfig' => GeminiTtsProvider::dialogueVoiceConfig($speaker['voice'])];
            }
            foreach ($this->turns($sample) as $turn) {
                $speaker = collect($sample['speakers'])->firstWhere('name', $turn['speaker']);
                $style = trim($this->accentDirection($language, $speaker['dialect'] ?? $sample['dialect']).' '.$sample['style']);
                $parts[] = ['text' => $turn['text'], 'speech_metadata' => ['speaker' => $turn['speaker'], 'style' => $style]];
            }
            $first = $sample['speakers'][0];
            $parts[] = ['text' => '<long pause> '.$closing, 'speech_metadata' => [
                'speaker' => $first['name'],
                'style' => trim($this->accentDirection($language, $first['dialect'] ?? $sample['dialect']).' Calm, clear, friendly sign-off at a natural pace, like the closing line of a professional production.'),
            ]];
            $speechConfig = ['multiSpeakerVoiceConfig' => ['speakerVoiceConfigs' => $voices]];
        }

        return [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => ['responseModalities' => ['AUDIO'], 'speechConfig' => $speechConfig],
        ];
    }

    private function accentDirection(string $language, ?string $code): string
    {
        if (isset(VoicePreviews::NATIVE[(string) $code])) {
            return 'Language: '.VoicePreviews::NATIVE[$code]['prompt'].'. Speak it as a native speaker born and raised there, with natural native pronunciation, rhythm and intonation. No English accent.';
        }
        if ($language === 'ar') {
            $name = ArabicDialects::prompt($code);
            if ($name === null) {
                return '';
            }
            $direction = "Voice dialect: {$name}. Speak with authentic regional pronunciation and natural dialect characteristics.";
            $markers = ArabicDialects::markers($code);
            if ($markers !== null) {
                $direction .= " Dialect markers to make clearly audible: {$markers}";
            }
            if (strcasecmp((string) $code, 'ar-XA') !== 0) {
                $direction .= ' Stay in this regional colloquial variety. Do not switch to Modern Standard Arabic or news-anchor Fusha.';
            }

            return $direction;
        }

        $accent = EnglishAccents::prompt($code);

        return $accent !== null
            ? "Voice accent: {$accent}. Keep this accent consistent and do not switch to a different English accent."
            : '';
    }

    /**
     * @param  array<string, mixed>  $sample
     * @return array<int, array{speaker: string, text: string}>
     */
    private function turns(array $sample): array
    {
        $turns = [];
        foreach ($sample['lines'] as $line) {
            [$speaker, $text] = array_map('trim', explode(':', $line, 2));
            $turns[] = ['speaker' => $speaker, 'text' => $text];
        }

        return $turns;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requestPcm(array $payload, string $key): string
    {
        $endpoint = rtrim((string) config('services.gemini_tts.endpoint'), '/').'/models/'.GeminiTtsModel::LATEST.':generateContent';
        $lastError = 'no attempt';

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = Http::timeout(120)->withHeaders(['x-goog-api-key' => $key])->post($endpoint, $payload);
            } catch (ConnectionException $e) {
                $lastError = $e->getMessage();
                sleep(5);

                continue;
            }
            if ($response->successful()) {
                foreach ((array) $response->json('candidates.0.content.parts', []) as $part) {
                    $data = $part['inlineData']['data'] ?? null;
                    if (is_string($data) && $data !== '') {
                        return base64_decode($data, true) ?: throw new \RuntimeException('Audio was not valid base64.');
                    }
                }
                $lastError = 'reply had no audio (finishReason '.($response->json('candidates.0.finishReason') ?? 'none').')';
            } else {
                $lastError = 'HTTP '.$response->status().': '.mb_substr((string) $response->json('error.message', $response->body()), 0, 300);
                if ($response->status() === 400 || $response->status() === 403) {
                    break;
                }
            }
            sleep($response->status() === 429 ? 30 : 3);
        }

        throw new \RuntimeException($lastError);
    }

    /**
     * Gemini audio to a trimmed, loudness-matched MP3.
     */
    private function encodeMp3(string $pcm, string $target): void
    {
        $raw = tempnam(sys_get_temp_dir(), 'showcase');
        file_put_contents($raw, $pcm);
        $ffmpeg = (string) (config('services.gemini_tts.ffmpeg_path') ?: 'ffmpeg');

        // Gemini 3.8 replies with a WAV file; older replies were bare 24 kHz PCM.
        $input = str_starts_with($pcm, 'RIFF') ? [] : ['-f', 's16le', '-ar', '24000', '-ac', '1'];

        // A preview starts almost at once (40 ms) and is small: mono, 24 kHz like the voice itself, 64 kbps.
        $edges = $this->preview ? 'adelay=40,apad=pad_dur=0.25' : 'adelay=120,apad=pad_dur=0.4';
        $codec = match (true) {
            $this->ext === 'wav' => ['-ar', '48000', '-c:a', 'pcm_s16le'],
            $this->preview => ['-ac', '1', '-ar', '24000', '-c:a', 'libmp3lame', '-b:a', '64k'],
            default => ['-ar', '44100', '-c:a', 'libmp3lame', '-b:a', '128k'],
        };
        $process = new Process([
            $ffmpeg, '-y', '-hide_banner', '-loglevel', 'error',
            ...$input, '-i', $raw,
            '-af', 'silenceremove=start_periods=1:start_threshold=-50dB,areverse,silenceremove=start_periods=1:start_threshold=-50dB,areverse,loudnorm=I=-16:TP=-1.5:LRA=11,'.$edges,
            ...$codec, $target,
        ]);
        $process->setTimeout(120)->run();
        @unlink($raw);

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('ffmpeg failed: '.trim($process->getErrorOutput()));
        }
    }

    /**
     * The dialogue turns with when each is spoken (start and end in seconds), from
     * database/seeders/data/showcase_dialogue_times.json, made by scripts/tts/dialogue_timing.py.
     *
     * @param  array<string, mixed>  $sample
     * @return array<int, array{speaker: string, text: string, start?: float, end?: float}>
     */
    private function timedTurns(array $sample): array
    {
        static $times = null;
        $file = database_path('seeders/data/showcase_dialogue_times.json');
        $times ??= is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];

        $turns = $this->turns($sample);
        $spans = $times[$sample['slug']] ?? [];
        if (count($spans) !== count($turns)) {
            return $turns;
        }
        foreach ($turns as $i => &$turn) {
            $turn['start'] = (float) $spans[$i][0];
            $turn['end'] = (float) $spans[$i][1];
        }
        unset($turn);

        return $turns;
    }

    private function durationOf(string $file): float
    {
        $process = new Process(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $file]);
        $process->run();

        return (float) trim($process->getOutput());
    }

    /**
     * @param  array<int, array<string, mixed>>  $samples
     * @param  array<string, string>  $signOff
     */
    private function import(array $samples, array $signOff, bool $retireOld): void
    {
        $only = array_filter((array) $this->option('only'));
        $kept = [];
        $order = ['ar' => 0, 'en' => 0, 'dialogue' => 0];

        foreach ($samples as $sample) {
            $path = self::AUDIO_DIR.'/'.$sample['slug'].'.mp3';
            if (! is_file(public_path($path))) {
                $this->warn("no audio for {$sample['slug']}, not imported");

                continue;
            }
            if ($only !== [] && ! in_array($sample['slug'], $only, true)) {
                $kept[] = $path;

                continue;
            }

            $isDialogue = $sample['kind'] === 'dialogue';
            $language = $sample['language'];
            $group = $isDialogue ? 'dialogue' : $language;
            $position = $order[$group]++;
            $label = $this->dialectLabel($language, $sample['dialect']);
            $text = $isDialogue
                ? implode("\n", array_map(fn (array $t): string => $t['speaker'].': '.$t['text'], $this->turns($sample)))
                : $sample['text'];
            $speakers = $isDialogue ? $sample['speakers'] : [[
                'name' => $sample['speaker'], 'name_ar' => $sample['speaker_ar'] ?? $sample['speaker'],
                'voice' => $sample['voice'], 'gender' => $sample['gender'],
            ]];

            VoiceSample::updateOrCreate(['audio_path' => $path], [
                'title' => $sample['title'],
                'title_ar' => $sample['title_ar'] ?? $sample['title'],
                'description' => $label['en'].' · '.$sample['title'],
                'description_ar' => $label['ar'].' · '.($sample['title_ar'] ?? $sample['title']),
                'icon' => null,
                'sample_text' => $text,
                'sample_text_ar' => $text,
                'language' => $language,
                'dialect' => $sample['dialect'],
                'country' => $this->countryOf($language, $sample['dialect']),
                'emotion' => $sample['emotion'] ?? null,
                'gender' => $isDialogue ? null : $sample['gender'],
                'voice_name' => implode(',', array_column($speakers, 'voice')),
                'provider' => 'gemini',
                'sort_order' => 100 + $position,
                'is_featured' => $position < 3,
                'is_active' => true,
                'show_in_hero' => false,
                'show_in_showcase' => true,
                'category' => $isDialogue ? 'dialogue' : 'hero',
                'duration_seconds' => (int) round($this->durationOf(public_path($path))),
                'metadata' => [
                    'showcase' => true,
                    'slug' => $sample['slug'],
                    'model' => GeminiTtsModel::LATEST,
                    'sign_off' => $signOff[$language.($isDialogue ? '_dialogue' : '')],
                    'speaker_name' => implode(' & ', array_column($speakers, 'name')),
                    'speaker_name_ar' => implode(' و', array_map(fn (array $s): string => $s['name_ar'] ?? $s['name'], $speakers)),
                    'speakers' => array_map(fn (array $s): array => [
                        'name' => $s['name'], 'name_ar' => $s['name_ar'] ?? $s['name'], 'gender' => $s['gender'], 'dialect' => $s['dialect'] ?? $sample['dialect'],
                    ], $speakers),
                    'turns' => $isDialogue ? $this->timedTurns($sample) : null,
                ],
            ]);
            $kept[] = $path;
            $this->line("saved {$sample['slug']}");
        }

        if ($retireOld) {
            $retired = VoiceSample::query()
                ->whereIn('category', ['hero', 'dialogue'])
                ->where('is_active', true)
                ->whereNotIn('audio_path', $kept)
                ->get();
            foreach ($retired as $old) {
                $old->update(['is_active' => false]);
            }
            $this->info("Deactivated {$retired->count()} older homepage sample(s). They stay in the admin and can be switched back on.");
        }

        // The gallery shows up to this many voices per language; make room for all of them.
        $perLanguage = max($order['ar'], $order['en']);
        if ((int) SiteSetting::get('landing_voice_samples_limit', '12') < $perLanguage) {
            SiteSetting::set('landing_voice_samples_limit', (string) min(60, $perLanguage), 'content');
            $this->info("Homepage sample limit raised to {$perLanguage} per language.");
        }

        VoiceSample::flushLandingCache();
    }

    /**
     * @return array{en: string, ar: string}
     */
    private function dialectLabel(string $language, string $code): array
    {
        $row = $language === 'ar' ? (ArabicDialects::ALL[$code] ?? null) : (EnglishAccents::ALL[$code] ?? null);

        return ['en' => $row['en'] ?? $code, 'ar' => $row['ar'] ?? $code];
    }

    private function countryOf(string $language, string $code): ?string
    {
        if ($language === 'ar') {
            return strcasecmp($code, 'ar-XA') === 0 ? 'XA' : ArabicDialects::countryOf($code);
        }

        // "AR" here means an Arabic accent, not Argentina.
        return strcasecmp($code, 'en-AR') === 0 ? null : EnglishAccents::countryOf($code);
    }
}
