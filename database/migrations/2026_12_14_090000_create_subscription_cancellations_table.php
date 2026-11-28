<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each time a member cancels their subscription: why (the reason they picked in the
 * cancel dialog, and their own words), their plan, and when it ends. Billing → Cancellations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('plan_name', 120)->nullable();
            // price, quality, technical, not_needed, other; null when the member skipped it.
            $table->string('reason', 32)->nullable()->index();
            $table->text('note')->nullable();
            $table->string('locale', 5)->nullable();
            // Ends with the period paid for, or now (a renewal that was never paid).
            $table->boolean('ended_now')->default(false);
            $table->timestamp('ends_at')->nullable();
            // Set once the cancellation went through; a row without it did not (Stripe refused).
            $table->timestamp('cancelled_at')->nullable()->index();
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_cancellations');
    }
};
