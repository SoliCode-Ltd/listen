<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('allow_overage')->default(false);
            $table->unsignedInteger('overage_price_per_million_cents')->nullable();
            $table->boolean('commercial_use_allowed')->default(false);
            $table->string('processing_priority', 20)->default('standard');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'allow_overage',
                'overage_price_per_million_cents',
                'commercial_use_allowed',
                'processing_priority',
            ]);
        });
    }
};
