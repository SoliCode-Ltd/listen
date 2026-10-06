<?php

namespace App\Console\Commands;

use App\Models\VoiceSample;
use App\Services\Tts\GeminiKeyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Make each sample's text match its audio.
 *
 * The sign-in page and landing page show a sample's text next to its audio.
 * When an audio file is replaced and the text is not, they disagree. This
 * transcribes the audio with a Gemini text model and, with --fix, saves the
 * transcript as the text when the two differ by more than a few words.
 * Small differences (punctuation, "twelfth" vs "12th") keep the stored text.
 */
class SyncVoiceSampleTextCommand extends Command
{
    protected $signature = 'voice-samples:sync-text
        {--fix : Save the transcript when the text does not match the audio}
        {--category=hero : Sample category to check ("all" for every category)}
        {--id=* : Only these sample ids}
        {--threshold=0.15 : Share of words that may differ before the text is replaced}';

    protected $description = 'Check that each voice sample\'s text matches what its audio says, and fix it with --fix';

    private const MODELS = ['gemini-3.5-flash', 'gemini-2.5-flash'];

    public function handle(): int
    {
        $key = (string) (app(GeminiKeyManager::class)->getAvailableKey()['key'] ?? '');
        if ($key === '') {
            $this->error('No Gemini API key is configured.');

            return self::FAILURE;
        }

        $query = VoiceSample::query()->active()->orderBy('language')->orderBy('sort_order');
        if (($category = (string) $this->option('category')) !== 'all') {
            $query->where('category', $category);
        }
        if ($ids = array_filter((array) $this->option('id'))) {
            $query->whereIn('id', $ids);
        }

        $threshold = max(0.0, (float) $this->option('threshold'));
        $fix = (bool) $this->option('fix');
        $counts = ['match' => 0, 'mismatch' => 0, 'fixed' => 0, 'skipped' => 0];

        foreach ($query->get() as $sample) {
            $field = $sample->language === 'ar' && trim((string) $sample->sample_text_ar) !== '' ? 'sample_text_ar' : 'sample_text';
            $stored = trim((string) $sample->{$field});
            $label = "#{$sample->id} {$sample->language} {$sample->dialect}";

            $audio = $this->audio((string) $sample->audio_path);
            if ($audio === null) {
                $this->warn("{$label}: audio file missing, skipped.");
                $counts['skipped']++;

                continue;
            }

            $spoken = $this->transcribe($audio, $key);
            if ($spoken === null) {
                $this->warn("{$label}: could not transcribe, skipped.");
                $counts['skipped']++;

                continue;
            }

            $difference = self::difference($stored, $spoken);
            if ($difference <= $threshold) {
                $counts['match']++;
                $this->line("{$label}: matches (".round($difference * 100).'% different).');

                continue;
            }

            $counts['mismatch']++;
            $this->line("{$label}: does not match (".round($difference * 100).'% different).');
            $this->line('  text : '.$stored);
            $this->line('  audio: '.$spoken);

            if ($fix) {
                $metadata = (array) ($sample->metadata ?? []);
                $metadata['text_before_sync'] = $stored;
                $metadata['text_synced_at'] = now()->toIso8601String();
                $sample->forceFill([$field => $spoken, 'metadata' => $metadata])->save();
                if ($field === 'sample_text_ar' && trim((string) $sample->sample_text) === $stored) {
                    $sample->forceFill(['sample_text' => $spoken])->save();
                }
                $counts['fixed']++;
                $this->info('  saved the audio transcript as the text.');
            }
        }

        $this->newLine();
        $this->info("Matching: {$counts['match']}, not matching: {$counts['mismatch']}, fixed: {$counts['fixed']}, skipped: {$counts['skipped']}.");
        if ($counts['mismatch'] > $counts['fixed']) {
            $this->line('Run again with --fix to save the transcripts.');
        }

        return self::SUCCESS;
    }

    /**
     * Share of words in either text that the other does not have (0 = same words).
     */
    public static function difference(string $a, string $b): float
    {
        $wordsA = self::words($a);
        $wordsB = self::words($b);
        if ($wordsA === [] && $wordsB === []) {
            return 0.0;
        }

        $missing = count(array_diff_key(array_count_values($wordsA), array_count_values($wordsB)))
            + count(array_diff_key(array_count_values($wordsB), array_count_values($wordsA)));

        return $missing / max(1, count(array_unique($wordsA)) + count(array_unique($wordsB)));
    }

    /**
     * @return array<int, string>
     */
    private static function words(string $text): array
    {
        $text = mb_strtolower($text);
        // Arabic diacritics and tatweel, and the letter forms speech-to-text writes either way.
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
        $text = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);

        return array_values(array_filter(preg_split('/\s+/u', $text) ?: [], fn (string $w) => $w !== ''));
    }

    private function audio(string $path): ?array
    {
        $path = ltrim($path, '/');
        $disk = Storage::disk('public');
        if ($path === '' || ! $disk->exists($path)) {
            return null;
        }
        $bytes = (string) $disk->get($path);

        return ['bytes' => $bytes, 'mime' => str_starts_with($bytes, 'RIFF') ? 'audio/wav' : 'audio/mpeg'];
    }

    private function transcribe(array $audio, string $key): ?string
    {
        $base = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');

        foreach (self::MODELS as $model) {
            try {
                $response = Http::timeout(90)->withHeaders(['x-goog-api-key' => $key])
                    ->post($base.'/models/'.$model.':generateContent', [
                        'contents' => [[
                            'role' => 'user',
                            'parts' => [
                                ['inline_data' => ['mime_type' => $audio['mime'], 'data' => base64_encode($audio['bytes'])]],
                                ['text' => 'Transcribe exactly what is said in this audio, word for word, in the language and dialect spoken, with normal punctuation. Return only the transcript.'],
                            ],
                        ]],
                    ]);
                $text = $response->json('candidates.0.content.parts.0.text');
                if ($response->ok() && is_string($text) && trim($text) !== '') {
                    return trim($text);
                }
            } catch (Throwable) {
                // Try the next model.
            }
        }

        return null;
    }
}
