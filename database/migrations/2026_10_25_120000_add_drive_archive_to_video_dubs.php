<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A paid dub is kept in the site's Google Drive (Listen Studio, a folder
     * per member) instead of on the server: its Drive file and when it was
     * saved there (App\Services\Dubbing\DriveArchive).
     */
    public function up(): void
    {
        Schema::table('video_dubs', function (Blueprint $table): void {
            if (! Schema::hasColumn('video_dubs', 'drive_file_id')) {
                $table->string('drive_file_id', 128)->nullable();
            }
            if (! Schema::hasColumn('video_dubs', 'archived_at')) {
                $table->timestamp('archived_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('video_dubs', function (Blueprint $table): void {
            $table->dropColumn(['drive_file_id', 'archived_at']);
        });
    }
};
