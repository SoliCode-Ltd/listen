<?php

/*
 * Updates SEO site settings that hold back search visibility.
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

$noindex = 'noindex,nofollow,noarchive,nosnippet';

$changes = [
    // Home: the old title had no search keyword ("Turn any text into human voice instantly.").
    'seo_home_title_en' => 'Arabic & English AI Text to Speech, 50+ Dialects',
    'seo_home_title_ar' => 'تحويل النص إلى صوت وكلام بالذكاء الاصطناعي',
    'seo_home_description_en' => 'AI text to speech in Arabic and English: Fusha and 50 Arabic dialects from the Gulf, Levant, Egypt and Maghreb, plus 18 English accents. Free to try, MP3 download.',
    'seo_home_description_ar' => 'تحويل النص إلى صوت بالذكاء الاصطناعي بالعربية والإنجليزية: الفصحى و50 لهجة من الخليج والشام ومصر والمغرب العربي، و18 لكنة إنجليزية. جرّبها مجاناً وحمّل MP3.',

    // Pricing had no Arabic description, so it showed the site-wide one.
    'seo_pricing_title_en' => 'Text to Speech Pricing and Free Plan',
    'seo_pricing_title_ar' => 'أسعار تحويل النص إلى صوت والخطة المجانية',
    'seo_pricing_description_en' => 'Compare Listen plans for Arabic and English AI text to speech. Start free, then upgrade for more characters, HD voices and dialects.',
    'seo_pricing_description_ar' => 'قارن خطط منصة إسمع لتحويل النص إلى صوت بالعربية والإنجليزية. ابدأ مجاناً ثم رقِّ خطتك لأحرف أكثر وأصوات عالية الدقة ولهجات.',

    // Extension: target "text to speech chrome extension" / "قراءة النص بصوت".
    'seo_extension_title_en' => 'Text to Speech Chrome Extension',
    'seo_extension_title_ar' => 'إضافة كروم لقراءة النصوص بصوت عالٍ',
    'seo_extension_description_en' => 'Read any web page aloud in natural Arabic or English with the Listen Reader Chrome extension. Select text, press play.',
    'seo_extension_description_ar' => 'اقرأ أي صفحة ويب بصوت عربي أو إنجليزي طبيعي مع إضافة إسمع لمتصفح كروم. حدد النص واضغط تشغيل.',

    // Arabic text to speech page now also has an Arabic version.
    'seo_text_to_speech_arabic_title_ar' => 'تحويل النص العربي إلى كلام بالفصحى واللهجات',
    'seo_text_to_speech_arabic_description_ar' => 'حوّل النص العربي، فصحى أو عامية، إلى كلام بصوت بشري طبيعي بنطق صحيح وأكثر من 50 لهجة عربية. جرّبه مجاناً مع منصة إسمع.',

    // Sign-in and account pages were set to "index"; they belong out of search.
    'seo_login_robots' => $noindex,
    'seo_register_robots' => $noindex,
    'seo_demo_robots' => $noindex,
    'seo_dashboard_robots' => $noindex,
    'seo_billing_robots' => $noindex,
    'seo_manage_subscription_robots' => $noindex,
    'seo_upgrade_robots' => $noindex,

    // Sitemap: list only indexable pages, and drop the hand-written list
    // (it duplicated the automatic entries and pointed at /demo, a redirect).
    'sitemap_include_non_indexable' => '0',
    'sitemap_paths' => '',

    'robots_txt' => "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /livewire/\n\nSitemap: https://listenai.studio/sitemap.xml",

    // Empty = the app's built-in llms.txt, which lists every landing and dialect page.
    'llms_txt' => '',
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
    $report[$key] = ['from' => $current, 'to' => $value, 'changed' => (string) $current !== $value];
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
        SiteSetting::set($key, $value, 'seo');
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
