<?php

namespace App\Console\Commands;

use App\Services\Billing\ChatGptAdsConversions;
use Illuminate\Console\Command;

/**
 * Sales for ChatGPT Ads still waiting to be sent from the server: one that could not be
 * sent the moment it was paid (OpenAI busy, the network down) is tried again
 * (ChatGptAdsConversions, SEO Settings → Ad pixels and tracking code).
 */
class SendChatGptAdsEventsCommand extends Command
{
    protected $signature = 'listen:chatgpt-ads-send';

    protected $description = 'Send the paid plans still waiting to go to ChatGPT Ads (Conversions API)';

    public function handle(ChatGptAdsConversions $conversions): int
    {
        $sent = $conversions->sendPending();
        if ($sent > 0) {
            $this->info("Sent to ChatGPT Ads: {$sent}.");
        }

        return self::SUCCESS;
    }
}
