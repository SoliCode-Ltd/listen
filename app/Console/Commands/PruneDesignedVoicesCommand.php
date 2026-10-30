<?php

namespace App\Console\Commands;

use App\Services\VoiceDesignService;
use Illuminate\Console\Command;

/**
 * Voice Design: deletes the takes nobody kept (each holds one of the 200
 * voices a speech project may store), and takes this site made on a key but
 * never heard back about, a day after they were made.
 */
class PruneDesignedVoicesCommand extends Command
{
    protected $signature = 'voice-design:prune {--minutes= : Delete unkept takes older than this (default '.VoiceDesignService::DRAFT_MINUTES.')}';

    protected $description = 'Delete Voice Design takes that were never kept.';

    public function handle(VoiceDesignService $designer): int
    {
        $minutes = (int) ($this->option('minutes') ?: VoiceDesignService::DRAFT_MINUTES);
        $result = $designer->prune(max(1, $minutes));
        $this->info("Deleted {$result['drafts']} unkept takes and {$result['strays']} lost ones.");

        return self::SUCCESS;
    }
}
