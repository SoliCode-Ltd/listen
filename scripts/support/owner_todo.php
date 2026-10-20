<?php

/*
 * For the "Server deploy" workflow's owner-todo mode, on either site: whether
 * the things only the owner can change in the admin are still to do. In a
 * READ ONLY database session it prints the plans' own pricing-card lines that
 * still promise a queue or priority, which plans have a saved description
 * (it replaces the automatic one), the SEO texts saved in the admin (they
 * override the page's own text), and the referral campaigns switched on.
 * Prints no member data, and writes nothing.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/owner_todo.php
 */

use App\Models\Plan;
use App\Models\ReferralCampaign;
use App\Models\SiteSetting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}
$short = fn (string $text): string => mb_strimwidth((string) preg_replace('/\s+/u', ' ', $text), 0, 140, '…');
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";

echo "\n== Plans: their own card lines that promise a queue or priority, and saved descriptions ==\n";
$found = false;
foreach (Plan::query()->orderBy('kind')->orderBy('price_cents')->get() as $plan) {
    foreach (['en' => 'features_en', 'ar' => 'features_ar'] as $locale => $column) {
        foreach ((array) ($plan->{$column} ?? []) as $line) {
            $line = is_array($line) ? (string) ($line['text'] ?? $line['label'] ?? json_encode($line, JSON_UNESCAPED_UNICODE)) : (string) $line;
            if (preg_match('/priorit|queue|faster|أولوي|طابور|انتظار|أسرع/iu', $line)) {
                $found = true;
                printf("  %-12s %s (%s): %s%s\n", $plan->slug, $plan->is_active ? 'on sale' : 'off', $locale, $short($line),
                    $plan->pricing_use_custom_text ? '' : '   [own text is off: not shown]');
            }
        }
        $description = (string) ($locale === 'ar' ? $plan->description_ar : $plan->description_en);
        if (trim($description) !== '') {
            printf("  %-12s saved description (%s): %s\n", $plan->slug, $locale, $short($description));
        }
    }
}
if (! $found) {
    echo "  no card line promises a queue or priority\n";
}

echo "\n== SEO texts saved in the admin (each replaces the page's own text) ==\n";
$saved = SiteSetting::query()
    ->where(fn ($q) => $q->where('key', 'like', 'seo\_%\_title')->orWhere('key', 'like', 'seo\_%\_description')
        ->orWhere('key', 'like', 'seo\_landing\_%')->orWhere('key', 'llms_txt'))
    ->orderBy('key')
    ->get(['key', 'value', 'updated_at']);
$any = false;
foreach ($saved as $setting) {
    if (trim((string) $setting->value) === '') {
        continue;
    }
    $any = true;
    printf("  %-40s saved %s: %s\n", $setting->key, optional($setting->updated_at)->toDateString() ?? '-', $short((string) $setting->value));
}
if (! $any) {
    echo "  none: every page uses its own text\n";
}

echo "\n== Referral campaigns switched on (the invite popup shows while one is) ==\n";
$campaigns = ReferralCampaign::query()->where('is_active', true)->orderByDesc('id')->get();
if ($campaigns->isEmpty()) {
    echo "  none: the invite popup is not shown\n";
}
foreach ($campaigns as $campaign) {
    printf("  %s (%s): %s characters for each friend who %s; created %s\n", $campaign->name, $campaign->slug, number_format((int) $campaign->reward_chars),
        str_replace('_', ' ', (string) $campaign->qualification_step), optional($campaign->created_at)->toDateString() ?? '-');
}
