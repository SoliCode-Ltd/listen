<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every cancellation, wherever it was made: the cancel dialog on the site, Stripe itself,
 * or before the dialog asked why (brought in from Stripe by cancellations:import). The
 * Stripe subscription keeps one cancellation from being counted twice, and resumed_at
 * marks a member who turned renewal back on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_cancellations', function (Blueprint $table) {
            $table->string('stripe_subscription_id')->nullable()->after('plan_name')->index();
            // site, stripe or import.
            $table->string('source', 16)->default('site')->after('stripe_subscription_id');
            $table->timestamp('resumed_at')->nullable()->after('email_sent_at');
        });

        // Rows the cancel dialog saved before this column existed get their member's subscription.
        \App\Services\Billing\CancellationLog::fillMissingSubscriptionIds();
    }

    public function down(): void
    {
        Schema::table('subscription_cancellations', function (Blueprint $table) {
            $table->dropIndex(['stripe_subscription_id']);
            $table->dropColumn(['stripe_subscription_id', 'source', 'resumed_at']);
        });
    }
};
