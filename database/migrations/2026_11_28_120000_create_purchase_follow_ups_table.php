<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Members who nearly bought a plan: a first payment the bank declined, or a checkout opened
 * and left without paying. Each row is what happened, the follow-up email it got (or why it
 * got none) and whether the member bought a plan afterwards
 * (Admin → Newsletter → Purchase follow-ups, App\Services\Billing\PurchaseFollowUps).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_follow_ups')) {
            return;
        }

        Schema::create('purchase_follow_ups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // payment_failed | checkout_left
            $table->string('kind', 20);
            $table->string('plan_slug', 60)->nullable();
            $table->string('billing_cycle', 10)->default('monthly');
            $table->string('locale', 5)->default('ar');
            // The bank's reason for a declined payment (insufficient_funds, ...).
            $table->string('reason', 60)->nullable();
            $table->string('stripe_invoice_id', 80)->nullable();
            $table->unsignedInteger('amount_cents')->nullable();
            $table->string('currency', 3)->nullable();
            // When the payment was declined, or the checkout last opened.
            $table->timestamp('happened_at')->nullable();
            $table->unsignedSmallInteger('opens')->default(1);
            // waiting | sent | skipped | failed | off
            $table->string('email_status', 20)->default('waiting');
            $table->string('email_note', 160)->nullable();
            $table->timestamp('email_due_at')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            // The member started a paid plan afterwards.
            $table->timestamp('paid_at')->nullable();
            $table->string('paid_plan_slug', 60)->nullable();
            $table->timestamps();

            $table->index(['email_status', 'email_due_at']);
            $table->index(['user_id', 'kind', 'created_at']);
            $table->index('stripe_invoice_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_follow_ups');
    }
};
