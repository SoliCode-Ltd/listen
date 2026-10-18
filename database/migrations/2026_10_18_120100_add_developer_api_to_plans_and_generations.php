<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The developer API becomes a per-plan option. It starts switched on for
     * the paid subscription plans that already allow commercial use, and off
     * everywhere else (free plan, packs) until it is switched on in the admin
     * plan form. Generations made through the API remember the key they used.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('plans', 'api_access_enabled')) {
            Schema::table('plans', function (Blueprint $table): void {
                $table->boolean('api_access_enabled')->default(false);
                // Null: the default for the plan's processing priority (config/listen.php → api).
                $table->unsignedInteger('api_requests_per_minute')->nullable();
                $table->unsignedInteger('api_concurrency')->nullable();
            });

            DB::table('plans')
                ->where('price_cents', '>', 0)
                ->where('commercial_use_allowed', true)
                ->where(function ($query): void {
                    $query->whereNull('kind')->orWhere('kind', 'subscription');
                })
                ->update(['api_access_enabled' => true]);
        }

        if (! Schema::hasColumn('audio_generations', 'api_key_id')) {
            Schema::table('audio_generations', function (Blueprint $table): void {
                $table->unsignedBigInteger('api_key_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('audio_generations', 'api_key_id')) {
            Schema::table('audio_generations', function (Blueprint $table): void {
                $table->dropIndex(['api_key_id']);
                $table->dropColumn('api_key_id');
            });
        }

        if (Schema::hasColumn('plans', 'api_access_enabled')) {
            Schema::table('plans', function (Blueprint $table): void {
                $table->dropColumn(['api_access_enabled', 'api_requests_per_minute', 'api_concurrency']);
            });
        }
    }
};
