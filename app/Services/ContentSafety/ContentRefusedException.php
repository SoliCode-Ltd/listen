<?php

namespace App\Services\ContentSafety;

/**
 * A text Listen does not make a voice of (TextContentCheck). Never retried: the
 * same text is refused again, so the generation fails at once and its credits
 * are given back.
 */
class ContentRefusedException extends \RuntimeException
{
    public function __construct(public readonly string $category, public readonly string $why = '')
    {
        parent::__construct('The text was refused by the content check ('.$category.').');
    }
}
