<?php

/*
 * For the "Server deploy" workflow's dub-test mode, on STAGING only: dubs the
 * test video kept in storage/app/listen-deploy/dub-test/ with this server's
 * code, the way a member's dub is made (VideoDubber), so a change to dubbing
 * can be heard and measured before anyone tries it. It takes the settings of
 * the newest dub of a video that long (else the newest dub), changed by DUB
 * ("voice=Kore dialect=ar-EG ..."). It is no member's dub: no credits, no
 * Drive, nothing on a dashboard. The dubbed video (dub.mp4) and its report
 * (report.json) are left in that folder for the workflow to take.
 *
 * It uses the speech quota staging shares with live (a request for each line
 * and one or two to hear the video: about 25 for a minute and a half), so it
 * runs only when the owner has said yes. It refuses to run on the live site.
 *
 *   ssh user@host 'cd ~/public_html && DUB="voice=Kore" php' < scripts/support/dub_test.php
 */

use App\Models\VideoDub;
use App\Services\Dubbing\VideoDubber;
use App\Services\Dubbing\VoiceSeparator;
use App\Support\LiveServer;
use Illuminate\Contracts\Console\Kernel;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
ini_set('memory_limit', '1024M');
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";

if (LiveServer::allowsCustomerActions() || str_contains(strtolower((string) config('app.url')), (string) config('listen.live_host', 'listenai.studio'))) {
    echo "This is the live site: dub-test runs on staging only.\n";
    exit(1);
}
$dir = storage_path('app/listen-deploy/dub-test');
$source = $dir.'/source.mp4';
if (! is_file($source)) {
    echo "No test video: put one at $source first.\n";
    exit(1);
}
@unlink($dir.'/dub.mp4');
@unlink($dir.'/report.json');

$dubber = app(VideoDubber::class);
$seconds = (float) $dubber->probe($source)['seconds'];
printf("Test video: %.2f s, %.1f MB\n", $seconds, filesize($source) / 1048576);

echo "== The newest dubs here ==\n";
$recent = VideoDub::query()->latest('id')->limit(10)->get();
foreach ($recent->take(5) as $d) {
    echo '  ', json_encode(['id' => $d->id, 'at' => (string) $d->created_at, 'status' => $d->status, 'seconds' => $d->video_seconds,
        'language' => $d->language, 'dialect' => $d->dialect, 'voice' => $d->voice, 'style' => $d->performance_style,
        'emotion' => $d->emotion, 'keep_background' => $d->keep_background, 'free' => $d->is_free,
        'speech_map' => $d->report['speech_map'] ?? null, 'lines' => count((array) ($d->report['lines'] ?? []))], JSON_UNESCAPED_UNICODE), "\n";
}
$like = $recent->first(fn (VideoDub $d): bool => abs((float) $d->video_seconds - $seconds) < 1.0) ?? $recent->first();
$o = [
    'language' => $like?->language ?: 'ar',
    'dialect' => $like?->dialect ?: 'ar-EG',
    'voice' => $like?->voice ?: 'Kore',
    'voices' => [],
    'style' => $like?->performance_style,
    'emotion' => $like?->emotion,
    'background' => $like?->keep_background === false ? 'replace' : 'keep',
];
foreach (preg_split('/\s+/', trim((string) getenv('DUB'))) ?: [] as $pair) {
    if (preg_match('/^(language|dialect|voice|style|emotion|background)=(.*)$/', $pair, $m) === 1) {
        $o[$m[1]] = $m[2] === '' ? null : $m[2];
    }
}
echo 'Settings (from dub ', $like?->id ?? 'none', '): ', json_encode($o, JSON_UNESCAPED_UNICODE), "\n";

echo "== Taking the old voice out ==\n";
printf("  installed: %s, speech detection: %s, switched on: %s, memory: %s\n", VoiceSeparator::installed() ? 'yes' : 'no',
    VoiceSeparator::vadReady() ? 'yes' : 'no', VoiceSeparator::enabled() ? 'yes' : 'no', json_encode(VoiceSeparator::memory()));
if (! VoiceSeparator::vadReady() && VoiceSeparator::python() !== null) {
    // As "Install again" on the settings page does: the speech detection came with the last update.
    echo "  installing it again first\n";
    try {
        app(VoiceSeparator::class)->install(function (string $message): void {
            echo '    ', $message, "\n";
        });
    } catch (Throwable $e) {
        echo '  the install failed: ', $e->getMessage(), "\n";
    }
}

echo "== Dubbing ==\n";
$started = microtime(true);
$stage = '';
try {
    $report = $dubber->dub($source, $o + ['preview' => false, 'out_dir' => $dir, 'name' => 'dub',
        'progress' => function (string $now, int $done, int $total) use (&$stage, $started): void {
            if ($now !== $stage || ($now === 'voicing' && $done % 5 === 0)) {
                printf("  %6.1f s  %s %d/%d\n", microtime(true) - $started, $now, $done, $total);
                $stage = $now;
            }
        }]);
} catch (Throwable $e) {
    echo 'FAILED: ', $e->getMessage(), "\n";
    file_put_contents($dir.'/report.json', json_encode($dubber->lastReport, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo json_encode($dubber->lastReport, JSON_UNESCAPED_UNICODE), "\n";
    exit(1);
}
unset($report['output'], $report['preview']);
file_put_contents($dir.'/report.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "== Report ==\n";
foreach (['video_seconds', 'speech_map', 'transcribe', 'heard_again', 'segments', 'voicing', 'separation', 'fit', 'total_seconds'] as $key) {
    if (array_key_exists($key, $report)) {
        echo '  ', $key, ': ', json_encode($report[$key], JSON_UNESCAPED_UNICODE), "\n";
    }
}
echo "== Lines ==\n";
foreach ((array) ($report['lines'] ?? []) as $line) {
    printf("  %6.2f-%6.2f (heard %6.2f) %4.1f s ×%s %s%s\n      %s\n   -> %s\n", $line['start'], $line['end'], $line['heard_start'], $line['voiced_seconds'],
        $line['tempo'] ?? '—', isset($line['gain_db']) ? sprintf('%+.1f dB', $line['gain_db']) : '',
        implode('', array_map(fn (string $k): string => ! empty($line[$k]) ? ' ['.$k.']' : '', ['heard_again', 'as_said', 'written', 'placed_whole', 'quiet'])),
        $line['original'], $line['dubbed']);
}
printf("Done in %.1f s: %s (%.1f MB)\n", microtime(true) - $started, $dir.'/dub.mp4', filesize($dir.'/dub.mp4') / 1048576);
