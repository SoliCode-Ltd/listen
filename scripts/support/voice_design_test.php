<?php

/*
 * For the "Server deploy" workflow's voice-design-test mode, on STAGING only:
 * one real design with this server's code, the way the dashboard makes one
 * (the line written for the character, then the takes, each speaking it), for
 * the admin email's account. It prints what each request took (statuses,
 * times, the token use the speech service reported, each sample's and take's
 * length) and what that costs at list prices, keeps take 1 in the admin's
 * library as "Staging test voice" (the others are deleted), and leaves the
 * takes' audio in storage/app/listen-deploy/voice-design-test/ for the
 * workflow to take. No credits are charged.
 *
 * It uses the speech quota staging shares with live (a text request and two
 * speech requests a take), so it runs only when the owner has said yes. It
 * refuses to run on the live site.
 *
 *   ssh user@host 'cd ~/public_html && VD="cairo_grandma" php' < scripts/support/voice_design_test.php
 */

use App\Models\User;
use App\Services\VoiceDesignService;
use App\Support\LiveServer;
use App\Support\PlanCostEstimate;
use App\Support\VoiceCloningAccess;
use App\Support\VoiceDesign;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";

if (LiveServer::allowsCustomerActions() || str_contains(strtolower((string) config('app.url')), (string) config('listen.live_host', 'listenai.studio'))) {
    echo "This is the live site: voice-design-test runs on staging only.\n";
    exit(1);
}
if (! \Illuminate\Support\Facades\Schema::hasTable('designed_voices')) {
    echo "The designed_voices table is missing: run this workflow with mode migrate first.\n";
    exit(1);
}
$admin = User::query()->where('email', VoiceCloningAccess::PREVIEW_EMAIL)->first();
if (! $admin) {
    echo "No account with the admin email on this server.\n";
    exit(1);
}

$key = (string) (getenv('VD') ?: 'cairo_grandma');
$example = collect(VoiceDesign::examples('en'))->firstWhere('key', $key) ?? collect(VoiceDesign::examples('en'))->first();
$designer = app(VoiceDesignService::class);
$takes = VoiceDesign::takes();
echo "Design: {$example['key']} ({$example['language']}), {$takes} takes\n";

$started = microtime(true);
$line = $designer->previewLine($example['description'], $example['language']);
$lineSeconds = round(microtime(true) - $started, 1);
echo "Line written in {$lineSeconds} s: {$line}\n";

$designStarted = microtime(true);
$result = $designer->design($admin, [
    'description' => $example['description'], 'language' => $example['language'], 'gender' => null, 'line' => $line,
    'projection' => VoiceDesign::SLIDER_MIDDLE, 'pace' => VoiceDesign::SLIDER_MIDDLE, 'variety' => VoiceDesign::SLIDER_MIDDLE,
], $takes);
$designSeconds = round(microtime(true) - $designStarted, 1);
$report = $designer->report;

echo "== Requests ==\n";
foreach ($report as $row) {
    echo '  ', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
}
if ($result['takes'] === []) {
    echo "No take came: {$result['error_code']} {$result['error']}\n";
    exit(1);
}

// The audio made: every sample the service sent and every line spoken.
$samples = array_sum(array_map(fn ($r) => (float) ($r['sample_seconds'] ?? 0), $report));
$spoken = array_sum(array_map(fn ($r) => (float) ($r['audio_seconds'] ?? 0), $report));
$tokens = array_sum(array_map(fn ($r) => (int) (data_get($r, 'usage.total_output_tokens') ?? data_get($r, 'usage.output_tokens') ?? data_get($r, 'usage.candidatesTokenCount') ?? 0), $report));
$prices = PlanCostEstimate::geminiPrices();
$later = PlanCostEstimate::geminiPrices(\Illuminate\Support\Carbon::create(2027, 1, 1));
$cost = fn (float $seconds, int $perSecond, array $price): float => round($seconds * $perSecond / 1_000_000 * $price['audio'], 4);

echo "== Summary ==\n";
echo json_encode([
    'takes' => count($result['takes']),
    'line_chars' => mb_strlen($line),
    'line_written_seconds' => $lineSeconds,
    'design_seconds' => $designSeconds,
    'total_seconds' => round($lineSeconds + $designSeconds, 1),
    'sample_audio_seconds' => round($samples, 1),
    'spoken_audio_seconds' => round($spoken, 1),
    'output_tokens_reported' => $tokens ?: null,
    'usd_if_samples_billed' => ['now' => $cost($samples + $spoken, PlanCostEstimate::AUDIO_TOKENS_PER_SECOND, $prices), 'now_high' => $cost($samples + $spoken, PlanCostEstimate::AUDIO_TOKENS_PER_SECOND_HIGH, $prices), '2027' => $cost($samples + $spoken, PlanCostEstimate::AUDIO_TOKENS_PER_SECOND_HIGH, $later)],
    'usd_lines_only' => ['now' => $cost($spoken, PlanCostEstimate::AUDIO_TOKENS_PER_SECOND, $prices), '2027' => $cost($spoken, PlanCostEstimate::AUDIO_TOKENS_PER_SECOND_HIGH, $later)],
    'usd_from_reported_tokens' => $tokens ? round($tokens / 1_000_000 * $prices['audio'], 4) : null,
    'member_would_pay_credits' => VoiceDesign::estimate($line, count($result['takes']))['credits'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

// The takes' audio for the run, then take 1 kept for the admin to try in the studio.
$dir = storage_path('app/listen-deploy/voice-design-test');
@mkdir($dir, 0700, true);
array_map('unlink', glob($dir.'/*') ?: []);
foreach ($result['takes'] as $take) {
    $path = (string) $take->preview_audio_path;
    if ($path !== '' && Storage::disk('public')->exists($path)) {
        copy(Storage::disk('public')->path($path), $dir.'/take-'.$take->take.'.'.pathinfo($path, PATHINFO_EXTENSION));
    }
}
$kept = $designer->keep($result['takes'][0], 'Staging test voice');
echo "Kept take 1 for the admin as \"{$kept->name}\" (designed_{$kept->id}); the other takes were deleted.\n";
