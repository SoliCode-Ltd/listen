<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The member asked for a translation and none could be made: the recording
 * stops rather than reading the untranslated script.
 */
class TranslationFailedException extends RuntimeException
{
}
