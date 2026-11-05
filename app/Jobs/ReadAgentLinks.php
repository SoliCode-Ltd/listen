<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Models\AgentSource;
use App\Services\Agents\LinkReader;
use App\Support\Agents\AgentLinkReads;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads an agent's links (AgentLinkReads) in the background: when the owner adds them, and every
 * few hours after that (agents:read-links). One run at a time for an agent; a run that would go
 * on too long leaves the rest to the next one.
 *
 *   pending → ready (posts, a page, or nothing new on the website's home page)
 *           → failed (with a reason); a link read before keeps what it had
 */
class ReadAgentLinks implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Under the workers' --timeout=150 (README); each link is a request or two of at most 12 s.
    public int $timeout = 140;

    // One waiting run for an agent, also while the hourly runs are spread over the hour.
    public int $uniqueFor = 3600;

    public function __construct(public int $agentId, public bool $due = false) {}

    // The owner's new links are never held up by an hourly run waiting its turn (that one then
    // skips what was just read).
    public function uniqueId(): string
    {
        return $this->agentId.($this->due ? ':due' : '');
    }

    public function handle(LinkReader $reader): void
    {
        $agent = Agent::find($this->agentId);
        if ($agent === null) {
            return;
        }
        if (AgentLinkReads::read($agent, $reader, $this->due, now()->addSeconds(100))) {
            self::dispatch($this->agentId, true);
        }
    }

    public function failed(?\Throwable $e): void
    {
        AgentSource::query()->where('agent_id', $this->agentId)->where('kind', AgentSource::LINK)->where('status', AgentSource::PENDING)
            ->update(['status' => AgentSource::FAILED, 'error' => 'busy', 'updated_at' => now()]);
    }
}
