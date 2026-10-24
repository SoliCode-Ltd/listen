<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Abeer (Arabic, premium) speaks with Zephyr instead of Pulcherrima, which
 * sounded like a man. Her id keeps its old name, so past audio, favourites and
 * API calls still find her; the speech provider maps that id to Zephyr.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('voices')
            ->where('internal_voice_id', 'ar-Gemini-HD-Pulcherrima')
            ->where('provider_voice_id', 'Pulcherrima')
            ->update(['provider_voice_id' => 'Zephyr', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('voices')
            ->where('internal_voice_id', 'ar-Gemini-HD-Pulcherrima')
            ->where('provider_voice_id', 'Zephyr')
            ->update(['provider_voice_id' => 'Pulcherrima', 'updated_at' => now()]);
    }
};
