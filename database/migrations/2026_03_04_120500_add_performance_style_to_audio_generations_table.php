<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('audio_generations', function (Blueprint $table) {
            $table->string('performance_style', 30)->nullable()
                ->after('dialect')
                ->comment('Performance style preset (e.g. educational, podcast)');
        });
    }

    public function down(): void
    {
        Schema::table('audio_generations', function (Blueprint $table) {
            $table->dropColumn('performance_style');
        });
    }
};

