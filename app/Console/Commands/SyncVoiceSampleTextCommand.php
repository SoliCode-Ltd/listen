<?php

namespace App\Console\Commands;

use App\Models\VoiceSample;
use App\Services\Tts\GeminiKeyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Make each sample's text and dialect label match its audio.
 *
 * The sign-in page and landing page show a sample's text next to its audio.
 * When an audio file is replaced and the text is not, they disagree. This
 * transcribes the audio with a Gemini text model and, with --fix, saves the
 * transcript as the text when the two differ by more than a few words.
 * Small differences (punctuation, "twelfth" vs "12th") keep the stored text.
 *
 * It also asks which dialect or accent the audio is in. When that is a
 * different family from the label (Fusha labelled audio that speaks
 * Egyptian, say), --fix corrects the label so the right tab plays it.
 * Neighbours in one family (Saudi and Kuwaiti) are left as labelled.
 */
class SyncVoiceSampleTextCommand extends Command
{
    protected $signature = 'voice-samples:sync-text
        {--fix : Save the transcript when the text does not match the audio}
        {--category=hero : Sample category to check ("all" for every category)}
        {--id=* : Only these sample ids}
        {--threshold=0.15 : Share of words that may differ before the text is replaced}
        {--skip-dialect : Do not check the dialect labels}';

    protected $description = 'Check that each voice sample\'s text matches what its audio says, and fix it with --fix';

    private const MODELS = ['gemini-3.5-flash', 'gemini-2.5-flash'];

    /**
     * Dialect family of each label, and the label a family gets when a sample is relabelled.
     *
     * @var array<string, string>
     */
    private const FAMILY_OF = [
        'ar-XA' => 'msa',
        'ar-EG' => 'egyptian', 'ar-EG-SA' => 'egyptian',
        'ar-SA' => 'gulf', 'ar-SA-HJ' => 'gulf', 'ar-KW' => 'gulf', 'ar-AE' => 'gulf', 'ar-QA' => 'gulf', 'ar-BH' => 'gulf', 'ar-OM' => 'gulf',
        'ar-LB' => 'levantine', 'ar-SY' => 'levantine', 'ar-JO' => 'levantine', 'ar-PS' => 'levantine',
        'ar-IQ' => 'iraqi', 'ar-YE' => 'yemeni', 'ar-SD' => 'sudanese',
        'ar-MA' => 'maghrebi', 'ar-TN' => 'maghrebi', 'ar-DZ' => 'maghrebi', 'ar-LY' => 'maghrebi', 'ar-MR' => 'maghrebi',
        'ar-SA-SO' => 'gulf',
        'ar-SA-QS' => 'gulf',
        'ar-SA-EA' => 'gulf',
        'ar-SA-NO' => 'gulf',
        'ar-OM-DH' => 'gulf',
        'ar-QA-BD' => 'gulf',
        'ar-SY-AL' => 'levantine',
        'ar-JO-BD' => 'levantine',
        'ar-PS-GZ' => 'levantine',
        'ar-EG-AL' => 'egyptian',
        'ar-EG-DL' => 'egyptian',
        'ar-EG-PO' => 'egyptian',
        'ar-EG-BD' => 'egyptian',
        'ar-IQ-BS' => 'iraqi',
        'ar-IQ-MS' => 'iraqi',
        'ar-YE-AD' => 'yemeni',
        'ar-YE-HD' => 'yemeni',
        'ar-MA-NO' => 'maghrebi',
        'ar-DZ-OR' => 'maghrebi',
        'ar-LY-BG' => 'maghrebi',
        'ar-LB-TR' => 'levantine',
        'ar-SY-DZ' => 'levantine',
        'ar-JO-HR' => 'levantine',
        'ar-PS-HB' => 'levantine',
        'ar-IQ-AN' => 'iraqi',
        'ar-YE-TZ' => 'yemeni',
        'ar-MA-MK' => 'maghrebi',
        'ar-DZ-CO' => 'maghrebi',
        'ar-TN-SF' => 'maghrebi',
        'en-US' => 'american', 'en-GB' => 'british', 'en-AU' => 'australian', 'en-IN' => 'indian', 'en-ZA' => 'south_african',
    ];

