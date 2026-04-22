<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_share_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('referral_campaigns')->nullOnDelete();
            $table->string('platform', 32); // facebook, twitter, linkedin, whatsapp, telegram, copy_link, instagram
            $table->string('locale', 8)->default('en');
            $table->string('referral_code', 32);
            $table->string('referral_link', 512);
            $table->string('visitor_ip', 45)->nullable();
            $table->string('visitor_device_id', 80)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('platform');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_share_logs');
    }
};
