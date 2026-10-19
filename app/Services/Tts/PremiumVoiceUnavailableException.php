<?php

namespace App\Services\Tts;

/**
 * The HD (premium) voice engine could not make this recording: it is not set
 * up, or it failed. HD requests never fall back to a standard voice, so the
 * generation fails, its credits are given back and the member is told why:
 * the premium voices are busy for a moment, or a limit on our side that
 * lasts holds them back (then no wait is promised).
 */
class PremiumVoiceUnavailableException extends \RuntimeException
{
    /** A passing overload or error: trying again soon can work. */
    public const BUSY = 'busy';

    /**
     * A limit on our side that lasts: the site's daily ceiling, every key out
     * (the day's quota, the spending cap, refused keys), or no engine set up.
     */
    public const OUR_LIMIT = 'our_limit';

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly string $reason = self::BUSY,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
