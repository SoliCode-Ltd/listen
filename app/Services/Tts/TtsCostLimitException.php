<?php

namespace App\Services\Tts;

/**
 * A site-wide daily speech limit (TtsCostGuardrail) has been reached.
 */
class TtsCostLimitException extends \RuntimeException
{
}
