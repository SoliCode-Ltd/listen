<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Voice Design: a member describes a voice in words and the speech engine
     * makes it (App\Services\VoiceDesignService). Each take is a stored voice
     * on the engine's side, kept here as a draft until the member keeps one;
     * the takes they do not keep are deleted, and drafts nobody kept are
     * cleared by voice-design:prune. Plans gain the switch that includes it,
     * how many designed voices each member may keep, and how many new ones a month.
     */
    public function up(): void
    {
        if (! Schema::hasTable('designed_voices')) {
            Schema::create('designed_voices', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                // draft (a take still to be kept) or saved.
                $table->string('status', 16)->default('draft');
                // The takes of one design share it, so keeping one clears the others.
                $table->string('batch', 40)->nullable();
                $table->string('name', 80)->nullable();
                // What the member wrote, and what the engine was given (with the take's own touch).
                $table->text('description');
                $table->text('engine_prompt')->nullable();
                $table->string('language', 10)->default('ar');
                $table->string('gender', 10)->nullable();
                $table->text('preview_text')->nullable();
                $table->string('preview_audio_path')->nullable();
                // The engine's voice_... id, and the site key whose project holds it.
                $table->string('provider_voice_key')->nullable();
                $table->string('key_name', 80)->nullable();
                // Shown and played on the Voice Design card for everyone (Admin → Designed voices).
                $table->boolean('is_showcase')->default(false);
                $table->unsignedTinyInteger('take')->default(1);
                $table->json('metadata')->nullable();
                // When the member kept it: a plan's new voices a month are counted from here.
                $table->timestamp('kept_at')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['user_id', 'status']);
                $table->index(['status', 'created_at']);
                $table->index(['user_id', 'kept_at']);
                $table->index('batch');
            });
        }

        if (Schema::hasTable('plans')) {
            Schema::table('plans', function (Blueprint $table): void {
                if (! Schema::hasColumn('plans', 'voice_design_enabled')) {
                    $table->boolean('voice_design_enabled')->default(false);
                }
                if (! Schema::hasColumn('plans', 'voice_design_max_voices')) {
                    // Designed voices a member keeps at once. 0: the site-wide number (Voice Design → Settings).
                    $table->unsignedInteger('voice_design_max_voices')->default(0);
                }
                if (! Schema::hasColumn('plans', 'voice_design_monthly_voices')) {
                    // New voices a member may keep in a credit month. 0: no monthly count.
                    $table->unsignedInteger('voice_design_monthly_voices')->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('designed_voices');

        if (Schema::hasTable('plans')) {
            Schema::table('plans', function (Blueprint $table): void {
                foreach (['voice_design_enabled', 'voice_design_max_voices', 'voice_design_monthly_voices'] as $column) {
                    if (Schema::hasColumn('plans', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
