<?php

namespace App\Console\Commands;

use App\Models\AudioGeneration;
use App\Services\Billing\MemberUsageMeter;
use App\Support\GenerationMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Fails, and gives the credits back for, members' own generations (the
 * studio and the browser extension) that got stuck: a web request killed
 * while it made the voice (a time or memory limit, a restart, a deploy), or a
 * queued job no worker picked up. Without this they would hold the member's
 * credits for good and show as "processing" forever.
 *
 * Each one is failed and refunded in one locked step (MemberUsageMeter::
 * failAndRefund), so a job that wakes up later never makes it or refunds it
 * again. Developer API generations have their own sweep
 * (api:expire-stuck-generations). Runs every five minutes (routes/console.php).
 */
class ExpireStuckMemberGenerationsCommand extends Command
{
    protected $signature = 'listen:expire-stuck-generations';

    protected $description = "Fail and refund members' generations stuck in queued or processing.";

    /** Well above the longest a job may take with its retries (3 tries of 150 s, 180 s apart). */
    private const PROCESSING_MINUTES = 30;

    /** A queued one waits for a worker, so it gets longer. */
    private const QUEUED_MINUTES = 60;

    public function handle(MemberUsageMeter $meter): int
    {
        $rules = [
            'processing' => now()->subMinutes(self::PROCESSING_MINUTES),
            'queued' => now()->subMinutes(self::QUEUED_MINUTES),
        ];
        // Before the developer API migration has run, every generation is a member's.
        $apiColumn = Schema::hasColumn('audio_generations', 'api_key_id');
        $message = GenerationMessage::stored('ui.backend.generation_stuck_refunded');

        $expired = 0;
        foreach ($rules as $status => $before) {
            AudioGeneration::query()
                ->when($apiColumn, fn ($query) => $query->whereNull('api_key_id'))
                ->where('status', $status)
                ->where('updated_at', '<', $before)
                ->orderBy('id')
                ->limit(500)
                ->pluck('id')
                ->each(function (int $id) use ($meter, $status, $before, $message, &$expired): void {
                    // Checked again, as it may have moved on meanwhile (failAndRefund then
                    // checks its status once more under the row lock).
                    $stillStuck = AudioGeneration::query()->whereKey($id)->where('status', $status)->where('updated_at', '<', $before)->exists();
                    if ($stillStuck && $meter->failAndRefund($id, $message, [$status])) {
                        $expired++;
                    }
                });
        }

        $this->info("Expired {$expired} stuck member generation(s).");

        return self::SUCCESS;
    }
}
