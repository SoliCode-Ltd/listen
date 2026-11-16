<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Speech to text ("Transcribe"), set on each plan (Admin → Plans → Transcribe):
 * whether the plan includes it, the minutes of audio a month (from the plan
 * only: there are no packs or top-ups), the longest file, what a transcript
 * may have (timings with SRT/VTT, speaker names, translation), whether its
 * jobs go first in the queue, and how many run at once for one member.
 *
 * The plans start as the owner set them (16 November 2026): no transcription
 * on Free and Basic; Starter 120 minutes a month, Creator 250, Pro 500, Studio
 * Max 1,000. A database without these plans (a new install) gets the columns
 * only. Every number can be changed on the plan afterwards.
 */
return new class extends Migration
{
    /** @var array<string, array{minutes: int, file: int, timestamps: bool, speakers: bool, translate: bool, priority: bool, concurrent: int}> */
    private const PLANS = [
        'starter' => ['minutes' => 120, 'file' => 30, 'timestamps' => true, 'speakers' => false, 'translate' => false, 'priority' => false, 'concurrent' => 1],
        'creator' => ['minutes' => 250, 'file' => 60, 'timestamps' => true, 'speakers' => true, 'translate' => true, 'priority' => false, 'concurrent' => 1],
        'pro' => ['minutes' => 500, 'file' => 120, 'timestamps' => true, 'speakers' => true, 'translate' => true, 'priority' => true, 'concurrent' => 2],
        'studio_max' => ['minutes' => 1000, 'file' => 180, 'timestamps' => true, 'speakers' => true, 'translate' => true, 'priority' => true, 'concurrent' => 2],
    ];

    private const COLUMNS = [
        'transcription_enabled', 'transcription_minutes_month', 'transcription_max_file_minutes', 'transcription_timestamps',
        'transcription_speakers', 'transcription_translate', 'transcription_priority', 'transcription_concurrent_jobs',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }
        Schema::table('plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('plans', 'transcription_enabled')) {
                $table->boolean('transcription_enabled')->default(false);
            }
            if (! Schema::hasColumn('plans', 'transcription_minutes_month')) {
                $table->unsignedInteger('transcription_minutes_month')->default(0);
            }
            if (! Schema::hasColumn('plans', 'transcription_max_file_minutes')) {
                $table->unsignedSmallInteger('transcription_max_file_minutes')->default(0);
            }
            foreach (['transcription_timestamps', 'transcription_speakers', 'transcription_translate', 'transcription_priority'] as $flag) {
                if (! Schema::hasColumn('plans', $flag)) {
                    $table->boolean($flag)->default(false);
                }
            }
            if (! Schema::hasColumn('plans', 'transcription_concurrent_jobs')) {
                $table->unsignedSmallInteger('transcription_concurrent_jobs')->default(1);
            }
        });

        foreach (self::PLANS as $slug => $p) {
            DB::table('plans')->where('slug', $slug)->update([
                'transcription_enabled' => true,
                'transcription_minutes_month' => $p['minutes'],
                'transcription_max_file_minutes' => $p['file'],
                'transcription_timestamps' => $p['timestamps'],
                'transcription_speakers' => $p['speakers'],
                'transcription_translate' => $p['translate'],
                'transcription_priority' => $p['priority'],
                'transcription_concurrent_jobs' => $p['concurrent'],
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }
        Schema::table('plans', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                if (Schema::hasColumn('plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
