<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * More calls at the same time on the plans with voice agents (the owner's choice): Starter 3,
 * Creator 5, Pro 10, Studio Max 20, so a busy website rarely finds every line taken (and a caller
 * who does waits for the next free one, or leaves a number to be called back).
 *
 * It changes nothing anyone can spend: a call holds its credits before it starts and counts
 * toward the month's call minutes while it is on. Only a plan still on the number the earlier
 * migration gave it (2026_11_21_120000) changes, so a number set by hand in Admin → Plans stays.
 * Rolled back, the earlier numbers come back the same way.
 */
return new class extends Migration
{
    /** @var array<string, array{0: int, 1: int}> slug => [before, after] */
    private const CALLS = [
        'starter' => [2, 3],
        'creator' => [3, 5],
        'pro' => [5, 10],
        'studio_max' => [10, 20],
    ];

    public function up(): void
    {
        $this->move(0, 1);
    }

    public function down(): void
    {
        $this->move(1, 0);
    }

    private function move(int $from, int $to): void
    {
        if (! Schema::hasTable('plans') || ! Schema::hasColumn('plans', 'agent_concurrent_calls')) {
            return;
        }
        foreach (self::CALLS as $slug => $calls) {
            DB::table('plans')->where('slug', $slug)->where('agent_concurrent_calls', $calls[$from])
                ->update(['agent_concurrent_calls' => $calls[$to]]);
        }
    }
};
