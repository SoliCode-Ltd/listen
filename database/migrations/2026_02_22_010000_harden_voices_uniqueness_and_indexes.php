<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('voices')) {
            return;
        }

        $seen = [];
        $duplicateIds = [];

        $rows = DB::table('voices')
            ->select(['id', 'language', 'display_name'])
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $key = strtolower((string) $row->language).'|'.strtolower((string) $row->display_name);

            if (isset($seen[$key])) {
                $duplicateIds[] = (int) $row->id;
                continue;
            }

            $seen[$key] = true;
        }

        if ($duplicateIds !== []) {
            DB::table('voices')->whereIn('id', $duplicateIds)->delete();
        }

        Schema::table('voices', function (Blueprint $table) {
            $table->unique(['language', 'display_name'], 'voices_language_display_name_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('voices')) {
            return;
        }

        Schema::table('voices', function (Blueprint $table) {
            $table->dropUnique('voices_language_display_name_unique');
        });
    }
};
