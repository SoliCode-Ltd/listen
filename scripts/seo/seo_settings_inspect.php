<?php

/*
 * Read-only SEO settings inspection.
 *
 * Boots the Laravel app in the current directory and prints JSON with the
 * SEO-related site settings (titles, descriptions, keywords, robots, sitemap,
 * og/twitter, site name). Keys that look like secrets are never printed.
 * Nothing is written.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/seo/seo_settings_inspect.php
 */

use App\Models\SiteSetting;
use Illuminate\Contracts\Console\Kernel;

ini_set('display_errors', 'stderr');

$root = getcwd();
if (! is_file($root.'/artisan')) {
    fwrite(STDERR, "Run this from the Laravel app root.\n");
    exit(1);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$prefixes = ['google_', 'facebook_', 'seo_', 'meta_', 'og_', 'twitter_', 'sitemap_', 'site_name', 'site_description', 'canonical_url', 'robots_txt', 'llms_txt', 'enable_llms_txt', 'enable_structured_data', 'logo_url', 'google_site_verification', 'bing_site_verification', 'landing_'];
$secretWords = ['secret', 'token', 'password', 'api_key', 'apikey', 'private'];

$rows = SiteSetting::query()->orderBy('key')->get(['key', 'value', 'group']);
$out = [];
foreach ($rows as $row) {
    $key = (string) $row->key;
    $match = false;
    foreach ($prefixes as $prefix) {
        if (str_starts_with($key, $prefix)) {
            $match = true;
            break;
        }
    }
    if (! $match) {
        continue;
    }
    foreach ($secretWords as $word) {
        if (str_contains(strtolower($key), $word)) {
            continue 2;
        }
    }
    $value = (string) $row->value;
    $out[$key] = mb_strlen($value) > 600 ? mb_substr($value, 0, 600).'…['.mb_strlen($value).' chars]' : $value;
}

echo json_encode([
    'app_url' => config('app.url'),
    'settings' => $out,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
