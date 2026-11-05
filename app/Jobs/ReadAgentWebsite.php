<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Services\Agents\PictureReader;
use App\Services\Agents\SiteReader;
use App\Support\Agents\AgentSite;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Reads every page of an agent's website in the background (AgentSite): when the agent is written
 * from its website, once for an agent made before this, when the owner asks, and every few days
 * for live agents (agents:read-websites). One run at a time for an agent.
 */
class ReadAgentWebsite implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Under the workers' --timeout (README): the pages take at most 80 s, their pictures the rest.
    public int $timeout = 140;

    public int $uniqueFor = 3600;

    // pages: the website's pages; extras: the pictures and PDF files a run had no time for.
    public function __construct(public int $agentId, public string $part = 'pages') {}

    public function uniqueId(): string
    {
        return $this->agentId.($this->part === 'extras' ? ':extras' : '');
    }

    public function handle(SiteReader $reader, PictureReader $pictures): void
    {
        $agent = Agent::find($this->agentId);
        if ($agent === null) {
            Cache::forget('agent-site-reading:'.$this->agentId);

            return;
        }
        if ($this->part === 'extras') {
            AgentSite::readExtras($agent, $pictures, microtime(true) + 120);

            return;
        }
        AgentSite::read($agent, $reader, $pictures, microtime(true) + 120);
    }

    public function failed(?\Throwable $e): void
    {
        Cache::forget('agent-site-reading:'.$this->agentId);
    }
}
