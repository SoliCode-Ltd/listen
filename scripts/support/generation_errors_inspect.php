<?php
// Read-only: what Admin → Generation errors shows on LIVE, summarised.
// Member ids only; messages cut short. Writes nothing.
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
$db->statement('SET SESSION TRANSACTION READ ONLY');
$t = fn () => $db->table('generation_errors');
$cut = fn ($s, $n = 260) => mb_substr(preg_replace('/\s+/u', ' ', (string) $s), 0, $n);
echo 'Now (UTC): ', now()->toDateTimeString(), "\n";
echo 'Total rows: ', $t()->count(), ' · unresolved: ', $t()->whereNull('resolved_at')->count(), "\n";
foreach ([1, 3, 7, 30] as $d) {
    echo "Last {$d}d: rows ", $t()->where('last_seen_at', '>=', now()->subDays($d))->count(),
        ', occurrences ', (int) $t()->where('last_seen_at', '>=', now()->subDays($d))->sum('occurrences'), "\n";
}
echo "\n== Groups (last 14 days), most occurrences first ==\n";
$rows = $t()->where('last_seen_at', '>=', now()->subDays(14))
    ->selectRaw('source, severity, provider, voice_tier, exception_class, LEFT(message, 220) AS msg, SUM(occurrences) AS n, COUNT(*) AS r, COUNT(DISTINCT user_id) AS members, MIN(created_at) AS first, MAX(last_seen_at) AS last')
    ->groupBy('source', 'severity', 'provider', 'voice_tier', 'exception_class', 'msg')
    ->orderByDesc('n')->limit(40)->get();
foreach ($rows as $r) {
    echo "- [{$r->severity}] {$r->source} · {$r->provider} · {$r->voice_tier} · {$r->exception_class}\n";
    echo "  x{$r->n} ({$r->r} rows, {$r->members} members) {$r->first} → {$r->last}\n";
    echo '  ', $cut($r->msg), "\n";
}
echo "\n== Newest 25 ==\n";
foreach ($t()->orderByDesc('last_seen_at')->limit(25)->get() as $r) {
    $ctx = json_decode((string) $r->context, true) ?: [];
    unset($ctx['text'], $ctx['content'], $ctx['prompt']);
    echo "#{$r->id} {$r->last_seen_at} [{$r->severity}] {$r->source} member={$r->user_id} {$r->provider}/{$r->voice_tier} {$r->voice_name} {$r->target_language}/{$r->dialect} x{$r->occurrences}", $r->resolved_at ? ' RESOLVED' : '', "\n";
    echo '   ', $cut($r->exception_class.': '.$r->message, 400), "\n";
    if ($r->user_message) echo '   member saw: ', $cut($r->user_message, 200), "\n";
    if ($ctx) echo '   ctx: ', $cut(json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 400), "\n";
}
echo "\n== Audio generations failed per day (last 10 days) ==\n";
foreach ($db->table('audio_generations')->where('created_at', '>=', now()->subDays(10))
    ->selectRaw("DATE(created_at) d, SUM(status='failed') failed, SUM(status='done') ok, COUNT(*) total")
    ->groupBy('d')->orderBy('d')->get() as $r) {
    echo "  {$r->d}: failed {$r->failed} / done {$r->ok} / total {$r->total}\n";
}

echo "\n== Newest 15 audio generations (member id, status, voice) ==\n";
foreach ($db->table('audio_generations')->orderByDesc('id')->limit(15)->get() as $g) {
    $a = (array) $g;
    $voice = '';
    foreach ($a as $val) {
        if (is_string($val) && preg_match('/^(designed|cloned)_\d+$/', $val)) {
            $voice = $val;
            break;
        }
    }
    echo "  #{$a['id']} {$a['created_at']} member=", $a['user_id'] ?? '?', " status={$a['status']} ", $voice !== '' ? $voice : 'other voice', "\n";
}

echo "\n== Billing grace ==\n";
echo '  billing_grace_days setting: ', var_export(App\Models\SiteSetting::get('billing_grace_days'), true),
    ' (default ', var_export(config('listen.billing.grace_days', 7), true), ")\n";
echo '  billing_webhook_emails setting: ', var_export(App\Models\SiteSetting::get('billing_webhook_emails'), true),
    ' (default ', var_export(config('listen.billing.webhook_email_enabled', true), true), ")\n";
