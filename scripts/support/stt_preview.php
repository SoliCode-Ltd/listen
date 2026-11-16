<?php

/*
 * For the "Server deploy" workflow's stt-preview mode, on either site: what
 * deploying speech to text would find there and change, for the owner to read
 * before saying yes. In a READ ONLY database session it prints:
 *  - the speech to text migrations: run already, or still to run;
 *  - every plan as it is now (on sale or not, its price and offer, its own card
 *    text), with the speech to text it has now and the speech to text it would
 *    have after the migration (PLANS below: the numbers in
 *    database/migrations/2026_12_01_120000_add_transcription_to_plans.php,
 *    kept the same by tests/Unit/SttPreviewScriptTest.php);
 *  - for a plan with its own card text, whether that text already names speech
 *    to text (then no line is added) and where the line would go;
 *  - the speech to text settings saved (none before the first deploy: the
 *    defaults apply) and the other features' audiences the pricing cards follow;
 *  - the queue workers (priority plans' files go on the "high" queue) and the
 *    queue's retry time, PHP's limits for uploads, and the disk's free space;
 *  - every plan's card settings as JSON, so the cards can be drawn exactly.
 * Writes nothing; prints no member data (subscription counts only).
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/stt_preview.php
 */

use App\Models\Plan;
use App\Models\SiteSetting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** What the migration sets on each plan, by its slug: minutes a month, longest file (minutes), priority queue, files at once. */
const PLANS = [
    'starter' => ['minutes' => 120, 'file' => 30, 'priority' => false, 'concurrent' => 1],
    'creator' => ['minutes' => 250, 'file' => 60, 'priority' => false, 'concurrent' => 1],
    'pro' => ['minutes' => 500, 'file' => 120, 'priority' => true, 'concurrent' => 2],
    'studio_max' => ['minutes' => 1000, 'file' => 180, 'priority' => true, 'concurrent' => 2],
];

const MIGRATIONS = [
    '2026_12_01_120000_add_transcription_to_plans',
    '2026_12_01_120100_create_transcriptions_table',
    '2026_12_01_120200_add_search_text_to_transcriptions',
];

/** Words that name speech to text in a hand-written card line (as App\Support\PlanPricingLines). */
const STT_WORDS = '/speech[ -]to[ -]text|transcri|تحويل (الكلام|الصوت) إلى نص|تفريغ/iu';

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}
$short = fn (string $text): string => mb_strimwidth((string) preg_replace('/\s+/u', ' ', $text), 0, 110, '…');
$lineText = fn ($line): string => is_array($line) ? implode(' ', array_filter([$line['label'] ?? '', $line['value'] ?? '', $line['text'] ?? ''], 'is_string')) : (string) $line;
echo 'Site: ', parse_url((string) config('app.url'), PHP_URL_HOST), '   now (UTC): ', now()->utc()->toDateTimeString(), "\n";

echo "\n== Speech to text migrations ==\n";
$ran = Schema::hasTable('migrations') ? DB::table('migrations')->whereIn('migration', MIGRATIONS)->pluck('migration')->all() : [];
foreach (MIGRATIONS as $m) {
    printf("  %-56s %s\n", $m, in_array($m, $ran, true) ? 'run already' : 'TO RUN');
}
$columns = Schema::hasColumn('plans', 'transcription_enabled');
echo '  plans have the speech to text columns: ', $columns ? 'yes' : 'no (added by the first migration)', "\n";
echo '  transcriptions table: ', Schema::hasTable('transcriptions')
    ? 'yes, '.DB::table('transcriptions')->count().' transcripts'
    : 'no (made by the second migration)', "\n";

echo "\n== Plans: now, and after the migration ==\n";
$plans = Plan::query()->orderByRaw("coalesce(kind, 'subscription')")->orderBy('price_cents')->get();
$money = fn (int $cents): string => '$'.number_format($cents / 100, 2);
foreach ($plans as $plan) {
    $subs = Schema::hasTable('subscriptions')
        ? DB::table('subscriptions')->where('plan_id', $plan->id)->whereIn('status', ['active', 'trialing', 'past_due'])->count()
        : 0;
    $after = PLANS[$plan->slug] ?? null;
    printf("\n  %s  (slug %s, %s, %s)\n", $plan->name_en ?: $plan->name, $plan->slug, $plan->kind ?: 'subscription', $plan->is_active ? 'on sale' : 'not on sale');
    printf("    Arabic name: %s · price %s a month%s · %d running subscriptions\n", (string) ($plan->name_ar ?: '—'), $money((int) $plan->price_cents),
        (int) $plan->discount_percentage > 0 ? ' ('.(int) $plan->discount_percentage.'% off'.($plan->discount_ends_at ? ' until '.$plan->discount_ends_at : '').')' : '', $subs);
    printf("    speech to text now: %s\n", ! $columns ? 'none (no columns yet)' : ((bool) $plan->transcription_enabled
        ? sprintf('%d min a month, files up to %d min, %s, %d at once', $plan->transcription_minutes_month, $plan->transcription_max_file_minutes,
            $plan->transcription_priority ? 'priority queue' : 'standard queue', $plan->transcription_concurrent_jobs)
        : 'not included'));
    printf("    after the migration: %s\n", $after === null
        ? ($columns && $plan->transcription_enabled ? 'unchanged (the migration does not touch this slug)' : 'not included (its members get the one free try instead: see the settings)')
        : sprintf('%d min a month, files up to %d min, %s, %d at once', $after['minutes'], $after['file'], $after['priority'] ? 'priority queue' : 'standard queue', $after['concurrent']));
    $included = $after !== null || ($columns && (bool) $plan->transcription_enabled);
    if (! $included) {
        echo "    pricing card: no speech to text line (the plan does not include it)\n";
    } elseif ($plan->pricing_use_custom_text) {
        foreach (['en' => 'features_en', 'ar' => 'features_ar'] as $locale => $column) {
            $lines = array_values((array) ($plan->{$column} ?? []));
            $names = array_filter($lines, fn ($l) => preg_match(STT_WORDS, $lineText($l)) === 1);
            $api = array_keys(array_filter($lines, fn ($l) => preg_match('/\bAPI\b|واجهة (المطورين|برمجية)/u', is_array($l) ? (string) ($l['label'] ?? '') : (string) $l) === 1));
            printf("    own card text (%s, %d lines): %s\n", $locale, count($lines), $names !== []
                ? 'already names speech to text, so no line is added: "'.$short($lineText(reset($names))).'"'
                : 'the speech to text line is added '.($api !== [] ? 'before line '.($api[0] + 1).' ("'.$short($lineText($lines[$api[0]])).'")' : 'at the end'));
        }
    } else {
        $saved = is_array($plan->pricing_lines) ? $plan->pricing_lines : [];
        printf("    card lines written from the plan's settings; speech to text line: %s\n",
            array_key_exists('speech_to_text', $saved) ? ($saved['speech_to_text'] ? 'shown (saved)' : 'HIDDEN (switched off on the plan)') : 'shown (the default)');
    }
}

