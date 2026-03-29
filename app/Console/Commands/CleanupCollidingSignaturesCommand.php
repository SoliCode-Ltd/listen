<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanupCollidingSignaturesCommand extends Command
{
    protected $signature = 'listen:cleanup-signatures
        {--threshold=2 : Signatures shared by more than this many users are considered collisions}
        {--fix : Actually null out the colliding signatures (dry-run by default)}';

    protected $description = 'Find and remove request signatures that collide across too many users (caused by the low-entropy fingerprint bug).';

    public function handle(): int
    {
        if (! Schema::hasColumn('users', 'registration_request_signature')
            || ! Schema::hasColumn('users', 'last_seen_request_signature')) {
            $this->warn('Request signature columns do not exist. Nothing to clean.');

            return self::SUCCESS;
        }

        $threshold = max(1, (int) $this->option('threshold'));
        $fix = (bool) $this->option('fix');

        $this->info("Threshold: signatures shared by more than {$threshold} users will be cleaned.");
        $this->info($fix ? 'MODE: FIX (will null out colliding signatures)' : 'MODE: DRY-RUN (preview only)');
        $this->newLine();

        // Find all registration_request_signature values shared by > threshold users
        $collidingRegSigs = DB::table('users')
            ->select('registration_request_signature as sig')
            ->selectRaw('COUNT(*) as cnt')
            ->whereNotNull('registration_request_signature')
            ->where('registration_request_signature', '!=', '')
            ->groupBy('registration_request_signature')
            ->havingRaw('COUNT(*) > ?', [$threshold])
            ->orderByDesc('cnt')
            ->get();

        // Find all last_seen_request_signature values shared by > threshold users
        $collidingSeenSigs = DB::table('users')
            ->select('last_seen_request_signature as sig')
            ->selectRaw('COUNT(*) as cnt')
            ->whereNotNull('last_seen_request_signature')
            ->where('last_seen_request_signature', '!=', '')
            ->groupBy('last_seen_request_signature')
            ->havingRaw('COUNT(*) > ?', [$threshold])
            ->orderByDesc('cnt')
            ->get();

        // Merge all colliding signature hashes
        $allColliding = $collidingRegSigs->pluck('sig')
            ->merge($collidingSeenSigs->pluck('sig'))
            ->unique()
            ->values();

        if ($allColliding->isEmpty()) {
            $this->info('No colliding signatures found. Database is clean.');

            return self::SUCCESS;
        }

        // Show a summary table
        $sigCounts = collect();
        foreach ($collidingRegSigs as $row) {
            $sigCounts[$row->sig] = ($sigCounts[$row->sig] ?? 0) + $row->cnt;
        }
        foreach ($collidingSeenSigs as $row) {
            $sigCounts[$row->sig] = max($sigCounts[$row->sig] ?? 0, $row->cnt);
        }

        $rows = [];
        foreach ($allColliding as $sig) {
            $regCount = (int) DB::table('users')
                ->where('registration_request_signature', $sig)
                ->count();
            $seenCount = (int) DB::table('users')
                ->where('last_seen_request_signature', $sig)
                ->count();

            $rows[] = [
                substr($sig, 0, 16) . '...',
                $regCount,
                $seenCount,
            ];
        }

        $this->table(
            ['Signature (truncated)', 'In registration_*', 'In last_seen_*'],
            $rows
        );

        // Count total affected users
        $affectedUsers = DB::table('users')
            ->where(function ($q) use ($allColliding) {
                $q->whereIn('registration_request_signature', $allColliding)
                    ->orWhereIn('last_seen_request_signature', $allColliding);
            })
            ->count();

        $this->newLine();
        $this->info("Found {$allColliding->count()} colliding signature(s) affecting {$affectedUsers} user(s).");

        if (! $fix) {
            $this->newLine();
            $this->warn('Run with --fix to null out these signatures.');

            return self::SUCCESS;
        }

        // Null out colliding signatures
        $updatedReg = DB::table('users')
            ->whereIn('registration_request_signature', $allColliding)
            ->update(['registration_request_signature' => null]);

        $updatedSeen = DB::table('users')
            ->whereIn('last_seen_request_signature', $allColliding)
            ->update(['last_seen_request_signature' => null]);

        Cache::forget('admin:suspicious_users_count');

        $this->newLine();
        $this->info("Cleaned {$updatedReg} registration_request_signature value(s).");
        $this->info("Cleaned {$updatedSeen} last_seen_request_signature value(s).");
        $this->info('Suspicious users cache cleared.');

        return self::SUCCESS;
    }
}
