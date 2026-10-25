<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The site icon filled its whole square, so wherever it sits beside text (a
     * link's preview in WhatsApp, a browser tab) it touched the words. The bundled
     * icons (public/favicon.svg, favicon.png and apple-touch-icon.png) keep room
     * around the mark. The icons uploaded in the admin before this change are the
     * old full-square ones: those settings are cleared, so the bundled icons are
     * used. An icon uploaded in the admin later is left alone.
     */
    private const OLD_UPLOADS = [
        'seo_favicon_svg_url' => '01KKZQ0BNK6Q25WBJ0FGY46KXY',
        'seo_favicon_url' => '01KKZQ0BNM2JW3P5PAA49S84DJ',
        'seo_apple_touch_icon_url' => '01KKZQ0BNNJ6Y2F44Z8HQ1NJFB',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('site_settings')) {
            return;
        }
        foreach (self::OLD_UPLOADS as $key => $file) {
            $value = (string) DB::table('site_settings')->where('key', $key)->value('value');
            if ($value !== '' && str_contains($value, $file)) {
                DB::table('site_settings')->where('key', $key)->update(['value' => '', 'updated_at' => now()]);
                Cache::forget("site_setting.{$key}");
            }
        }
        Cache::forget('site_settings_group.seo');
    }

    public function down(): void
    {
        // The uploads are still on the disk; they can be chosen again in the admin.
    }
};
