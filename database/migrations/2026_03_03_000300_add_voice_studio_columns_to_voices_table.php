<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add dialect, emotion, and provider columns to voices table.
 *
 * Extends the voice catalog with Arabic dialect/country mapping,
 * emotion compatibility, and provider-specific IDs.
 *
 * Safe for zero-downtime deployment: all columns are nullable.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('voices', function (Blueprint $table) {
            $table->string('provider', 30)->default('google_cloud')
                ->after('internal_voice_id')
                ->comment('TTS provider: google_cloud, gemini');

            $table->string('provider_voice_id', 160)->nullable()
                ->after('provider')
                ->comment('Provider-specific voice ID (for Gemini, etc.)');

            $table->string('dialect', 10)->nullable()
                ->after('provider_voice_id')
                ->comment('Arabic dialect code (e.g. ar-SA, ar-EG, ar-XA for MSA)');

            $table->string('country', 60)->nullable()
                ->after('dialect')
                ->comment('Country name for display');

            $table->json('supported_emotions')->nullable()
                ->after('country')
                ->comment('JSON array of supported emotion codes');

            $table->string('preview_audio_path', 255)->nullable()
                ->after('supported_emotions')
                ->comment('Path to preview audio sample');

            $table->unsignedSmallInteger('sort_order')->default(0)
                ->after('preview_audio_path')
                ->comment('Display sort order');

            $table->boolean('is_featured')->default(false)
                ->after('sort_order')
                ->comment('Featured voice for homepage/demo');

            $table->json('metadata')->nullable()
                ->after('is_featured')
                ->comment('Additional voice metadata as JSON');

            // Index for filtered queries
            $table->index(['provider', 'language', 'is_active'], 'idx_voices_provider_lang_active');
            $table->index(['dialect', 'is_active'], 'idx_voices_dialect_active');
            $table->index(['is_featured', 'is_active'], 'idx_voices_featured_active');
        });
    }

    public function down(): void
    {
        Schema::table('voices', function (Blueprint $table) {
            $table->dropIndex('idx_voices_provider_lang_active');
            $table->dropIndex('idx_voices_dialect_active');
            $table->dropIndex('idx_voices_featured_active');

            $table->dropColumn([
                'provider',
                'provider_voice_id',
                'dialect',
                'country',
                'supported_emotions',
                'preview_audio_path',
                'sort_order',
                'is_featured',
                'metadata',
            ]);
        });
    }
};
