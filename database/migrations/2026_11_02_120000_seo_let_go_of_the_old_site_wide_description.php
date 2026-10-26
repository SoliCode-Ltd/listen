<?php

use App\Models\SiteSetting;
use App\Support\SeoSettingDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The October SEO settings script (scripts/seo/apply_seo_settings.php) saved
     * a site-wide description that names no voices and calls the Arabic dialects
     * accents. A saved value wins over the site's own text, so pages without a
     * description of their own still show it. This lets go of it, so they show the
     * site's text, which counts the voices; a description written differently in
     * the admin stays as it is.
     */
    public const OCTOBER = [
        'meta_description_en' => 'Natural AI voices in Fusha, 140+ Arabic dialect accents and 60+ English accents, with emotions, performance styles and sound environments. Download MP3.',
        'meta_description_ar' => 'أصوات طبيعية بالذكاء الاصطناعي بالفصحى وأكثر من 140 لكنة عربية وأكثر من 60 لكنة إنجليزية، مع المشاعر وأساليب الأداء وبيئات الصوت. حمّل MP3.',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('site_settings')) {
            return;
        }
        foreach (self::OCTOBER as $key => $text) {
            $saved = SiteSetting::query()->where('key', $key)->value('value');
            if ($saved !== null && in_array(trim((string) $saved), [$text, ''], true)) {
                SeoSettingDefaults::forget($key);
            }
        }
        SiteSetting::flushGroupCache('seo');
    }

    public function down(): void
    {
        // The saved text was out of date; the site's own text replaces it.
    }
};
