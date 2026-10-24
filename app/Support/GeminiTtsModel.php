<?php

namespace App\Support;

/**
 * The Gemini speech model every HD voice speaks with.
 *
 * Always Gemini's latest speech model: there is no older or lighter
 * choice, so every HD generation (and every speaker's own library
 * voice) is made the same way.
 */
class GeminiTtsModel
{
    public const LATEST = 'gemini-3.8-flash-tts';

    public static function resolve(): string
    {
        return self::LATEST;
    }
}
