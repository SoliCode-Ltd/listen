<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('voice_cloning_enabled')
                ->default(false)
                ->after('custom_prompt_enabled')
                ->comment('Whether this plan allows voice cloning');

            $table->unsignedInteger('voice_cloning_max_voices')
                ->default(0)
                ->after('voice_cloning_enabled')
                ->comment('Max cloned voices for this plan (0 = use global setting)');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['voice_cloning_enabled', 'voice_cloning_max_voices']);
        });
    }
};
