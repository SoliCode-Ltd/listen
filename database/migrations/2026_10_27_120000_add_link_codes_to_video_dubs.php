<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Each dub's links (watch, download, how it is going) carry a random code
     * instead of its number, so nobody can guess another member's (only its
     * member can open them anyway). The dubs made before get theirs here.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('video_dubs', 'code')) {
            Schema::table('video_dubs', function (Blueprint $table): void {
                $table->string('code', 32)->nullable()->unique();
            });
        }
        DB::table('video_dubs')->whereNull('code')->orderBy('id')->select('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('video_dubs')->where('id', $row->id)->update(['code' => Str::random(32)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('video_dubs', function (Blueprint $table): void {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
