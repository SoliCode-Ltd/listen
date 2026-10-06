<?php

namespace App\Services;

use App\Support\GeminiVocalTags;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranslationService
{
    public function maybeTranslate(string $text, string $sourceLanguage, string $targetLanguage, bool $translate): string
    {
        if (! $translate || $sourceLanguage === $targetLanguage) {
            return $text;
        }

        $text = trim($text);
        if ($text === '') {
            return $text;
        }

        $cacheKey = 'listen:tr:v3:'.sha1($sourceLanguage.'|'.$targetLanguage.'|'.$text);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // Sound and mood tags like <laugh> or [excited] stay in English.
        $protected = GeminiVocalTags::protect($text);
        $translated = $this->googleTranslate($protected['text'], $sourceLanguage, $targetLanguage);
        if (is_string($translated) && $translated !== '') {
            $translated = GeminiVocalTags::restore($translated, $protected['tags']);
            Cache::put($cacheKey, $translated, now()->addDays(14));
            return $translated;
        }

        return $text;
    }

    private function googleTranslate(string $text, string $source, string $target): ?string
    {
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
