<?php

namespace App\Exceptions;

use RuntimeException;

class TrialStartException extends RuntimeException
{
    public function __construct(
        public readonly string $errorKey,
        public readonly int $status = 422,
        public readonly bool $blocked = false,
        public readonly ?string $reason = null,
    ) {
        parent::__construct($errorKey);
    }
}
