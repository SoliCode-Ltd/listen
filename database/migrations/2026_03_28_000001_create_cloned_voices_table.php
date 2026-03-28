<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloned_voices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('source_type', ['upload', 'recording'])->default('upload');
            $table->string('source_audio_path');
            $table->string('consent_audio_path')->nullable();
            $table->unsignedInteger('source_audio_duration_seconds')->default(0);
            $table->string('language_code', 10)->default('ar-XA');
            $table->string('provider')->default('google_cloud_chirp3');
            $table->text('provider_voice_key')->nullable()->comment('Chirp 3 voice_cloning_key from Google');
            $table->enum('status', ['pending', 'validating', 'processing', 'ready', 'failed'])->default('pending');
            $table->text('failure_reason')->nullable();
            $table->boolean('consent_confirmed')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloned_voices');
    }
};
