<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('voices', function (Blueprint $table) {
            $table->id();
            $table->string('language', 10);
            $table->string('gender', 20);
            $table->string('tier', 20);
            $table->string('display_name', 120);
            $table->string('internal_voice_id', 160)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['language', 'tier', 'is_active']);
            $table->index(['language', 'gender', 'tier', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voices');
    }
};
