<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('generation_errors')) {
            return;
        }

        Schema::create('generation_errors', function (Blueprint $table): void {
            $table->id();
            // dashboard, extension, demo, voice_cloning, fallback
            $table->string('source', 30)->index();
            // error: the member got no audio; warning: they got audio another way
            $table->string('severity', 10)->default('error')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('provider', 30)->nullable();
            $table->string('voice_name', 120)->nullable();
            $table->string('voice_tier', 20)->nullable();
            $table->string('target_language', 10)->nullable();
            $table->string('dialect', 20)->nullable();
            $table->string('exception_class', 190)->nullable();
            $table->text('message');
            $table->string('user_message', 500)->nullable();
            $table->json('context')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_errors');
    }
};
