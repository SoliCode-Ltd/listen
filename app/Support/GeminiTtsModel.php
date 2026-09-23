<?php

namespace App\Support;

use App\Models\SiteSetting;

/**
 * Gemini speech models the admin can select.
 *
 * The saved choice is read on every Gemini generation, so HD speech
 * uses the model selected on the TTS settings page.
 */
class GeminiTtsModel
{
    public const CURRENT = 'gemini-2.5-flash-preview-tts';

    public const FLASH = 'gemini-3.8-flash-tts';

    public const FLASH_LITE = 'gemini-3.8-flash-lite-tts';

    public const SETTING_KEY = 'gemini_tts_model';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::CURRENT => 'Current — Gemini 2.5 Flash Preview',
            self::FLASH => 'Newest — Gemini 3.8 Flash',
            self::FLASH_LITE => 'Newest Lite — Gemini 3.8 Flash-Lite',
        ];
    }

    public static function resolve(?string $model = null): string
    {
        $candidate = trim((string) $model);
        if ($candidate === '') {
            $candidate = trim((string) SiteSetting::get(self::SETTING_KEY, ''));
        }
        if ($candidate === '') {
            $candidate = trim((string) config('services.gemini_tts.model', self::CURRENT));
        }

        return array_key_exists($candidate, self::options()) ? $candidate : self::CURRENT;
    }

    /**
     * Gemini 3.8 speaks the transcript verbatim, so delivery instructions
     * belong in speech metadata instead of the spoken text.
     */
    public static function usesStructuredSpeech(string $model): bool
    {
        return in_array($model, [self::FLASH, self::FLASH_LITE], true);
    }
}
