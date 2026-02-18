<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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

        $cacheKey = 'listen:tr:v1:'.sha1($sourceLanguage.'|'.$targetLanguage.'|'.$text);

        $translated = Cache::remember($cacheKey, now()->addDays(14), function () use ($text, $sourceLanguage, $targetLanguage) {
            return $this->googleTranslate($text, $sourceLanguage, $targetLanguage) ?: $text;
        });

        return is_string($translated) && $translated !== '' ? $translated : $text;
    }

    private function googleTranslate(string $text, string $source, string $target): ?string
    {
        try {
            $response = Http::timeout(8)
                ->retry(1, 200)
                ->get('https://translate.googleapis.com/translate_a/single', [
                    'client' => 'gtx',
                    'sl' => $source ?: 'auto',
                    'tl' => $target,
                    'dt' => 't',
                    'q' => $text,
                ]);

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
}
