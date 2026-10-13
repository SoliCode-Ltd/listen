<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pricing card lines written from each plan's settings: which ones show,
        // the admin's extra lines, and a switch back to the hand-written lists.
        Schema::table('plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('plans', 'pricing_lines')) {
                $table->json('pricing_lines')->nullable();
            }
            if (! Schema::hasColumn('plans', 'pricing_extra_lines_en')) {
                $table->json('pricing_extra_lines_en')->nullable();
            }
            if (! Schema::hasColumn('plans', 'pricing_extra_lines_ar')) {
                $table->json('pricing_extra_lines_ar')->nullable();
            }
            if (! Schema::hasColumn('plans', 'pricing_use_custom_text')) {
                $table->boolean('pricing_use_custom_text')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            foreach (['pricing_lines', 'pricing_extra_lines_en', 'pricing_extra_lines_ar', 'pricing_use_custom_text'] as $column) {
                if (Schema::hasColumn('plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
