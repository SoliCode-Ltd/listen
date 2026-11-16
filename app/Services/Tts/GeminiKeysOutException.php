<?php

namespace App\Services\Tts;

/**
 * Every Gemini key is out for longer than a minute: its daily quota is used
 * up, its project hit its spending cap, or Google refused the key. A limit
 * on our side, so it is not sent again and the member is not told to wait
 * a minute.
 */
class GeminiKeysOutException extends \RuntimeException
{
}
