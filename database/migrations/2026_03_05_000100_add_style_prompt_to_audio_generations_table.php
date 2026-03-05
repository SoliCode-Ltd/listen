<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('audio_generations', function (Blueprint $table) {
            $table->text('style_prompt')->nullable()
                ->after('performance_style')
                ->comment('Custom style prompt for Gemini TTS (Premium feature)');
        });
    }

    public function down(): void
    {
        Schema::table('audio_generations', function (Blueprint $table) {
            $table->dropColumn('style_prompt');
        });
    }
};
