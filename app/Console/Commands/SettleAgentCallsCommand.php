<?php

namespace App\Console\Commands;

use App\Models\SiteSetting;
use App\Services\Agents\AgentCalls;
use App\Services\Agents\AgentChats;
use App\Support\Agents\AgentAccess;
use App\Support\Agents\AgentVisits;
use Illuminate\Console\Command;

/**
 * Settles agent calls whose browser stopped saying they were on (closed tab,
 * lost connection): each is charged up to its last sign of life and the rest
 * of its hold given back (AgentCalls::settleStale).
 */
class SettleAgentCallsCommand extends Command
{
    protected $signature = 'agents:settle-calls';

    protected $description = 'Settle agent calls that went quiet';

    public function handle(AgentCalls $calls, AgentChats $chats): int
    {
        // Nothing to settle before the agents tables exist (a deploy just ahead of its migration).
        if (! AgentAccess::tablesReady()) {
            return self::SUCCESS;
        }
        $settled = $calls->settleStale();
        if ($settled > 0) {
            $this->info("Settled {$settled} call(s).");
        }
        // Once: the conversations kept before visits were are joined into theirs (AgentVisits),
        // so the admin's list reads the same for last week as for today.
        if (! SiteSetting::get('agents_visits_joined')) {
            $joined = (int) rescue(fn () => AgentVisits::joinPast(), 0, true);
            SiteSetting::set('agents_visits_joined', now()->toIso8601String(), 'agents');
            $this->info("Joined {$joined} conversation(s) into their visits.");
        }
        // Written chats nobody wrote in for a while: closed, their unused credits given back.
        $closed = $chats->closeQuiet();
        if ($closed > 0) {
            $this->info("Closed {$closed} chat(s).");
        }

        return self::SUCCESS;
    }
}
