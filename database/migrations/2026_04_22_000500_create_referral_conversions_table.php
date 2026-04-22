<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_conversions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('referral_campaigns')->cascadeOnDelete();
            $table->foreignId('referrer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('referral_visits')->nullOnDelete();
            // pending, approved, rejected, flagged
            $table->string('status', 16)->default('pending');
            $table->string('qualification_step', 40); // snapshot at time of conversion
            $table->unsignedInteger('reward_chars')->default(0); // snapshot amount granted
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('flagged_at')->nullable();
            $table->string('rejection_reason', 120)->nullable();
            $table->json('fraud_indicators')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reviewer_notes', 1024)->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'referred_user_id'], 'referral_conversions_unique_referred');
            $table->index(['referrer_user_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_conversions');
    }
};
