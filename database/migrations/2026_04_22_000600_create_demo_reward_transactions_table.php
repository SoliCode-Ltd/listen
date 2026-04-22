<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ledger for gifted demo-only reward characters.
     *
     * Positive `delta_chars` = grant (credit). Negative = consumption (debit).
     * The user's current reward balance is SUM(delta_chars) WHERE user_id = X.
     * This balance is strictly scoped to the demo page and must NEVER be merged
     * into dashboard / plan / subscription balances.
     */
    public function up(): void
    {
        Schema::create('demo_reward_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversion_id')->nullable()->constrained('referral_conversions')->nullOnDelete();
            $table->foreignId('demo_voice_generation_id')->nullable()->constrained('demo_voice_generations')->nullOnDelete();
            // grant, consume, revoke, adjustment
            $table->string('kind', 20);
            $table->integer('delta_chars'); // signed
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_reward_transactions');
    }
};
