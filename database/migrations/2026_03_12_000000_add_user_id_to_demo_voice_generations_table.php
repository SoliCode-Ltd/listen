<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('demo_voice_generations', 'user_id')) {
            return;
        }

        Schema::table('demo_voice_generations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('demo_voice_generations', 'user_id')) {
            return;
        }

        Schema::table('demo_voice_generations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
