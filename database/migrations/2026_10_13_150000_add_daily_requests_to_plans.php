<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Most generations a member on the plan may make in one day; 0 = no daily limit.
        if (! Schema::hasColumn('plans', 'daily_requests')) {
            Schema::table('plans', function (Blueprint $table): void {
                $table->unsignedInteger('daily_requests')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('plans', 'daily_requests')) {
            Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('daily_requests'));
        }
    }
};
