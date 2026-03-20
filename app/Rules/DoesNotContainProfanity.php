<?php

namespace App\Rules;

use App\Services\BlockedGenerationLogger;
use App\Services\ProfanityFilter;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class DoesNotContainProfanity implements ValidationRule
{
    public function __construct(
        private readonly ProfanityFilter $profanityFilter,
        private readonly string $messageKey = 'ui.backend.generation_text_prohibited',
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $match = $this->profanityFilter->firstMatch($value);
        if ($match !== null) {
            app(BlockedGenerationLogger::class)->logFromValidation($value, $match, $attribute);
            $fail(__($this->messageKey));
        }
    }
}
