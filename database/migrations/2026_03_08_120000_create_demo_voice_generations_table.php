<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('demo_voice_generations', function (Blueprint $table) {
            $table->id();
            $table->string('primary_identity_hash', 255)->index();
            $table->string('link_group', 80)->nullable()->index();
            $table->longText('input_text');
            $table->longText('speech_text')->nullable();
            $table->string('source_language', 10)->nullable();
            $table->string('target_language', 10);
            $table->boolean('translate')->default(false);
            $table->string('voice_key', 60)->nullable();
            $table->string('voice_name', 120)->nullable();
            $table->string('voice_label', 120)->nullable();
            $table->string('voice_provider', 30)->nullable();
            $table->string('voice_tier', 20)->nullable();
            $table->string('audio_path')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('billable_chars')->nullable();
            $table->string('dialect', 10)->nullable();
            $table->string('performance_style', 30)->nullable();
            $table->text('style_prompt')->nullable();
            $table->string('emotion', 20)->nullable();
            $table->decimal('speed', 4, 2)->nullable();
            $table->decimal('pitch', 5, 2)->nullable();
            $table->string('tts_model', 60)->nullable();
            $table->unsignedInteger('tts_latency_ms')->nullable();
            $table->boolean('tts_fallback_used')->default(false);
            $table->decimal('tts_cost_estimate_usd', 10, 6)->nullable();
            $table->unsignedInteger('quota_limit_chars')->nullable();
            $table->unsignedInteger('quota_remaining_chars')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_voice_generations');
    }
};
