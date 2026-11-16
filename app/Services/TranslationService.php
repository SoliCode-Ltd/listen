<?php

namespace App\Services;

use App\Exceptions\TranslationFailedException;
use App\Support\GeminiVocalTags;
use App\Support\SpeechLanguages;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranslationService
{
    public function maybeTranslate(string $text, string $sourceLanguage, string $targetLanguage, bool $translate): string
    {
        // Letters tell Arabic, Russian, Japanese … apart, but not English from
        // Spanish or French: Latin-script text ('en') is left to the translator to identify.
        $latin = $sourceLanguage === 'en';
        if (! $translate || ($sourceLanguage === $targetLanguage && ! $latin)) {
            return $text;
        }

        $text = trim($text);
        if ($text === '') {
            return $text;
        }

        $cacheKey = 'listen:tr:v4:'.sha1($sourceLanguage.'|'.$targetLanguage.'|'.$text);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // Sound and mood tags like <laugh> or [excited] stay as they are.
        $protected = GeminiVocalTags::protect($text);
        $translated = $this->googleTranslate($protected['text'], $latin ? 'auto' : $sourceLanguage, $targetLanguage, $detected);
        if ($detected !== null && strtolower(explode('-', $detected)[0]) === $targetLanguage) {
            // Already written in the voice's language.
            return $text;
        }
        if ($translated === null && $sourceLanguage === $targetLanguage) {
            // English (or Latin-script) text for an English voice, and no answer from the translator: read it as written.
            return $text;
        }
        if (! $this->usable($translated, $protected['tags'], $targetLanguage)) {
            // The quick translator failed or left part of the script untouched:
            // ask the language model for the whole script instead.
            $translated = $this->modelTranslate($protected['text'], $latin ? null : $sourceLanguage, $targetLanguage);
        }
        if (! $this->usable($translated, $protected['tags'], $targetLanguage)) {
            // Never read the original text in place of the translation the member asked for.
            Log::warning('Translation failed; the generation stops instead of reading the original.', [
                'source' => $sourceLanguage,
                'target' => $targetLanguage,
            ]);
            throw new TranslationFailedException(__('ui.backend.translation_failed'));
        }

        $translated = GeminiVocalTags::restore((string) $translated, $protected['tags']);
        Cache::put($cacheKey, $translated, now()->addDays(14));

        return $translated;
    }

    /**
     * Many texts asked of the quick translator at once (a transcript's paragraphs), each usable
     * answer kept where maybeTranslate() looks first, so the texts then come back at once instead
     * of one request after another. A text it cannot do well is left for maybeTranslate() to do.
     *
     * @param  array<int, string>  $texts
     */
    public function prefetch(array $texts, string $sourceLanguage, string $targetLanguage): void
    {
        $latin = $sourceLanguage === 'en';
        if ($sourceLanguage === $targetLanguage && ! $latin) {
            return;
        }
        $todo = [];
        foreach (array_values(array_unique(array_map('trim', $texts))) as $text) {
            $key = 'listen:tr:v4:'.sha1($sourceLanguage.'|'.$targetLanguage.'|'.$text);
            if ($text === '' || GeminiVocalTags::protect($text)['tags'] !== [] || (is_string(Cache::get($key)) && Cache::get($key) !== '')) {
                continue;
            }
            $todo[$key] = $text;
        }
        if ($todo === []) {
            return;
        }
        $verify = (bool) config('services.google_tts.verify_ssl', true);
        foreach (array_chunk($todo, 8, true) as $batch) {
            try {
                $answers = Http::pool(function ($pool) use ($batch, $latin, $sourceLanguage, $targetLanguage, $verify): array {
                    $sent = [];
                    foreach ($batch as $key => $text) {
                        $sent[] = $pool->as($key)->timeout(8)->withOptions(['verify' => $verify])->get('https://translate.googleapis.com/translate_a/single', [
                            'client' => 'gtx', 'sl' => $latin ? 'auto' : $sourceLanguage, 'tl' => $targetLanguage, 'dt' => 't', 'q' => $text,
                        ]);
                    }

                    return $sent;
                });
            } catch (\Throwable) {
                return;
            }
            foreach ($batch as $key => $text) {
                $response = $answers[$key] ?? null;
                if (! $response instanceof Response || ! $response->ok()) {
                    continue;
                }
                $json = $response->json();
                if (! is_array($json) || ! is_array($json[0] ?? null)) {
                    continue;
                }
                $detected = is_string($json[2] ?? null) ? strtolower(explode('-', $json[2])[0]) : null;
                $parts = [];
                foreach ($json[0] as $segment) {
                    if (is_array($segment) && is_string($segment[0] ?? null)) {
                        $parts[] = $segment[0];
                    }
                }
                $translated = trim(implode('', $parts));
                if ($detected === $targetLanguage || ! $this->usable($translated, [], $targetLanguage)) {
                    continue;
                }
                Cache::put($key, $translated, now()->addDays(14));
            }
        }
    }

    /**
     * @param  array<int, string>  $tags
     */
    private function usable(?string $translated, array $tags, string $targetLanguage): bool
    {
        if (! is_string($translated) || trim($translated) === '') {
            return false;
        }
        if ($tags !== [] && ! GeminiVocalTags::placeholdersIntact($translated, count($tags))) {
            return false;
        }

        return ! SpeechLanguages::hasForeignScript((string) preg_replace('/\[\s*\[\s*\d+\s*\]\s*\]/u', ' ', $translated), $targetLanguage);
    }

    private function modelTranslate(string $text, ?string $source, string $target): ?string
    {
        $from = $source !== null ? ' from '.SpeechLanguages::englishName($source) : '';
        $prompt = 'Translate this voice-over script'.$from.' into '.SpeechLanguages::englishName($target).". If it is already entirely in that language, return it unchanged.\n"
            .'Keep the meaning, tone and any slang natural for a native speaker reading it aloud. '
            .'Keep every placeholder such as [[0]] or [[1]] exactly as written and in the same place. '
            .'Keep line breaks. Return only the translated script, with no notes or quotes.'
            ."\n\nScript:\n".$text;

        return app(VoiceCloningService::class)->generateText($prompt, 25);
    }

    private function googleTranslate(string $text, string $source, string $target, ?string &$detected = null): ?string
    {
        $detected = null;
        try {
            $verifySsl = (bool) config('services.google_tts.verify_ssl', true);
            $response = $this->sendWithSslFallback(
                fn (bool $verify) => Http::timeout(8)
                    ->retry(1, 200)
                    ->withOptions(['verify' => $verify])
                    ->get('https://translate.googleapis.com/translate_a/single', [
                        'client' => 'gtx',
                        'sl' => $source ?: 'auto',
                        'tl' => $target,
                        'dt' => 't',
                        'q' => $text,
                    ]),
                $verifySsl,
            );

            if (! $response->ok()) {
                return null;
            }

            $json = $response->json();
            if (! is_array($json) || ! isset($json[0]) || ! is_array($json[0])) {
                return null;
            }

            $detected = isset($json[2]) && is_string($json[2]) ? $json[2] : null;
            $parts = [];
            foreach ($json[0] as $segment) {
                if (is_array($segment) && isset($segment[0]) && is_string($segment[0])) {
                    $parts[] = $segment[0];
                }
            }

            return trim(implode('', $parts)) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function sendWithSslFallback(callable $sender, bool $verifySsl)
    {
        try {
            return $sender($verifySsl);
        } catch (\Throwable $e) {
            if (! $this->shouldRetryWithoutSsl($e, $verifySsl)) {
                throw $e;
            }

            Log::warning('Google Translate request failed SSL verification on local environment; retrying without verification.');

            return $sender(false);
        }
    }

    private function shouldRetryWithoutSsl(\Throwable $e, bool $verifySsl): bool
    {
        if (! $verifySsl || ! app()->environment('local')) {
            return false;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'curl error 60')
            || str_contains($message, 'ssl certificate problem')
            || str_contains($message, 'unable to get local issuer certificate');
    }
}
