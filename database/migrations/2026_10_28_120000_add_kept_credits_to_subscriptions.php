<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an admin lowers a plan's monthly credits, the members already on it can
 * keep their credits (App\Services\Billing\KeptCredits): how many, for which
 * plan, and until when (empty: for as long as they stay on that plan).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('kept_monthly_chars')->nullable()->after('current_period_end');
            $table->unsignedBigInteger('kept_plan_id')->nullable()->after('kept_monthly_chars');
            $table->timestamp('kept_until')->nullable()->after('kept_plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['kept_monthly_chars', 'kept_plan_id', 'kept_until']);
        });
    }
};
