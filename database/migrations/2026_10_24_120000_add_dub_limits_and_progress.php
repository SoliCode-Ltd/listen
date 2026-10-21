<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dub my video, second step: each plan says how many videos a month its
     * members may dub and how long each may be; each dub keeps the credit
     * month it was paid in, how far along it is (for the page's progress
     * bar), whether the video's music and effects were to be kept, and the
     * language it is dubbed into (Arabic or English, as in the text studio).
     * voice_key is added here too for a server that made the table before
     * it was in the first migration.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('plans', 'video_dubs_per_month')) {
                // 0: no count, only the plan's credits.
                $table->unsignedInteger('video_dubs_per_month')->default(0);
            }
            if (! Schema::hasColumn('plans', 'video_dub_max_seconds')) {
                $table->unsignedSmallInteger('video_dub_max_seconds')->default(60);
            }
        });

        Schema::table('video_dubs', function (Blueprint $table): void {
            if (! Schema::hasColumn('video_dubs', 'voice_key')) {
                $table->string('voice_key', 40)->nullable();
            }
            if (! Schema::hasColumn('video_dubs', 'period_ym')) {
                $table->string('period_ym', 7)->nullable()->index();
            }
            if (! Schema::hasColumn('video_dubs', 'stage')) {
                // listening → voicing → mixing → marking, while it works.
                $table->string('stage', 16)->nullable();
            }
            if (! Schema::hasColumn('video_dubs', 'progress')) {
                $table->unsignedTinyInteger('progress')->default(0);
            }
            if (! Schema::hasColumn('video_dubs', 'keep_background')) {
                $table->boolean('keep_background')->default(true);
            }
            if (! Schema::hasColumn('video_dubs', 'language')) {
                // Dubbed into Arabic (a dialect) or English (an accent).
                $table->string('language', 5)->default('ar');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn(['video_dubs_per_month', 'video_dub_max_seconds']);
        });
        Schema::table('video_dubs', function (Blueprint $table): void {
            $table->dropColumn(['period_ym', 'stage', 'progress', 'keep_background', 'language']);
        });
    }
};
