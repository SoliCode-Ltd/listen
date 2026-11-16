<?php

namespace App\Services\Transcription;

/**
 * A transcript that cannot start, and why, in words the member reads (the
 * page, the API and MCP show the same message): the code says which rule it
 * met, and $upgrade names the plan that fits where one would.
 */
final class TranscriptionRefused extends \RuntimeException
{
    /**
     * @param  array{plan: ?string, slug: ?string, url: ?string}|null  $upgrade
     * @param  array<string, mixed>  $extra  numbers for the API (seconds needed, left ...)
     */
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422,
        public readonly ?array $upgrade = null, public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['code' => $this->reason, 'message' => $this->getMessage(), 'upgrade' => $this->upgrade] + $this->extra;
    }
}
