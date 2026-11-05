<?php

namespace App\Console\Commands;

use App\Jobs\ReadAgentWebsite;
use App\Models\Agent;
use App\Support\Agents\AgentAccess;
use App\Support\Agents\AgentSite;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps every page of live agents' websites read (AgentSite): a live agent whose pages were never
 * read (made before this) gets them read, and the others are read again every few days. The runs
 * a pass sends are spread out, a minute apart.
 */
class ReadAgentWebsitesCommand extends Command
{
    protected $signature = 'agents:read-websites {--limit=10 : Agents a run sends to be read}';

    protected $description = 'Read every page of live agents\' websites again';

    public function handle(): int
    {
        if (! AgentAccess::tablesReady()) {
            return self::SUCCESS;
        }
        $limit = max(1, (int) $this->option('limit'));
        $sent = 0;
        Agent::query()->where('status', Agent::LIVE)->whereNotNull('website_url')->where('website_url', '!=', '')
            ->orderBy('id')->chunkById(200, function ($agents) use (&$sent, $limit) {
                foreach ($agents as $agent) {
                    if ($sent >= $limit) {
                        return false;
                    }
                    if (AgentSite::isDue($agent)) {
                        Cache::put('agent-site-reading:'.$agent->id, true, now()->addMinutes(20 + $sent));
                        ReadAgentWebsite::dispatch($agent->id)->delay(now()->addSeconds($sent * 60));
                        $sent++;
                    }
                }

                return true;
            });
        if ($sent > 0) {
            $this->info("Sent {$sent} agent(s) to have their website read.");
        }

        return self::SUCCESS;
    }
}
