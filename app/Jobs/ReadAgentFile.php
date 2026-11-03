<?php

namespace App\Jobs;

use App\Models\AgentSource;
use App\Services\Agents\FileReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads a file an agent's owner uploaded (FileReader), in the background so the upload
 * answers at once; the Knowledge tab shows it reading, then what the agent learned.
 *
 *   pending → reading → ready (or ready, partial)
 *                     → failed (with a reason)
 */
class ReadAgentFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    // Under the workers' --timeout=150 (README), with room for the reader's 110 s model call.
    public int $timeout = 140;

    public function __construct(public int $sourceId) {}

    public function handle(FileReader $reader): void
    {
        $source = AgentSource::find($this->sourceId);
        // Removed before it was read, or already read by an earlier try.
        if ($source === null || ! $source->isUpload() || $source->status === AgentSource::READY) {
            return;
        }
        $reader->read($source);
    }

    public function failed(?\Throwable $e): void
    {
        AgentSource::whereKey($this->sourceId)->whereIn('status', [AgentSource::PENDING, AgentSource::READING])
            ->update(['status' => AgentSource::FAILED, 'error' => 'busy', 'read_at' => now()]);
    }
}
