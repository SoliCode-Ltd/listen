<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('billing_trials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('quota_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('status', 32)->index();
            $table->boolean('is_admin_test')->default(false)->index();
            $table->string('stripe_customer_id')->nullable()->index();
            $table->string('stripe_subscription_id')->nullable()->index();
            $table->string('stripe_invoice_id')->nullable();
            $table->string('setup_intent_id')->nullable()->unique();
            $table->string('stripe_payment_method_id')->nullable();
            $table->string('card_brand', 32)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->unsignedSmallInteger('card_exp_month')->nullable();
            $table->unsignedSmallInteger('card_exp_year')->nullable();
            $table->string('card_fingerprint')->nullable()->index();
            $table->string('card_funding', 32)->nullable();
            $table->string('card_country', 8)->nullable();
            $table->string('cvc_check', 32)->nullable();
            $table->boolean('off_session_ready')->default(false);
            $table->unsignedInteger('amount_cents')->default(0);
            $table->unsignedInteger('amount_paid_cents')->nullable();
            $table->string('currency', 8)->default('usd');
            $table->unsignedInteger('chars_limit')->default(0);
            $table->unsignedInteger('minutes_limit')->default(0);
            $table->unsignedInteger('max_chars_per_request')->default(0);
            $table->unsignedInteger('premium_minutes_limit')->default(0);
            $table->unsignedBigInteger('chars_used')->default(0);
            $table->unsignedInteger('requests_used')->default(0);
            $table->unsignedInteger('minutes_used')->default(0);
            $table->string('email_normalized')->nullable()->index();
            $table->string('phone_normalized')->nullable()->index();
            $table->string('device_id')->nullable()->index();
            $table->string('registration_ip', 64)->nullable();
            $table->string('blocked_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('charge_failed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('saved_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_trial_id')->nullable()->constrained('billing_trials')->nullOnDelete();
            $table->string('stripe_customer_id')->nullable()->index();
            $table->string('stripe_payment_method_id')->unique();
            $table->string('brand', 32)->nullable();
            $table->string('last4', 4)->nullable();
            $table->unsignedSmallInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->string('fingerprint')->nullable()->index();
            $table->string('funding', 32)->nullable();
            $table->string('country', 8)->nullable();
            $table->string('cvc_check', 32)->nullable();
            $table->string('setup_intent_id')->nullable();
            $table->boolean('off_session_ready')->default(false);
            $table->string('purpose', 32)->default('subscription');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_payment_methods');
        Schema::dropIfExists('billing_trials');
    }
};
