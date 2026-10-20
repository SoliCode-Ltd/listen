<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether each person in the video gets a voice of their own; off, the
     * voice the member chose reads every line.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('video_dubs', 'each_speaker')) {
            Schema::table('video_dubs', function (Blueprint $table): void {
                $table->boolean('each_speaker')->default(false);
            });
        }
    }

    public function down(): void
    {
        Schema::table('video_dubs', function (Blueprint $table): void {
            $table->dropColumn('each_speaker');
        });
    }
};
