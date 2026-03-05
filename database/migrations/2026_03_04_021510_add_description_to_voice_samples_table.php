<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('voice_samples', function (Blueprint $table) {
            $table->string('description', 500)->nullable()->after('title_ar')
                ->comment('Short description / subtitle for display');
            $table->string('description_ar', 500)->nullable()->after('description')
                ->comment('Arabic short description / subtitle');
            $table->string('icon', 100)->nullable()->after('description_ar')
                ->comment('Icon identifier (e.g. flag emoji or class)');
        });
    }

    public function down(): void
    {
        Schema::table('voice_samples', function (Blueprint $table) {
            $table->dropColumn(['description', 'description_ar', 'icon']);
        });
    }
};