$graceUser = $db->table('users')->where('stripe_id', 'cus_VRQbqt51XEhfMF')->value('id');
foreach ($db->table('subscriptions')->where('user_id', $graceUser)->orderByDesc('id')->limit(2)
    ->get(['id', 'user_id', 'plan_id', 'status', 'billing_payment_state', 'billing_grace_ends_at', 'billing_failed_notice_sent_at', 'current_period_start', 'current_period_end']) as $row) {
    echo '  ', json_encode($row), "\n";
}

echo "\n== Declined-renewal email: is live ready? ==\n";
try {
    Illuminate\Support\Facades\Artisan::call('migrate:status', ['--pending' => true]);
    echo "  pending migrations:\n", preg_replace('/^/m', '    ', trim(Illuminate\Support\Facades\Artisan::output()) ?: 'none'), "\n";
} catch (Throwable $e) {
    echo '  pending migrations: could not be read (', mb_substr($e->getMessage(), 0, 160), ")\n";
}
echo '  purchase_follow_ups table: ', Illuminate\Support\Facades\Schema::hasTable('purchase_follow_ups') ? 'there' : 'MISSING', "\n";
if (Illuminate\Support\Facades\Schema::hasTable('purchase_follow_ups')) {
    foreach ($db->table('purchase_follow_ups')->selectRaw('kind, email_status, COUNT(*) n, SUM(paid_at IS NOT NULL) paid')->groupBy('kind', 'email_status')->orderBy('kind')->get() as $r) {
        echo "    {$r->kind} / {$r->email_status}: {$r->n} (paid after: {$r->paid})\n";
    }
    echo '  discount columns: ', Illuminate\Support\Facades\Schema::hasColumn('purchase_follow_ups', 'discount_percent') ? 'there' : 'not yet', "\n";
}
try {
    App\Support\TitanMailbox::apply();
    App\Support\TitanMailbox::requirePassword();
    echo "  newsletter mailbox: ready (the designed email can be sent)\n";
} catch (Throwable $e) {
    echo '  newsletter mailbox: NOT ready, the plain-text warning would go instead (', mb_substr($e->getMessage(), 0, 160), ")\n";
}
echo '  live server emails members: ', App\Support\LiveServer::allowsCustomerActions() ? 'yes' : 'no', "\n";
if (method_exists(App\Services\Billing\PurchaseFollowUps::class, 'renewalFailed')) {
    $fu = app(App\Services\Billing\PurchaseFollowUps::class);
    echo '  discounts switch: ', $fu->settings()['discounts_enabled'] === '1' ? 'ON' : 'off', "\n";
    foreach ([false, true] as $withDiscount) {
        try {
            $html = $fu->previewHtml(App\Models\PurchaseFollowUp::KIND_RENEWAL_FAILED, null, $withDiscount);
            echo '  preview ', $withDiscount ? 'with a discount' : 'warning', ': renders (', strlen($html), ' bytes', preg_match('/\{(name|plan|date|reason|discount|price|old_price|next)\}/', $html) ? ', A PLACEHOLDER IS LEFT IN IT' : ', no placeholder left', ")\n";
        } catch (Throwable $e) {
            echo '  preview ', $withDiscount ? 'with a discount' : 'warning', ': FAILS (', mb_substr($e->getMessage(), 0, 200), ")\n";
        }
    }
} else {
    echo "  the new code is not on this server yet\n";
}

echo "\n== Speech keys marked out (live cache) and today's key warnings in the logs ==\n";
$km = app(App\Services\Tts\GeminiKeyManager::class);
$model = App\Services\VoiceCloningService::MODEL;
try {
    $ref = new ReflectionClass($km);
    $keys = $km->getAllKeys();
    foreach ($keys as $k) {
        $name = is_array($k) ? ($k['name'] ?? '?') : (string) $k;
        echo "  key {$name}: out for today on {$model}=", $km->isOutForToday($name, (string) $model) ? 'YES' : 'no', "\n";
    }
} catch (Throwable $e) { echo '  (keys not listed: ', $e->getMessage(), ")\n"; }
$logs = glob(storage_path('logs/*.log')) ?: [];
usort($logs, fn ($a, $b) => filemtime($b) <=> filemtime($a));
$n = 0;
foreach (array_slice($logs, 0, 2) as $f) {
    foreach (file($f) ?: [] as $line) {
        if (str_starts_with($line, '['.now()->toDateString()) && preg_match('/daily limit for this model|spending cap|designed voice|Designed voice/i', $line)) {
            echo '  ', mb_substr(trim(preg_replace('/"text":"[^"]*"/', '"text":"…"', $line)), 0, 400), "\n";
            if (++$n >= 25) break 2;
        }
    }
}
if ($n === 0) echo "  none today\n";
