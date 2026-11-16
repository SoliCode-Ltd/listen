<?php

namespace App\Services\Tts;

/**
 * A Gemini reply that is worth sending again: a 5xx, or a reply that
 * finished without audio. Rate limits and bad requests are not transient.
 */
class TransientGeminiException extends \RuntimeException
{
}
