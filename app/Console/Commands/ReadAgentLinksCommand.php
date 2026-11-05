<?php

namespace App\Console\Commands;

use App\Jobs\ReadAgentLinks;
use App\Models\Agent;
use App\Models\AgentSource;
use App\Support\Agents\AgentAccess;
use App\Support\Agents\AgentLinkReads;
use Illuminate\Console\Command;

/**
 * Keeps what agents know from their links new (AgentLinkReads): the links of live agents (and of
 * agents changed this week) are read again every few hours, and a live agent whose links were
 * never read (made before links were read) gets them read.
 */
class ReadAgentLinksCommand extends Command
{
    protected $signature = 'agents:read-links {--limit=100 : Agents a run sends to be read}';

    protected $description = 'Read the newest posts on agents\' links again';

    public function handle(): int
    {
        if (! AgentAccess::tablesReady()) {
            return self::SUCCESS;
        }
        $limit = max(1, (int) $this->option('limit'));
        $sent = 0;

        // Live agents with links that were never read.
        Agent::query()->where('status', Agent::LIVE)
            ->whereDoesntHave('sources', fn ($q) => $q->where('kind', AgentSource::LINK))
            ->chunkById(200, function ($agents) use (&$sent, $limit) {
                foreach ($agents as $agent) {
                    if ($sent < $limit && AgentLinkReads::prepare($agent)) {
                        ReadAgentLinks::dispatch($agent->id);
                        $sent++;
                    }
                }

                return $sent < $limit;
            });

        // Links not tried for a while, not read the last time (tried again after an hour), or still
        // waiting after a run that did not finish.
        $due = AgentSource::query()->where('kind', AgentSource::LINK)
            ->where(fn ($q) => $q->where('updated_at', '<', now()->subHours(AgentLinkReads::EVERY_HOURS))
                ->orWhere(fn ($q) => $q->where('status', AgentSource::PENDING)->where('updated_at', '<', now()->subMinutes(15)))
                ->orWhere(fn ($q) => $q->whereNotNull('error')->whereNotIn('error', AgentLinkReads::LASTING)
                    ->where('updated_at', '<', now()->subMinutes(AgentLinkReads::FAILED_AGAIN_MINUTES - 10))))
            ->whereHas('agent', fn ($q) => $q->where('status', Agent::LIVE)->orWhere('updated_at', '>', now()->subDays(7)))
            ->orderBy('updated_at')->limit(max(0, $limit - $sent) * 10)->pluck('agent_id')->unique()->take(max(0, $limit - $sent));
        // Spread over the hour (half a minute apart, at most 50 minutes), so a site that allows each
        // server only a few reads at a time (X) is not asked for many accounts at once.
        foreach ($due->values() as $i => $agentId) {
            ReadAgentLinks::dispatch((int) $agentId, true)->delay(now()->addSeconds(min(3000, $i * 30)));
            $sent++;
        }
        if ($sent > 0) {
            $this->info("Sent {$sent} agent(s) to have their links read.");
        }

        return self::SUCCESS;
    }
}
