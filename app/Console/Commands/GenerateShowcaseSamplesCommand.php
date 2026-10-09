<?php

namespace App\Console\Commands;

use App\Models\SiteSetting;
use App\Models\VoiceSample;
use App\Support\ArabicDialects;
use App\Support\EnglishAccents;
use App\Support\GeminiTtsModel;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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
        {--force : Regenerate MP3s that already exist}
        {--keep-old : On import, leave the older homepage samples active}
        {--pause=7 : Seconds between Gemini requests}';

    protected $description = 'Generate and import the Gemini 3.8 homepage showcase samples (dialects, accents, dialogues)';

    private const AUDIO_DIR = 'audio/showcase';

    public function handle(): int
    {
        if (! $this->option('generate') && ! $this->option('import')) {
            $this->error('Pass --generate, --import, or both.');

            return self::INVALID;
        }

        $data = require database_path('seeders/data/showcase_samples.php');
        $samples = $data['samples'];
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
     */
    private function generate(array $samples, array $signOff): bool
    {
        $key = trim((string) ($this->option('key') ?: config('services.gemini_tts.api_key')));
        if ($key === '') {
            $this->error('No Gemini API key. Pass --key or set GEMINI_TTS_API_KEY.');

            return false;
        }

        @mkdir(public_path(self::AUDIO_DIR), 0775, true);
        $pause = max(0, (int) $this->option('pause'));
        $failed = 0;
        $requested = false;

        foreach ($samples as $sample) {
            $target = public_path(self::AUDIO_DIR.'/'.$sample['slug'].'.mp3');
            if (is_file($target) && ! $this->option('force')) {
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
            } catch (\Throwable $e) {
                $failed++;
                $this->error("fail  {$sample['slug']}: ".$e->getMessage());
            }
        }

        if ($failed > 0) {
            $this->warn("{$failed} sample(s) failed. Run again to retry only the missing ones.");
        }

        return true;
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
        $isDialogue = $sample['kind'] === 'dialogue';
        $closing = $signOff[$language.($isDialogue ? '_dialogue' : '')];
        $closingStyle = $this->accentDirection($language, $sample['dialect'])
            .' Calm, clear, friendly sign-off at a natural pace, like the closing line of a professional production.';

        if (! $isDialogue) {
            $style = trim($this->accentDirection($language, $sample['dialect']).' '.$sample['style']);
            $speechConfig = ['voiceConfig' => ['voice' => $sample['voice']]];
            $parts = [
                ['text' => $sample['text'], 'speech_metadata' => ['style' => $style]],
                ['text' => '<long pause> '.$closing, 'speech_metadata' => ['style' => trim($closingStyle)]],
            ];
        } else {
            $parts = [];
            $voices = [];
            foreach ($sample['speakers'] as $speaker) {
                $voices[] = ['speaker' => $speaker['name'], 'voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $speaker['voice']]]];
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
        $endpoint = rtrim((string) config('services.gemini_tts.endpoint'), '/').'/models/'.GeminiTtsModel::FLASH.':generateContent';
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

        $process = new Process([
            $ffmpeg, '-y', '-hide_banner', '-loglevel', 'error',
            ...$input, '-i', $raw,
            '-af', 'silenceremove=start_periods=1:start_threshold=-50dB,areverse,silenceremove=start_periods=1:start_threshold=-50dB,areverse,loudnorm=I=-16:TP=-1.5:LRA=11,adelay=120,apad=pad_dur=0.4',
            '-ar', '44100', '-c:a', 'libmp3lame', '-b:a', '128k', $target,
        ]);
        $process->setTimeout(120)->run();
        @unlink($raw);

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('ffmpeg failed: '.trim($process->getErrorOutput()));
        }
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
                    'model' => GeminiTtsModel::FLASH,
                    'sign_off' => $signOff[$language.($isDialogue ? '_dialogue' : '')],
                    'speaker_name' => implode(' & ', array_column($speakers, 'name')),
                    'speaker_name_ar' => implode(' و', array_map(fn (array $s): string => $s['name_ar'] ?? $s['name'], $speakers)),
                    'speakers' => array_map(fn (array $s): array => [
                        'name' => $s['name'], 'name_ar' => $s['name_ar'] ?? $s['name'], 'gender' => $s['gender'], 'dialect' => $s['dialect'] ?? $sample['dialect'],
                    ], $speakers),
                    'turns' => $isDialogue ? $this->turns($sample) : null,
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
