<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A member's transcripts ("Transcribe", App\Models\Transcription): the audio
 * (kept for TranscriptionSettings::keepAudioDays()), its text as paragraphs
 * with the speaker of each and every sentence on the recording's clock, the
 * seconds it used of the month's minutes (held when it starts, given back
 * when it fails) and what it cost Listen. A transcript the member deletes
 * stays counted in its month (soft delete), so minutes are never won back
 * by deleting.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transcriptions')) {
            return;
        }
        Schema::create('transcriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32)->unique();
            $table->string('title', 200);
            $table->string('source', 16)->default('upload');
            $table->string('source_url', 2048)->nullable();
            $table->string('audio_path')->nullable();
            $table->timestamp('audio_deleted_at')->nullable();
            $table->decimal('seconds', 10, 2)->default(0);
            $table->string('language_requested', 8)->default('auto');
            $table->string('language', 8)->nullable();
            $table->string('dialect', 16)->nullable();
            $table->string('status', 16)->default('queued')->index();
            $table->unsignedSmallInteger('parts')->default(0);
            $table->unsignedSmallInteger('parts_done')->default(0);
            $table->json('work')->nullable();
            $table->json('paragraphs')->nullable();
            $table->json('segments')->nullable();
            $table->json('speaker_names')->nullable();
            $table->json('translations')->nullable();
            $table->unsignedInteger('charged_seconds')->default(0);
            $table->boolean('is_free')->default(false);
            $table->string('period_ym', 32)->nullable();
            $table->boolean('priority')->default(false);
            $table->foreignId('api_key_id')->nullable();
            $table->unsignedInteger('requests')->default(0);
            $table->json('usage')->nullable();
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->string('model', 64)->nullable();
            $table->json('notes')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'period_ym']);
            $table->index(['user_id', 'created_at']);
            $table->index(['is_free', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcriptions');
    }
};
