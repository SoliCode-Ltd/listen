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

    public function handle(AgentCalls $calls): int
    {
        $settled = $calls->settleStale();
        if ($settled > 0) {
            $this->info("Settled {$settled} call(s).");
        }

        return self::SUCCESS;
    }
}
