<?php

namespace App\Services\Dubbing;

/**
 * The text model answered, but with nothing that could be read, however it
 * was asked (VideoDubber::transcribeAndRewrite()): it would not copy out a
 * known text (finish RECITATION), or it stopped short. Not a video without
 * speech: the dub tries another way, or fails as not written out.
 */
final class SpeechNotWrittenOut extends \RuntimeException
{
    /** @param  array<int, string>  $finish  why each answer stopped */
    public function __construct(public readonly array $finish)
    {
        parent::__construct('The speech could not be written out (the model gave no answer: '.implode(', ', array_filter($finish) ?: ['empty']).').');
    }
}
