<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Google counted for each voice (App\Support\GeminiSpend): the calls it
 * answered and their tokens, across every try. With these set,
 * tts_cost_estimate_usd is the voice's exact cost; empty on voices made before.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['audio_generations', 'demo_voice_generations'] as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'api_calls')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t): void {
                $t->unsignedInteger('api_calls')->nullable()->after('tts_cost_estimate_usd');
                $t->unsignedBigInteger('api_input_tokens')->nullable()->after('api_calls');
                $t->unsignedBigInteger('api_output_tokens')->nullable()->after('api_input_tokens');
            });
        }
    }

    public function down(): void
    {
        foreach (['audio_generations', 'demo_voice_generations'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'api_calls')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->dropColumn(['api_calls', 'api_input_tokens', 'api_output_tokens']);
                });
            }
        }
    }
};
