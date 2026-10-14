<?php

/*
 * Updates (or, with a null value, deletes) SEO site settings on the live site.
 *
 * Dry run by default: prints each setting's current and new value.
 * With APPLY=1 in the environment it writes the new values and first saves
 * every old value to storage/app/seo-backups/ (also printed), so the change
 * can be undone with RESTORE=<backup file name>.
 *
 *   ssh user@host 'cd ~/public_html && APPLY=1 php' < scripts/seo/apply_seo_settings.php
 */

use App\Models\SiteSetting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;

ini_set('display_errors', 'stderr');

$root = getcwd();
if (! is_file($root.'/artisan')) {
    fwrite(STDERR, "Run this from the Laravel app root.\n");
    exit(1);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/*
 * October 2026: the product no longer has a free plan, speaks 24 languages,
 * and has more than 100 Arabic and 30 English accents. The page titles and
 * descriptions saved here earlier still said "Free Plan", "Start free",
 * "50 dialects" and "18 accents", and a saved value always wins over the
 * site's own text. A null value deletes the saved override, so the page
 * shows the up-to-date text that ships with the site (the lang files).
 */
$changes = [
    'seo_home_title' => null,
    'seo_home_description' => null,
    'seo_home_title_en' => null,
    'seo_home_title_ar' => null,
    'seo_home_description_en' => null,
    'seo_home_description_ar' => null,

    'seo_pricing_title' => null,
    'seo_pricing_description' => null,
    'seo_pricing_title_en' => null,
    'seo_pricing_title_ar' => null,
    'seo_pricing_description_en' => null,
    'seo_pricing_description_ar' => null,

    'seo_ai_text_to_speech_description' => null,
    'seo_ai_text_to_speech_description_en' => null,
    'seo_ai_text_to_speech_description_ar' => null,
    'seo_ai_voice_generator_description' => null,
    'seo_ai_voice_generator_description_en' => null,
    'seo_ai_voice_generator_description_ar' => null,
    'seo_text_to_speech_arabic_title_ar' => null,
    'seo_text_to_speech_arabic_description' => null,
    'seo_text_to_speech_arabic_description_en' => null,
    'seo_text_to_speech_arabic_description_ar' => null,
    'seo_text_to_speech_title_en' => null,
    'seo_text_to_speech_title_ar' => null,
    'seo_text_to_speech_description_en' => null,
    'seo_text_to_speech_description_ar' => null,
    'seo_register_description_en' => null,
    'seo_register_description_ar' => null,

    // The site-wide fallback description, used by pages without their own.
    'meta_description_en' => 'Natural AI voices in Fusha, 100+ Arabic dialect accents, 30+ English accents and 22 more languages, with emotions and sound environments. Download MP3.',
    'meta_description_ar' => 'أصوات طبيعية بالذكاء الاصطناعي بالفصحى وأكثر من 100 لكنة عربية وأكثر من 30 لكنة إنجليزية و22 لغة أخرى، مع المشاعر وبيئات الصوت. حمّل الصوت بصيغة MP3.',
];

if (($restore = getenv('RESTORE')) !== false && $restore !== '') {
    $path = storage_path('app/seo-backups/'.basename($restore));
    $backup = json_decode((string) @file_get_contents($path), true);
    if (! is_array($backup)) {
        fwrite(STDERR, "Backup not found: {$path}\n");
        exit(1);
    }
    foreach ($backup as $key => $row) {
        if ($row === null) {
            SiteSetting::query()->where('key', $key)->delete();
        } else {
            SiteSetting::set($key, $row['value'], $row['group'] ?: 'seo');
        }
        Cache::forget("site_setting.{$key}");
    }
    echo json_encode(['restored' => basename($restore), 'keys' => array_keys($backup)], JSON_PRETTY_PRINT), "\n";
    exit(0);
}

$apply = getenv('APPLY') === '1';
$backup = [];
$report = [];

foreach ($changes as $key => $value) {
    $row = SiteSetting::query()->where('key', $key)->first(['value', 'group']);
    $backup[$key] = $row ? ['value' => $row->value, 'group' => $row->group] : null;
    $current = $row?->value;
    $report[$key] = ['from' => $current, 'to' => $value, 'changed' => $value === null ? $row !== null : (string) $current !== $value];
}

if ($apply) {
    $dir = storage_path('app/seo-backups');
    if (! is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $file = 'seo-settings-'.date('Ymd-His').'.json';
    file_put_contents($dir.'/'.$file, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    foreach ($changes as $key => $value) {
        if (! $report[$key]['changed']) {
            continue;
        }
        if ($value === null) {
            SiteSetting::query()->where('key', $key)->delete();
        } else {
            SiteSetting::set($key, $value, 'seo');
        }
        Cache::forget("site_setting.{$key}");
    }
    foreach (['site_settings_group.seo', 'site_settings_group.general'] as $cacheKey) {
        Cache::forget($cacheKey);
    }
}

echo json_encode([
    'mode' => $apply ? 'applied' : 'dry-run',
    'backup_file' => $apply ? $file : null,
    'changes' => $report,
    'backup' => $backup,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
