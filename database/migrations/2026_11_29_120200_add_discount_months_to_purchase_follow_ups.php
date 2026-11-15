<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two columns for a discount that also covers the next renewals. A server that ran the
 * discount migration before they were part of it gets them here; on any other this does
 * nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_follow_ups')) {
            return;
        }

        Schema::table('purchase_follow_ups', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_follow_ups', 'discount_months')) {
                $table->unsignedTinyInteger('discount_months')->nullable();
            }
            if (! Schema::hasColumn('purchase_follow_ups', 'discount_coupon_id')) {
                $table->string('discount_coupon_id', 80)->nullable();
            }
        });
    }

    public function down(): void
    {
        // The discount migration removes them with the rest.
    }
};
