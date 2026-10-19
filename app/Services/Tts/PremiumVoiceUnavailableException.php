<?php

namespace App\Services\Tts;

/**
 * The HD (premium) voice engine could not make this recording: it is not set
 * up, or it failed. HD requests never fall back to a standard voice, so the
 * generation fails, its credits are given back and the member is told the
 * premium voices are busy.
 */
class PremiumVoiceUnavailableException extends \RuntimeException
{
}
