<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audio usage was counted in whole minutes per generation (a 6-second clip
 * counted as 1 minute). Count seconds instead; total_minutes is now the
 * month's seconds rounded up once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('usage_counters', 'total_seconds')) {
            Schema::table('usage_counters', function (Blueprint $table): void {
                $table->unsignedInteger('total_seconds')->default(0);
            });
        }
        if (! Schema::hasColumn('billing_trials', 'seconds_used')) {
            Schema::table('billing_trials', function (Blueprint $table): void {
                $table->unsignedInteger('seconds_used')->default(0);
            });
        }
        // True when duration_seconds was read from the audio file, not guessed from the text.
        if (! Schema::hasColumn('audio_generations', 'duration_measured')) {
            Schema::table('audio_generations', function (Blueprint $table): void {
                $table->boolean('duration_measured')->default(false);
            });
        }

        // Rebuild each month's seconds from its finished generations (pack
        // generations were never counted here).
        $totals = [];
        DB::table('audio_generations')
            ->where('status', 'done')
            ->where(fn ($q) => $q->whereNull('billed_to_pack')->orWhere('billed_to_pack', false))
            ->whereNotNull('user_id')
            ->select(['id', 'user_id', 'created_at', 'duration_seconds'])
            ->orderBy('id')
            ->chunk(1000, function ($rows) use (&$totals): void {
                foreach ($rows as $row) {
                    $ym = substr((string) $row->created_at, 0, 7);
                    $totals[$row->user_id][$ym] = ($totals[$row->user_id][$ym] ?? 0) + max(0, (int) $row->duration_seconds);
                }
            });

        foreach ($totals as $userId => $months) {
            foreach ($months as $ym => $seconds) {
                DB::table('usage_counters')
                    ->where('user_id', $userId)
                    ->where('period_ym', $ym)
                    ->update(['total_seconds' => $seconds, 'total_minutes' => intdiv($seconds + 59, 60)]);
            }
        }
    }

    public function down(): void
    {
        foreach (['usage_counters' => 'total_seconds', 'billing_trials' => 'seconds_used', 'audio_generations' => 'duration_measured'] as $table => $column) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
