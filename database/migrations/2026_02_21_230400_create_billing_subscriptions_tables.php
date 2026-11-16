<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('billing_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'stripe_status']);
            $table->unique(['user_id', 'type']);
        });

        Schema::create('billing_subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('billing_subscriptions')->cascadeOnDelete();
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->integer('quantity')->nullable();
            $table->timestamps();

            $table->index(['stripe_price', 'stripe_product']);
            $table->index(['subscription_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_subscription_items');
        Schema::dropIfExists('billing_subscriptions');
    }
};
