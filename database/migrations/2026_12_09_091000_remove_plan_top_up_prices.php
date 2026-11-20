<?php

use App\Support\OnlineSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The plans' top-up and overage prices are gone: they were never charged, and
 * members now add minutes with extra credits (App\Services\Billing\ExtraCredits),
 * whose prices are in Billing Settings. Nothing reads these columns any more.
 *
 * Safe to run again: a column is only dropped while it is there.
 */
return new class extends Migration
{
    private const COLUMNS = ['overage_price_per_million_cents', 'topup_char_price_cents', 'topup_minute_price_cents'];

    public function up(): void
    {
        OnlineSchema::change(function (): void {
            $there = array_values(array_filter(self::COLUMNS, fn (string $column): bool => Schema::hasColumn('plans', $column)));
            if ($there !== []) {
                Schema::table('plans', fn (Blueprint $table) => $table->dropColumn($there));
            }
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                if (! Schema::hasColumn('plans', $column)) {
                    $table->unsignedInteger($column)->nullable();
                }
            }
        });
    }
};
