<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A newsletter can go to several plans at once (Admin → Newsletter → Who receives it →
 * Chosen plans). The newsletters written for one plan keep that plan as their only choice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('newsletters', 'plan_ids')) {
            Schema::table('newsletters', function (Blueprint $table): void {
                $table->json('plan_ids')->nullable()->after('plan_id');
            });
        }

        DB::table('newsletters')->whereNotNull('plan_id')->whereNull('plan_ids')->orderBy('id')
            ->each(fn (object $row) => DB::table('newsletters')->where('id', $row->id)
                ->update(['plan_ids' => json_encode([(int) $row->plan_id])]));
    }

    public function down(): void
    {
        if (Schema::hasColumn('newsletters', 'plan_ids')) {
            Schema::table('newsletters', function (Blueprint $table): void {
                $table->dropColumn('plan_ids');
            });
        }
    }
};
