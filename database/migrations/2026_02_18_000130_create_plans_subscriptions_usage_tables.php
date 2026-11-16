<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->unsignedInteger('price_cents')->default(0);
            $table->unsignedInteger('monthly_minutes')->default(0);
            $table->unsignedInteger('max_chars_per_request')->default(1000);
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamps();
        });

        Schema::create('usage_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('period_ym', 7); // YYYY-MM
            $table->unsignedInteger('total_minutes')->default(0);
            $table->unsignedBigInteger('total_chars')->default(0);
            $table->unsignedInteger('total_requests')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'period_ym']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
