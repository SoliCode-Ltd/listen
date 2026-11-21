<?php

use App\Models\SiteSetting;
use App\Support\Clarity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Visit recordings (App\Support\Clarity) with the team's Clarity project, switched on. Only when no
 * project is set yet: a project the team typed in SEO Settings, or recordings they turned off, are left
 * as they are. Only the live site loads it (staging runs with LISTEN_LIVE_ACTIONS off).
 */
return new class extends Migration
{
    public const PROJECT = 'zguy8b887j';

    public function up(): void
    {
        if (! Schema::hasTable('site_settings')) {
            return;
        }

        if (trim((string) SiteSetting::query()->where('key', Clarity::PROJECT)->value('value')) === '') {
            SiteSetting::set(Clarity::PROJECT, self::PROJECT, Clarity::GROUP);
            SiteSetting::set(Clarity::ENABLED, '1', Clarity::GROUP);
        }

        SiteSetting::flushGroupCache(Clarity::GROUP);
    }

    public function down(): void
    {
        // Left as it is; switch it off in SEO Settings → Visit recordings.
    }
};
