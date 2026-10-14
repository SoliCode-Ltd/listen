<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sound environments (echo, hall, phone …) become a per-plan option, off on
     * every plan until it is switched on in the admin plan form.
     */
    public function up(): void
    {
        if (Schema::hasColumn('plans', 'sound_effects_enabled')) {
            return;
        }

        Schema::table('plans', function (Blueprint $table): void {
            $table->boolean('sound_effects_enabled')->default(false)->after('custom_prompt_enabled');
        });
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
