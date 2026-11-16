<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('audio_generations', function (Blueprint $table) {
            $table->string('voice_tier', 20)->default('standard')->after('target_language');
        });
    }

    public function down(): void
    {
        Schema::table('audio_generations', function (Blueprint $table) {
            $table->dropColumn('voice_tier');
        });
    }
};
