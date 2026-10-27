<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A member's own pronunciations: a word as it is written and how every new
 * voice should say it (App\Support\Pronunciations). One row per word: the key
 * is the word without vowel marks, so «هيتكرر» and «هيتكرّر» are the same word.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pronunciations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('word', 60);
            $table->string('word_key', 60);
            $table->string('spoken', 80);
            $table->timestamps();

            $table->unique(['user_id', 'word_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pronunciations');
    }
};
