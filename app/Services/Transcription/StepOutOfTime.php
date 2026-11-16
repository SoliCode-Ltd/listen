<?php

namespace App\Services\Transcription;

/**
 * Too little of a step's time is left to ask the model once more (Transcriber::until()): the step
 * is tried again later rather than stopped by the queue in the middle of a paid request.
 */
final class StepOutOfTime extends \RuntimeException {}
