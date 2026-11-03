<?php

namespace App\Jobs;

use App\Models\AgentConversation;
use App\Services\Agents\GeminiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * After a call: one or two sentences on what the caller wanted and what
 * happened, in the call's language, and its outcome (answered, callback,
 * booked, complaint, other), for the owner's conversations list.
 */
class SummarizeAgentCall implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public int $conversationId) {}

    public function handle(GeminiClient $gemini): void
    {
        $c = AgentConversation::find($this->conversationId);
        if (! $c || $c->summary !== null || count($c->transcript ?? []) < 2) {
            return;
        }
        $lines = array_map(fn ($t) => ($t['role'] === 'agent' ? 'Agent' : 'Caller').': '.$t['text'], $c->transcript);
        $language = $c->agent?->language === 'en' ? 'English' : 'Arabic';
        $prompt = "Summarise this phone call between a business's voice assistant and a caller for the business owner.\n"
            ."Write the summary in {$language}, one or two short sentences: what the caller wanted and what happened.\n"
            ."Then pick the outcome: answered, callback, booked, complaint or other.\n\n"
            .Str::limit(implode("\n", $lines), 12000, '');
        $raw = $gemini->generate($prompt, [
            'type' => 'OBJECT',
            'properties' => ['summary' => ['type' => 'STRING'], 'outcome' => ['type' => 'STRING']],
            'required' => ['summary', 'outcome'],
        ], 30, 0.2);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($data) || trim((string) ($data['summary'] ?? '')) === '') {
            return;
        }
        $outcome = in_array($data['outcome'] ?? '', ['answered', 'callback', 'booked', 'complaint', 'other'], true) ? $data['outcome'] : 'other';
        $c->forceFill([
            'summary' => Str::limit(trim((string) $data['summary']), 600, ''),
            'outcome' => $c->lead ? 'callback' : $outcome,
        ])->save();
    }
}
