<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('blocked_generations')) {
            return;
        }

        Schema::create('blocked_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('route_name')->nullable();
            $table->string('request_path')->nullable();
            $table->string('attribute', 60)->nullable();
            $table->longText('input_text');
            $table->string('matched_term')->nullable();
            $table->string('matched_language', 12)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['created_at'], 'blocked_generations_created_at_index');
            $table->index(['matched_language', 'created_at'], 'blocked_generations_language_created_at_index');
            $table->index(['user_id', 'created_at'], 'blocked_generations_user_created_at_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('blocked_generations')) {
            return;
        }

        Schema::dropIfExists('blocked_generations');
    }
};
