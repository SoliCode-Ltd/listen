<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every payment a member made and how: by card, Apple Pay or Google Pay (a paid
 * Stripe charge), or InstaPay / Vodafone Cash (an approved payment by hand). For
 * Billing → Payments, the Subscriptions list and the dashboard (App\Models\Payment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // charge:ch_... or manual:12, so a payment is kept once.
            $table->string('reference', 191)->unique();
            // apple_pay, google_pay, card, link, instapay, vodafone_cash...
            $table->string('method', 30);
            $table->string('card_brand', 30)->nullable();
            $table->string('card_last4', 4)->nullable();
            // Where the card was issued.
            $table->char('card_country', 2)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->string('currency', 3);
            $table->string('description', 255)->nullable();
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->index(['method', 'paid_at']);
            $table->index(['user_id', 'paid_at']);
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
