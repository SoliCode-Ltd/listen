<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('manual_payment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('billing_cycle', 20);
            $table->string('payment_method', 30);
            $table->unsignedBigInteger('expected_amount_minor');
            $table->string('currency', 3);
            $table->string('country_code', 2)->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('price_snapshot')->nullable();
            $table->json('destination_snapshot')->nullable();
            $table->string('proof_path')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reviewer_notes')->nullable();
            $table->unsignedBigInteger('approved_subscription_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'submitted_at']);
            $table->index(['user_id', 'status']);
            $table->index(['plan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_payment_requests');
    }
};
