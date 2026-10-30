<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The card a payment was made with, as Stripe gives it with the charge, and the
 * Stripe ids that find the card on file (saved_payment_methods): what the old
 * Saved cards page showed, now on each payment in Billing → Payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('stripe_customer_id', 255)->nullable();
            $table->string('stripe_payment_method_id', 255)->nullable()->index();
            $table->unsignedTinyInteger('card_exp_month')->nullable();
            $table->unsignedSmallInteger('card_exp_year')->nullable();
            $table->string('card_funding', 20)->nullable();
            $table->string('card_fingerprint', 64)->nullable();
            $table->string('card_cvc_check', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['stripe_payment_method_id']);
            $table->dropColumn(['stripe_customer_id', 'stripe_payment_method_id', 'card_exp_month', 'card_exp_year', 'card_funding', 'card_fingerprint', 'card_cvc_check']);
        });
    }
};
