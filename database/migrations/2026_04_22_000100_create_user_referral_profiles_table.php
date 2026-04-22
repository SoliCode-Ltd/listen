<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_referral_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('referral_code', 32)->unique();
            $table->foreignId('referred_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('referral_source_ip', 45)->nullable();
            $table->string('referral_source_device_id', 80)->nullable();
            $table->timestamp('referred_at')->nullable();
            $table->unsignedInteger('approved_conversions_count')->default(0);
            $table->unsignedInteger('pending_conversions_count')->default(0);
            $table->timestamps();

            $table->index('referred_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_referral_profiles');
    }
};
