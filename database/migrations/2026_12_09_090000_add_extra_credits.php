<?php

use App\Support\OnlineSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra credits a paid member buys when the month's credits run out
 * (App\Services\Billing\ExtraCredits). No new tables and no new subscription rows:
 *
 * - usage_counters (the member's row for one credit period) holds them, beside the
 *   credits Listen gifts: extra_chars bought or carried into this period, the plan's
 *   own credits for the period (extra_base_chars: the plan's credits are used first),
 *   when the period ends (extra_ends_at), and once the period is over, when what was
 *   left moved on to the next period or ended (extra_settled_at, extra_outcome);
 * - char_pack_purchases (every one-time purchase, with its card payment or its payment by
 *   hand) records each purchase: kind tells a pack from extra credits, and period_ym is
 *   the credit period the credits were added to.
 *
 * Columns are only added when missing, so this is safe to run again; it waits a few
 * seconds at most for a busy table (App\Support\OnlineSchema).
 */
return new class extends Migration
{
    public function up(): void
    {
        OnlineSchema::change(function (): void {
            if (Schema::hasTable('usage_counters') && ! Schema::hasColumn('usage_counters', 'extra_chars')) {
                Schema::table('usage_counters', function (Blueprint $table): void {
                    $table->unsignedBigInteger('extra_chars')->default(0);
                    $table->unsignedBigInteger('extra_base_chars')->default(0);
                    $table->timestamp('extra_ends_at')->nullable();
                    $table->timestamp('extra_settled_at')->nullable();
                    $table->string('extra_outcome', 10)->nullable();
                });
            }

            if (Schema::hasTable('char_pack_purchases') && ! Schema::hasColumn('char_pack_purchases', 'kind')) {
                Schema::table('char_pack_purchases', function (Blueprint $table): void {
                    $table->string('kind', 20)->default('pack');
                    $table->string('period_ym', 20)->nullable();
                    $table->index(['kind', 'status']);
                });
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('char_pack_purchases', 'kind')) {
            Schema::table('char_pack_purchases', function (Blueprint $table): void {
                $table->dropIndex(['kind', 'status']);
                $table->dropColumn(['kind', 'period_ym']);
            });
        }
        if (Schema::hasColumn('usage_counters', 'extra_chars')) {
            Schema::table('usage_counters', function (Blueprint $table): void {
                $table->dropColumn(['extra_chars', 'extra_base_chars', 'extra_ends_at', 'extra_settled_at', 'extra_outcome']);
            });
        }
    }
};
