<?php

namespace App\Rules;

use App\Services\BlockedGenerationLogger;
use App\Services\ContentSafety\TextContentCheck;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The reading of a text's meaning (App\Services\ContentSafety\TextContentCheck), for the
 * forms that make audio at once without a studio generation: the home page's demo and
 * the voice design takes. Put after the word list's rule, with "bail", so a text the
 * word list refuses is not read twice. A reading that could not be done lets the text
 * through, as in the studio.
 */
class PassesContentCheck implements ValidationRule
{
    public function __construct(private readonly ?string $direction = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        // The visitor is waiting for this answer: the reader is given less time than in the studio's job.
        $ruling = app(TextContentCheck::class)->check($value, $this->direction, 8);
        if ($ruling['verdict'] !== TextContentCheck::REFUSED) {
            return;
        }

        app(BlockedGenerationLogger::class)->logFromValidation(
            $value,
            ['language' => '', 'term' => BlockedGenerationLogger::CHECK_LABEL.': '.$ruling['category']],
            $attribute,
        );

        $fail(__('ui.backend.generation_text_prohibited'));
    }
}
