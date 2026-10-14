<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sound environments (echo, hall, phone …) become a per-plan option. Plans
     * on the premium voices keep them, as they had them before this switch.
     */
    public function up(): void
    {
        if (Schema::hasColumn('plans', 'sound_effects_enabled')) {
            return;
        }

        Schema::table('plans', function (Blueprint $table): void {
            $table->boolean('sound_effects_enabled')->default(false)->after('custom_prompt_enabled');
        });

        DB::table('plans')->where('voice_tier', 'hd')->update(['sound_effects_enabled' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('plans', 'sound_effects_enabled')) {
            Schema::table('plans', function (Blueprint $table): void {
                $table->dropColumn('sound_effects_enabled');
            });
        }
    }
};
