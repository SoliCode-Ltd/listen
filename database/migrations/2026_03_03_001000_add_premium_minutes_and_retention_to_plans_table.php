<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add premium voice minutes allowance and recent voices retention
 * columns to the plans table for the new 5-tier pricing model.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('premium_voice_minutes')->default(0)
                ->after('monthly_chars')
                ->comment('Monthly premium (Gemini) voice minutes included');

            $table->unsignedInteger('recent_voices_days')->default(7)
                ->after('queue_priority_weight')
                ->comment('Days recent voices are retained (0 = unlimited)');

            $table->string('topup_char_price_cents', 20)->nullable()
                ->after('overage_price_per_million_cents')
                ->comment('Per-million char top-up price in cents');

            $table->string('topup_minute_price_cents', 20)->nullable()
                ->after('topup_char_price_cents')
                ->comment('Per-minute premium voice top-up price in cents');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'premium_voice_minutes',
                'recent_voices_days',
                'topup_char_price_cents',
                'topup_minute_price_cents',
            ]);
        });
    }
};
