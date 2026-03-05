<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('custom_prompt_enabled')->default(false)->after('dialogue_enabled');
        });

        // Enable custom prompt for HD tier plans by default
        DB::table('plans')
            ->where('voice_tier', 'hd')
            ->update(['custom_prompt_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('custom_prompt_enabled');
        });
    }
};
