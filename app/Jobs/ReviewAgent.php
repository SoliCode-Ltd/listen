<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Services\Agents\AgentReview;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A live agent whose setup changed is checked again (AgentReview): one now set
 * up to deceive or harm callers is paused, and its owner sees why.
 */
class ReviewAgent implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public int $agentId) {}

    public function handle(AgentReview $review): void
    {
        $agent = Agent::find($this->agentId);
        if (! $agent || ! $agent->isLive()) {
            return;
        }
        $result = $review->check($agent);
        if ($result['verdict'] === AgentReview::BLOCK) {
            $agent->forceFill(['status' => Agent::PAUSED])->save();
        }
    }
}
