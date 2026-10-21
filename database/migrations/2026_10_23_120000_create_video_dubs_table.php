<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Dub my video": each video a member sends to be dubbed into an Arabic
     * dialect (App\Services\Dubbing\VideoDubber). A free one carries the
     * Listen mark and is one per person; a plan that includes dubbing pays
     * for it in credits and gets the clean copy. The files are deleted after
     * listen.dubbing.keep_hours; the row stays, so the free one stays used.
     * Plans gain the switch that includes dubbing.
     */
    public function up(): void
    {
        if (! Schema::hasTable('video_dubs')) {
            Schema::create('video_dubs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                // queued → processing → ready, or failed.
                $table->string('status', 16)->default('queued');
                // A free marked preview, or paid for from the plan's credits.
                $table->boolean('is_free')->default(false);
                $table->string('dialect', 10);
                // The speech engine's voice, and the studio library's card it was picked as.
                $table->string('voice', 40);
                $table->string('voice_key', 40)->nullable();
                $table->string('performance_style', 40)->nullable();
                $table->string('emotion', 40)->nullable();
                $table->string('original_name')->nullable();
                $table->decimal('video_seconds', 6, 2)->default(0);
                // Folder on the local disk with the upload and the results.
                $table->string('folder')->nullable();
                $table->boolean('has_clean')->default(false);
                $table->boolean('has_preview')->default(false);
                // What the member's plan was charged (MemberUsageMeter::reserveCredits), null for a free one.
                $table->json('reservation')->nullable();
                $table->json('report')->nullable();
                $table->string('error_message', 500)->nullable();
                // The browser cookie and device fingerprint it was asked from, so a new account there gets no second free one.
                $table->string('browser', 128)->nullable();
                $table->string('device', 64)->nullable();
                $table->timestamp('files_deleted_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
                $table->index(['is_free', 'created_at']);
                $table->index('browser');
                $table->index('device');
            });
        }

        if (Schema::hasTable('plans') && ! Schema::hasColumn('plans', 'video_dubbing_enabled')) {
            Schema::table('plans', function (Blueprint $table): void {
                $table->boolean('video_dubbing_enabled')->default(false);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('video_dubs');
        if (Schema::hasColumn('plans', 'video_dubbing_enabled')) {
            Schema::table('plans', function (Blueprint $table): void {
                $table->dropColumn('video_dubbing_enabled');
            });
        }
    }
};