echo "\n== Speech to text settings saved (Admin → Speech to text → Settings) ==\n";
$saved = SiteSetting::query()->where('key', 'like', 'transcription\_%')->orderBy('key')->get(['key', 'value']);
if ($saved->isEmpty()) {
    echo "  none saved: the defaults in the code apply (members do not see it until it is opened to everyone)\n";
}
foreach ($saved as $s) {
    printf("  %-34s %s\n", $s->key, $short((string) $s->value));
}

echo "\n== Other features' audiences (the pricing cards show a feature's line only once it is open to everyone) ==\n";
foreach (['video_dubbing_audience', 'voice_design_audience', 'voice_cloning_audience', 'agents_audience'] as $key) {
    printf("  %-26s %s\n", $key, (string) (SiteSetting::query()->where('key', $key)->value('value') ?? '(not saved: the default)'));
}

echo "\n== Queue, uploads and disk ==\n";
echo '  queue: ', config('queue.default'), ', retry after ', (int) config('queue.connections.'.config('queue.default').'.retry_after'), " s (a speech to text step is allowed 140 s)\n";
$workers = array_values(array_filter(array_map('trim', explode("\n", (string) @shell_exec('ps -eo args 2>/dev/null'))), fn ($l) => str_contains($l, 'queue:work') || str_contains($l, 'queue:listen')));
echo '  queue workers running: ', count($workers), "\n";
foreach (array_slice($workers, 0, 6) as $w) {
    echo '    ', $short(preg_replace('#/home/[^ ]+/#', '…/', $w)), str_contains($w, '--queue') ? '' : '   (no --queue: the default queue only)', "\n";
}
printf("  PHP: upload_max_filesize %s, post_max_size %s, memory_limit %s, max_execution_time %s\n",
    ini_get('upload_max_filesize'), ini_get('post_max_size'), ini_get('memory_limit'), ini_get('max_execution_time'));
$cron = array_values(array_filter(array_map('trim', explode("\n", (string) @shell_exec('crontab -l 2>/dev/null'))), fn ($l) => $l !== '' && $l[0] !== '#' && preg_match('/artisan|queue/', $l) === 1));
echo '  cron lines that start Laravel: ', $cron === [] ? 'none in this user\'s crontab' : count($cron), "\n";
foreach (array_slice($cron, 0, 6) as $c) {
    echo '    ', $short(preg_replace('#/home/[^ ]+/#', '…/', $c)), "\n";
}
if (Schema::hasTable('jobs')) {
    $waiting = DB::table('jobs')->selectRaw('queue, count(*) as n, min(available_at) as oldest')->groupBy('queue')->get();
    echo '  jobs waiting: ', $waiting->isEmpty() ? 'none' : $waiting->map(fn ($q) => $q->queue.' '.$q->n.' (oldest '.max(0, time() - (int) $q->oldest).' s)')->implode(', '), "\n";
}
if (Schema::hasTable('failed_jobs')) {
    $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(7))->selectRaw('queue, count(*) as n')->groupBy('queue')->get();
    echo '  jobs failed in 7 days: ', $failed->isEmpty() ? 'none' : $failed->map(fn ($q) => $q->queue.' '.$q->n)->implode(', '), "\n";
}
$free = @disk_free_space(storage_path('app'));
echo '  free disk for storage/app: ', $free ? number_format($free / 1073741824, 1).' GB' : '?', "\n";

echo "\n== Plans' card settings as JSON (no Stripe ids) ==\n";
$hidden = ['stripe_product_id', 'stripe_price_monthly', 'stripe_price_yearly', 'stripe_coupon_id', 'created_at', 'updated_at'];
echo json_encode($plans->map(fn (Plan $p) => array_diff_key($p->getAttributes(), array_flip($hidden)))->all(), JSON_UNESCAPED_UNICODE), "\n";
