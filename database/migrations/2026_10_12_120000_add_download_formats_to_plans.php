<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The audio file types each plan lets members download (mp3, wav, m4a, ogg, flac).
        if (! Schema::hasColumn('plans', 'download_formats')) {
            Schema::table('plans', function (Blueprint $table): void {
                $table->json('download_formats')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('plans', 'download_formats')) {
            Schema::table('plans', function (Blueprint $table): void {
                $table->dropColumn('download_formats');
            });
        }
    }
};
