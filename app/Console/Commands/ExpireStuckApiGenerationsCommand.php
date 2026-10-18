<?php

namespace App\Console\Commands;

use App\Models\AudioGeneration;
use App\Services\Api\ApiUsageMeter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Fails, and gives the credits back for, developer API generations that got
 * stuck: a request that waited for the file and whose web process was killed
 * (a time or memory limit, a restart, a deploy), or a background job that no
 * worker picked up. Without this they would hold the member's credits, the
 * key's budget and a place in the member's queue for good.
 *
 * Each one is failed and refunded in one locked step (ApiUsageMeter::
 * failAndRefund), so a job that wakes up later never makes it or refunds it
 * again. Runs every five minutes (routes/console.php).
 */
class ExpireStuckApiGenerationsCommand extends Command
{
    protected $signature = 'api:expire-stuck-generations';

    protected $description = 'Fail and refund developer API generations stuck in queued or processing.';

    public function handle(ApiUsageMeter $meter): int
    {
        // Between a deploy and its migrations there are no API generations to look at.
        if (! Schema::hasColumn('audio_generations', 'api_key_id')) {
            return self::SUCCESS;
        }

        $rules = [
            'processing' => now()->subMinutes(max(10, (int) config('listen.api.stuck_processing_minutes', 30))),
            'queued' => now()->subMinutes(max(10, (int) config('listen.api.stuck_queued_minutes', 60))),
        ];

        $expired = 0;
        foreach ($rules as $status => $before) {
            AudioGeneration::query()
                ->whereNotNull('api_key_id')
                ->where('status', $status)
                ->where('updated_at', '<', $before)
                ->orderBy('id')
                ->limit(500)
                ->pluck('id')
                ->each(function (int $id) use ($meter, $status, $before, &$expired): void {
                    // Checked again, as it may have moved on meanwhile (failAndRefund then
                    // checks its status once more under the row lock).
                    $stillStuck = AudioGeneration::query()->whereKey($id)->where('status', $status)->where('updated_at', '<', $before)->exists();
                    if ($stillStuck && $meter->failAndRefund($id, 'Voice generation took too long and was stopped. No credits were charged; please try again.', [$status])) {
                        $expired++;
                    }
                });
        }

        $this->info("Expired {$expired} stuck API generation(s).");

        return self::SUCCESS;
    }
}
