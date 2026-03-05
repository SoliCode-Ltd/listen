<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Hybrid TTS columns to plans table.
 *
 * Adds per-plan provider policy, dialogue support flag,
 * and quality tier override for the hybrid TTS architecture.
 *
 * Safe for zero-downtime deployment: all columns are nullable or have defaults.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('tts_provider_policy', 30)->default('auto')
                ->after('voice_tier')
                ->comment('auto|google_cloud|gemini – per-plan provider override');

            $table->boolean('dialogue_enabled')->default(false)
                ->after('tts_provider_policy')
                ->comment('Whether this plan supports multi-speaker dialogue');

            $table->string('quality_tier', 30)->nullable()
                ->after('dialogue_enabled')
                ->comment('Provider quality mapping (e.g. wavenet, neural2, studio)');

            $table->unsignedInteger('queue_priority_weight')->default(100)
                ->after('quality_tier')
                ->comment('Queue priority weight (higher = more priority)');
        });

        // Set dialogue_enabled for highest plan
        \Illuminate\Support\Facades\DB::table('plans')
            ->where('slug', 'pro')
            ->update(['dialogue_enabled' => true, 'quality_tier' => 'studio']);

        \Illuminate\Support\Facades\DB::table('plans')
            ->where('slug', 'creator')
            ->update(['quality_tier' => 'wavenet']);

        \Illuminate\Support\Facades\DB::table('plans')
            ->where('slug', 'free')
            ->update(['quality_tier' => 'standard']);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'tts_provider_policy',
                'dialogue_enabled',
                'quality_tier',
                'queue_priority_weight',
            ]);
        });
    }
};
