<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An upgrade gives money back for the plan credits not used, and the higher
 * plan starts with its full credits (App\Services\Billing\UnusedCredits). The
 * period the upgrade ends keeps how many of its plan credits were settled that
 * way, so the new period starts without them instead of carrying them on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_counters', function (Blueprint $table) {
            if (! Schema::hasColumn('usage_counters', 'credited_chars')) {
                $table->unsignedBigInteger('credited_chars')->nullable();
            }
            if (! Schema::hasColumn('usage_counters', 'credited_at')) {
                $table->timestamp('credited_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('usage_counters', function (Blueprint $table) {
            foreach (['credited_chars', 'credited_at'] as $column) {
                if (Schema::hasColumn('usage_counters', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
