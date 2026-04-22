<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            // "paid_plan" (referred user purchases a paid plan), "signup",
            // "email_verified", or "first_demo_generation".
            $table->string('qualification_step', 40)->default('paid_plan');
            $table->unsignedInteger('reward_chars')->default(1000);
            $table->unsignedInteger('visit_window_days')->default(30);
            $table->unsignedInteger('max_conversions_per_referrer_per_day')->default(5);
            $table->unsignedInteger('max_conversions_per_referrer_total')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        DB::table('referral_campaigns')->insert([
            'slug' => 'default',
            'name' => 'Default Referral Campaign',
            'is_active' => true,
            'qualification_step' => 'paid_plan',
            'reward_chars' => 1000,
            'visit_window_days' => 30,
            'max_conversions_per_referrer_per_day' => 5,
            'max_conversions_per_referrer_total' => null,
            'notes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_campaigns');
    }
};
