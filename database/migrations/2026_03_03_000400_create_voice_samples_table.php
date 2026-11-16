<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create voice_samples table for homepage/demo audio samples.
 *
 * Manageable from admin (CRUD + ordering + featured flags).
 * Supports multiple countries/dialects and emotions.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('voice_samples', function (Blueprint $table) {
            $table->id();

            $table->string('title', 255)->comment('Display title');
            $table->string('title_ar', 255)->nullable()->comment('Arabic display title');
            $table->text('sample_text')->comment('Text that was spoken');
            $table->text('sample_text_ar')->nullable()->comment('Arabic text');
            $table->string('audio_path', 500)->comment('Path to audio file');

            $table->string('language', 10)->default('ar')->comment('Language code');
            $table->string('dialect', 10)->nullable()->comment('Dialect code (e.g. ar-SA)');
            $table->string('country', 60)->nullable()->comment('Country name for display');
            $table->string('emotion', 30)->nullable()->comment('Emotion/style used');
            $table->string('gender', 20)->nullable()->comment('Speaker gender');
            $table->string('voice_name', 160)->nullable()->comment('Voice used for generation');
            $table->string('provider', 30)->nullable()->comment('TTS provider used');

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);

            $table->string('category', 60)->nullable()
                ->comment('Category for grouping: homepage, demo, dialect_showcase, emotion_showcase');

            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'is_featured', 'sort_order'], 'idx_vs_active_featured_sort');
            $table->index(['language', 'dialect', 'is_active'], 'idx_vs_lang_dialect_active');
            $table->index(['category', 'is_active'], 'idx_vs_category_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_samples');
    }
};
