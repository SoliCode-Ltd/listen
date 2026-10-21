<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A dub keeps the credit period it was paid from, as the usage counters
     * do: once credits follow the plan's dates that is "2026-10-20 20:17",
     * which "2026-10" had room for only. The other period columns were made
     * this wide by 2026_10_21_120000_credits_follow_plan_dates.
     */
    public function up(): void
    {
        if (Schema::hasTable('video_dubs') && Schema::hasColumn('video_dubs', 'period_ym')) {
            Schema::table('video_dubs', function (Blueprint $table): void {
                $table->string('period_ym', 20)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Left wide: narrowing it again would cut the periods already kept.
    }
};
