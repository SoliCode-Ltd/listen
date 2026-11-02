<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A move to a lower plan waits for the end of the period the member paid for
 * (App\Services\Billing\PlanChanges): on a card, a Stripe subscription schedule
 * switches the price at the renewal; paid by hand, the new plan starts the day the
 * current one ends. One row for each change, kept after it is done or called off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignId('to_plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('billing_cycle', 10);
            // card: the running card subscription changes at its renewal; card_start: a card
            // plan starts when a plan paid by hand (or given) ends; manual: paid by hand.
            $table->string('source', 20);
            // scheduled, applied, cancelled
            $table->string('status', 20)->default('scheduled');
            $table->timestamp('starts_at');
            // Paid by hand: the end of the period paid for.
            $table->timestamp('period_ends_at')->nullable();
            $table->string('stripe_subscription_id')->nullable();
            $table->string('stripe_schedule_id')->nullable()->index();
            $table->string('stripe_price_id')->nullable();
            // The offer kept for this member (a coupon of their own, used once).
            $table->string('stripe_coupon_id')->nullable();
            // What the new plan costs, as promised when it was chosen (US cents).
            $table->unsignedInteger('amount_cents')->default(0);
            $table->unsignedInteger('first_amount_cents')->nullable();
            $table->json('discount')->nullable();
            // The price as the member was shown it when they chose the plan, in English and
            // Arabic and in their money, for the emails that follow.
            $table->json('terms')->nullable();
            $table->foreignId('manual_payment_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cancel_reason', 40)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_changes');
    }
};
