<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('referral_campaigns')->cascadeOnDelete();
            $table->foreignId('referrer_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('referral_code', 32);
            $table->string('landed_locale', 8)->default('en');
            $table->string('visitor_ip', 45)->nullable();
            $table->string('visitor_device_id', 80)->nullable();
            $table->string('visitor_session_id', 80)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('http_referer', 1024)->nullable();
            $table->foreignId('converted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index(['referrer_user_id', 'created_at']);
            $table->index('referral_code');
            $table->index('visitor_ip');
            $table->index('visitor_device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_visits');
    }
};
