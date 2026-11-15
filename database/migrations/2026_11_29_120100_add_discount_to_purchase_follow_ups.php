<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A declined renewal can be given a discount from Newsletter → Purchase follow-ups: the
 * case keeps what was taken off, so its email says the lower price.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_follow_ups') || Schema::hasColumn('purchase_follow_ups', 'discount_percent')) {
            return;
        }

        Schema::table('purchase_follow_ups', function (Blueprint $table): void {
            $table->unsignedTinyInteger('discount_percent')->nullable();
            // What was taken off the unpaid payment, in its currency's smallest unit.
            $table->unsignedInteger('discount_cents')->nullable();
            $table->timestamp('discounted_at')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_follow_ups') && Schema::hasColumn('purchase_follow_ups', 'discount_percent')) {
            Schema::table('purchase_follow_ups', function (Blueprint $table): void {
                $table->dropColumn(['discount_percent', 'discount_cents', 'discounted_at']);
            });
        }
    }
};
