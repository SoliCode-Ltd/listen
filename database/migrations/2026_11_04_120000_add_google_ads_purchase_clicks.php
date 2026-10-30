<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Google Ads click a member last came from, kept with their purchases, so a
 * sale can be uploaded to Google Ads ("Import → Conversions from clicks") once it
 * is really paid: a payment by hand when the team approves it, a card payment when
 * Stripe says the invoice is paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ads_click_id', 255)->nullable();
            $table->timestamp('ads_click_at')->nullable();
        });

        Schema::table('manual_payment_requests', function (Blueprint $table) {
            $table->string('gclid', 255)->nullable();
        });

        Schema::create('ads_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // card (a paid Stripe invoice) or manual (an approved InstaPay / Vodafone Cash payment).
            $table->string('source', 20);
            // invoice:in_... or manual:12, so a purchase is kept once.
            $table->string('reference', 191)->unique();
            $table->string('gclid', 255);
            $table->unsignedBigInteger('value_minor');
            $table->string('currency', 3);
            $table->timestamp('purchased_at');
            $table->timestamps();

            $table->index(['source', 'purchased_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ads_purchases');

        Schema::table('manual_payment_requests', function (Blueprint $table) {
            $table->dropColumn('gclid');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['ads_click_id', 'ads_click_at']);
        });
    }
};
