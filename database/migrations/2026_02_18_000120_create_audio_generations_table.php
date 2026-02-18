<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audio_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('text_item_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('input_text');
            $table->string('source_language', 10)->nullable();
            $table->string('target_language', 10);
            $table->string('voice_name', 120)->nullable();
            $table->boolean('translate')->default(false);
            $table->string('audio_path')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->string('status', 20)->default('queued'); // queued, processing, done, failed
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_generations');
    }
};
