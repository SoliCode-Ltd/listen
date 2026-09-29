<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_trials', function (Blueprint $table) {
            $table->string('billing_cycle', 10)->default('monthly')->after('quota_plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('billing_trials', function (Blueprint $table) {
            $table->dropColumn('billing_cycle');
        });
    }
};
