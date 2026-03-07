<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_samples', function (Blueprint $table) {
            $table->boolean('show_in_hero')->default(false)->after('is_active');
            $table->boolean('show_in_showcase')->default(true)->after('show_in_hero');
        });

        DB::table('voice_samples')
            ->where('is_featured', true)
            ->where('is_active', true)
            ->update(['show_in_hero' => true]);

        DB::table('voice_samples')
            ->where('is_active', true)
            ->update(['show_in_showcase' => true]);
    }

    public function down(): void
    {
        Schema::table('voice_samples', function (Blueprint $table) {
            $table->dropColumn(['show_in_hero', 'show_in_showcase']);
        });
    }
};
