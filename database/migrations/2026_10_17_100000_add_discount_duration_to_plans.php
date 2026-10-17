<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How long each buyer keeps a plan's discount: the first payment only
     * (what every discount so far has been), the first few months, or for as
     * long as they stay subscribed.
     */
    public function up(): void
    {
        if (Schema::hasColumn('plans', 'discount_duration')) {
            return;
        }

        Schema::table('plans', function (Blueprint $table): void {
            $table->string('discount_duration', 16)->nullable()->after('discount_ends_at');
            $table->unsignedTinyInteger('discount_duration_months')->nullable()->after('discount_duration');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('plans', 'discount_duration')) {
            Schema::table('plans', function (Blueprint $table): void {
                $table->dropColumn(['discount_duration', 'discount_duration_months']);
            });
        }
    }
};
