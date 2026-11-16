<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The switchable New theme was removed, so its "site_design" setting has nothing to control.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('site_settings')->where('key', 'site_design')->delete();
        Cache::forget('site_setting.site_design');
    }

    public function down(): void
    {
        // Nothing to restore: the setting has no effect any more.
    }
};
