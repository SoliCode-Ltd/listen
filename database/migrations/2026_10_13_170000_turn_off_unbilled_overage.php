<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Overage was switched on for paid plans but never charged, which let members
 * go past their monthly limit for free. Every plan now stops at its credits.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('plans', 'allow_overage')) {
            DB::table('plans')->where('allow_overage', true)->update(['allow_overage' => false]);
        }
    }

    public function down(): void
    {
        // Not restored: turning unbilled overage back on would remove the limit again.
    }
};
