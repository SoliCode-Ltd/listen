<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Stripe product and price IDs
            $table->string('stripe_product_id')->nullable()->after('is_featured');
            $table->string('stripe_price_monthly')->nullable()->after('stripe_product_id');
            $table->string('stripe_price_yearly')->nullable()->after('stripe_price_monthly');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['stripe_product_id', 'stripe_price_monthly', 'stripe_price_yearly']);
        });
    }
};
