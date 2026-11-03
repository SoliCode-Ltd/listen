<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\Agents\LiveSession;
use Illuminate\Console\Command;

/**
 * Prints the live call setup of a sample agent as JSON (nothing is saved):
 * what the agent voice test locks into a short-lived key, so the test checks
 * the very setup the agents use.
 */
class AgentLiveSetupCommand extends Command
{
    protected $signature = 'agents:live-setup
        {--dialect=ar-SA : The agent\'s dialect}
        {--voice=Achernar : The live voice}
        {--name=Fatima : The agent\'s name}';

    protected $description = 'Print a sample agent\'s live call setup as JSON (for the voice test)';

    public function handle(): int
    {
        $agent = new Agent([
            'name' => (string) $this->option('name'),
            'business_name' => 'Listen',
            'business_type' => 'services',
            'jobs' => ['answer', 'leads'],
            'language' => 'ar',
            'dialect' => (string) $this->option('dialect'),
            'live_voice' => (string) $this->option('voice'),
            'first_message' => null,
            'script' => [
                'identity' => 'A warm, efficient receptionist for Listen.',
                'never' => ['Never promise a discount.'],
                'steps' => ['Find what the caller needs.', 'Answer from the facts.', 'For a callback, take their name and phone number, read the number back, then save it.'],
                'handoff' => 'When the caller asks for a person or for a custom team plan.',
            ],
            'knowledge' => "Listen turns text into natural speech in many Arabic dialects and English accents.\nMonthly plans: Basic $4 (20 minutes), Starter $9 (100 minutes), Creator $22 (200 minutes), Pro $49 (500 minutes), Studio Max $99 (2,000 minutes).\nPayment: card, Apple Pay, Google Pay, InstaPay or Vodafone Cash.",
        ]);

        $this->line(json_encode(LiveSession::setupFor($agent), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
