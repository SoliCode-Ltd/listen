<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add TTS telemetry and voice studio columns to audio_generations table.
 *
 * Extends existing table with provider tracking, studio controls (dialect, emotion),
 * dialogue metadata, and cost tracking.
 *
 * Safe for zero-downtime deployment: all columns are nullable.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('audio_generations', function (Blueprint $table) {
            // Provider telemetry
            $table->string('tts_provider', 30)->nullable()
                ->after('voice_name')
                ->comment('Provider used: google_cloud, gemini, cache');

            $table->string('tts_model', 60)->nullable()
                ->after('tts_provider')
                ->comment('Specific model used (e.g. gemini-2.5-flash-preview-tts)');

            $table->unsignedInteger('tts_latency_ms')->nullable()
                ->after('tts_model')
                ->comment('TTS API latency in milliseconds');

            $table->boolean('tts_fallback_used')->default(false)
                ->after('tts_latency_ms')
                ->comment('Whether fallback provider was used');

            $table->decimal('tts_cost_estimate_usd', 10, 6)->nullable()
                ->after('tts_fallback_used')
                ->comment('Estimated cost in USD');

            // Voice studio controls
            $table->string('dialect', 10)->nullable()
                ->after('tts_cost_estimate_usd')
                ->comment('Arabic dialect code (e.g. ar-SA, ar-EG)');

            $table->string('emotion', 20)->nullable()
                ->after('dialect')
                ->comment('Emotion/style (e.g. warm, sad, storytelling)');

            $table->decimal('speed', 4, 2)->nullable()
                ->after('emotion')
                ->comment('Speech speed (0.25-4.0)');

            $table->decimal('pitch', 5, 2)->nullable()
                ->after('speed')
                ->comment('Speech pitch (-20.0 to 20.0)');

            // Dialogue mode
            $table->boolean('is_dialogue')->default(false)
                ->after('pitch')
                ->comment('Whether this is a multi-speaker dialogue generation');

            $table->json('dialogue_metadata')->nullable()
                ->after('is_dialogue')
                ->comment('JSON: speaker assignments, voice configs, etc.');

            // Character tracking
            $table->unsignedInteger('billable_chars')->nullable()
                ->after('dialogue_metadata')
                ->comment('Actual billable character count');

            // Index for telemetry queries
            $table->index(['tts_provider', 'created_at'], 'idx_ag_provider_created');
            $table->index(['is_dialogue', 'created_at'], 'idx_ag_dialogue_created');
        });
    }

    public function down(): void
    {
        Schema::table('audio_generations', function (Blueprint $table) {
            $table->dropIndex('idx_ag_provider_created');
            $table->dropIndex('idx_ag_dialogue_created');

            $table->dropColumn([
                'tts_provider',
                'tts_model',
                'tts_latency_ms',
                'tts_fallback_used',
                'tts_cost_estimate_usd',
                'dialect',
                'emotion',
                'speed',
                'pitch',
                'is_dialogue',
                'dialogue_metadata',
                'billable_chars',
            ]);
        });
    }
};
