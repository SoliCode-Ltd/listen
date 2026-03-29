<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedTinyInteger('discount_percentage')->nullable()->after('price_cents');
            $table->timestamp('discount_ends_at')->nullable()->after('discount_percentage');
            $table->string('stripe_coupon_id')->nullable()->after('stripe_price_yearly');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['discount_percentage', 'discount_ends_at', 'stripe_coupon_id']);
        });
    }
};
