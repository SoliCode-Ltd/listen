<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an admin lowers a plan's monthly credits, the members already on it can
 * keep their credits (App\Services\Billing\KeptCredits): for good while they
 * are on that plan, and/or for the period they are in (until it renews), and
 * when they left the plan, in case they come back to it soon.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('kept_plan_id')->nullable()->after('current_period_end');
            $table->unsignedBigInteger('kept_monthly_chars')->nullable()->after('kept_plan_id');
            $table->unsignedBigInteger('kept_period_chars')->nullable()->after('kept_monthly_chars');
            $table->timestamp('kept_period_start')->nullable()->after('kept_period_chars');
            $table->timestamp('kept_left_at')->nullable()->after('kept_period_start');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['kept_plan_id', 'kept_monthly_chars', 'kept_period_chars', 'kept_period_start', 'kept_left_at']);
        });
    }
};
