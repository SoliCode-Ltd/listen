<?php

namespace App\Services\Tts;

/**
 * Gemini would not read this text (it blocked the prompt, or stopped for
 * safety): the same text gets the same answer, so it is not sent again and
 * the member is asked to change the wording rather than to wait.
 */
class GeminiTextRefusedException extends \RuntimeException
{
}
