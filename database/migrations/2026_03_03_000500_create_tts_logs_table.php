<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create tts_logs table for telemetry tracking.
 *
 * Stores per-request telemetry: provider, model, latency, char count,
 * cost estimate, fallback info, failure reason.
 *
 * Used for admin analytics and cost monitoring.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tts_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('audio_generation_id')->nullable()->constrained('audio_generations')->nullOnDelete();

            $table->string('provider', 30)->comment('Provider used');
            $table->string('model', 60)->nullable()->comment('Model used');
            $table->string('routing_mode', 20)->comment('legacy or hybrid');
            $table->string('plan_slug', 60)->nullable();

            $table->unsignedInteger('char_count')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->decimal('cost_estimate_usd', 10, 6)->nullable();

            $table->boolean('fallback_used')->default(false);
            $table->string('failure_reason', 500)->nullable();
            $table->string('target_language', 10)->nullable();
            $table->string('dialect', 10)->nullable();
            $table->string('emotion', 30)->nullable();
            $table->boolean('is_dialogue')->default(false);

            $table->string('status', 20)->default('success')->comment('success, failed, fallback');

            $table->timestamp('created_at')->useCurrent();

            $table->index(['provider', 'created_at'], 'idx_tts_logs_provider_created');
            $table->index(['user_id', 'created_at'], 'idx_tts_logs_user_created');
            $table->index(['status', 'created_at'], 'idx_tts_logs_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tts_logs');
    }
};
