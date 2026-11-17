<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Speech to text's free try is one for each person, as the demo counts people
 * (App\Services\DemoSamePerson), not one for each account: a free transcript keeps the
 * browser cookie and device it was asked from, so a new account there gets no second one,
 * and an account deleted after it keeps that it was used (demo_left_behind).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transcriptions') && ! Schema::hasColumn('transcriptions', 'browser')) {
            Schema::table('transcriptions', function (Blueprint $table): void {
                $table->string('browser', 128)->nullable()->after('is_free');
                $table->string('device', 64)->nullable()->after('browser');
                $table->index('browser');
                $table->index('device');
            });
        }
        if (Schema::hasTable('demo_left_behind') && ! Schema::hasColumn('demo_left_behind', 'free_stt_used')) {
            Schema::table('demo_left_behind', function (Blueprint $table): void {
                $table->boolean('free_stt_used')->default(false)->after('free_dub_used');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('transcriptions', 'browser')) {
            Schema::table('transcriptions', function (Blueprint $table): void {
                $table->dropIndex(['browser']);
                $table->dropIndex(['device']);
                $table->dropColumn(['browser', 'device']);
            });
        }
        if (Schema::hasColumn('demo_left_behind', 'free_stt_used')) {
            Schema::table('demo_left_behind', function (Blueprint $table): void {
                $table->dropColumn('free_stt_used');
            });
        }
    }
};
