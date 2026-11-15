<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A declined renewal can be given a discount from Newsletter → Purchase follow-ups, on
 * that renewal only or on the next months too: the case keeps what was given, so its email
 * says the lower price and how long it lasts.
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
            // The same discount on this many renewals after it (a monthly plan), or null: this one only.
            $table->unsignedTinyInteger('discount_months')->nullable();
            // The one-member coupon on their subscription that carries those months.
            $table->string('discount_coupon_id', 80)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_follow_ups') && Schema::hasColumn('purchase_follow_ups', 'discount_percent')) {
            Schema::table('purchase_follow_ups', function (Blueprint $table): void {
                $table->dropColumn(['discount_percent', 'discount_cents', 'discounted_at', 'discount_months', 'discount_coupon_id']);
            });
        }
    }
};
