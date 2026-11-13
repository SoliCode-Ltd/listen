<?php

namespace App\Console\Commands;

use App\Services\Agents\AgentCalls;
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

    public function handle(AgentCalls $calls, \App\Services\Agents\AgentChats $chats): int
    {
        // Nothing to settle before the agents tables exist (a deploy just ahead of its migration).
        if (! \App\Support\Agents\AgentAccess::tablesReady()) {
            return self::SUCCESS;
        }
        $settled = $calls->settleStale();
        if ($settled > 0) {
            $this->info("Settled {$settled} call(s).");
        }
        // Written chats nobody wrote in for a while: closed, their unused credits given back.
        $closed = $chats->closeQuiet();
        if ($closed > 0) {
            $this->info("Closed {$closed} chat(s).");
        }

        return self::SUCCESS;
    }
}