    private const LABEL_FOR = [
        'msa' => 'ar-XA', 'egyptian' => 'ar-EG', 'gulf' => 'ar-SA', 'levantine' => 'ar-LB', 'iraqi' => 'ar-IQ',
        'yemeni' => 'ar-YE', 'sudanese' => 'ar-SD', 'maghrebi' => 'ar-MA',
        'american' => 'en-US', 'british' => 'en-GB', 'australian' => 'en-AU', 'indian' => 'en-IN', 'south_african' => 'en-ZA',
    ];

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
        $checkDialect = ! $this->option('skip-dialect');
        $counts = ['match' => 0, 'mismatch' => 0, 'fixed' => 0, 'skipped' => 0, 'relabel' => 0, 'relabelled' => 0];

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

            $heard = $this->transcribe($audio, $key, (string) $sample->language);
            if ($heard === null) {
                $this->warn("{$label}: could not transcribe, skipped.");
                $counts['skipped']++;

                continue;
            }
            $spoken = $heard['text'];

            if ($checkDialect && ($newDialect = self::relabel($sample->dialect, $heard['variety'])) !== null) {
                $counts['relabel']++;
                $this->line("{$label}: the audio is {$heard['variety']}, labelled ".($sample->dialect ?: 'none').", should be {$newDialect}.");
                if ($fix) {
                    $metadata = (array) ($sample->metadata ?? []);
                    $metadata['dialect_before_sync'] = $sample->dialect;
                    $metadata['dialect_heard'] = $heard['variety'];
                    $sample->forceFill(['dialect' => $newDialect, 'metadata' => $metadata])->save();
                    $counts['relabelled']++;
                    $this->info("  label changed to {$newDialect}.");
                }
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
        if ($checkDialect) {
            $this->info("Wrong dialect labels: {$counts['relabel']}, relabelled: {$counts['relabelled']}.");
        }
        if ($counts['mismatch'] > $counts['fixed'] || $counts['relabel'] > $counts['relabelled']) {
            $this->line('Run again with --fix to save the changes.');
        }

        return self::SUCCESS;
    }

    /**
     * The label a sample should have, or null when its label is right (or the audio could not be placed).
     */
    public static function relabel(?string $dialect, ?string $heard): ?string
    {
        $heard = strtolower(trim((string) $heard));
        if (! isset(self::LABEL_FOR[$heard])) {
            return null;
        }
        $current = self::FAMILY_OF[(string) $dialect] ?? null;

        return $current === $heard ? null : self::LABEL_FOR[$heard];
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

    /**
     * @return array{text: string, variety: ?string}|null
     */
    private function transcribe(array $audio, string $key, string $language): ?array
    {
        $base = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $families = $language === 'en'
            ? 'american, british, australian, indian, south_african'
            : 'msa (Modern Standard Arabic, Fusha), egyptian, gulf (Saudi, Kuwaiti, Emirati, Qatari, Bahraini, Omani), levantine (Lebanese, Syrian, Jordanian, Palestinian), iraqi, yemeni, sudanese, maghrebi (Moroccan, Tunisian, Algerian, Libyan)';
        $prompt = 'Listen to this audio. Return JSON with two keys: "transcript" (exactly what is said, word for word, '
            .'in the language and dialect spoken, with normal punctuation) and "variety" (the dialect or accent family of the speaker, '
            .'one of: '.$families.'; use "unknown" if unsure).';

        foreach (self::MODELS as $model) {
            try {
                $response = Http::timeout(90)->withHeaders(['x-goog-api-key' => $key])
                    ->post($base.'/models/'.$model.':generateContent', [
                        'contents' => [[
                            'role' => 'user',
                            'parts' => [
                                ['inline_data' => ['mime_type' => $audio['mime'], 'data' => base64_encode($audio['bytes'])]],
                                ['text' => $prompt],
                            ],
                        ]],
                        'generationConfig' => ['responseMimeType' => 'application/json'],
                    ]);
                $raw = $response->json('candidates.0.content.parts.0.text');
                if (! $response->ok() || ! is_string($raw) || trim($raw) === '') {
                    continue;
                }
                $data = json_decode(trim((string) preg_replace('/^```(?:json)?|```$/m', '', $raw)), true);
                if (is_array($data) && is_string($data['transcript'] ?? null) && trim($data['transcript']) !== '') {
                    return ['text' => trim($data['transcript']), 'variety' => is_string($data['variety'] ?? null) ? $data['variety'] : null];
                }

                // A plain transcript without JSON still fixes the text.
                return ['text' => trim($raw), 'variety' => null];
            } catch (Throwable) {
                // Try the next model.
            }
        }

        return null;
    }
}
