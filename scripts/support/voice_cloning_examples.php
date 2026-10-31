<?php

/*
 * For the "Server deploy" workflow's voice-cloning-examples mode, on STAGING only:
 * the two examples the voice cloning stage plays, one in Arabic and one in
 * English, each saying it is a cloned voice. They are spoken by the admin
 * email's own ready cloned voice (an Arabic one for Arabic and an English one
 * for English when there are both; otherwise the one voice speaks both), so
 * what they say is true. Nothing is made when the admin has no ready cloned
 * voice. The clips (MP3, at the studio's loudness) are left in
 * storage/app/listen-deploy/voice-cloning-examples/ for the workflow to take;
 * they are committed to public/audio/voice-cloning/examples/ by hand.
 *
 * It uses the speech quota staging shares with live (two short requests), so
 * it runs only when the owner has said yes. It refuses to run on the live site.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/voice_cloning_examples.php
 */

use App\Models\ClonedVoice;
use App\Models\User;
use App\Services\VoiceCloningService;
use App\Support\LiveServer;
use App\Support\ShareableAudio;
use App\Support\VoiceCloningAccess;
use Illuminate\Contracts\Console\Kernel;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";

if (LiveServer::allowsCustomerActions() || str_contains(strtolower((string) config('app.url')), (string) config('listen.live_host', 'listenai.studio'))) {
    echo "This is the live site: voice-cloning-examples runs on staging only.\n";
    exit(1);
}
$admin = User::query()->where('email', VoiceCloningAccess::PREVIEW_EMAIL)->first();
if (! $admin) {
    echo "No account with the admin email on this server.\n";
    exit(1);
}
$voices = ClonedVoice::query()->where('user_id', $admin->id)->ready()->latest('id')->get();
echo 'The admin\'s ready cloned voices: ', $voices->map(fn (ClonedVoice $v) => "#{$v->id} {$v->language_code}")->implode(', ') ?: 'none', "\n";
if ($voices->isEmpty()) {
    echo "Nothing made: clone your voice on staging first (Dashboard → Voice cloning), then run this again.\n";
    exit(1);
}

$arabic = $voices->first(fn (ClonedVoice $v) => str_starts_with((string) $v->language_code, 'ar')) ?? $voices->first();
$english = $voices->first(fn (ClonedVoice $v) => str_starts_with((string) $v->language_code, 'en')) ?? $arabic;
$lines = [
    'ar' => [$arabic, (string) trans('ui.voice_cloning.example_line', [], 'ar')],
    'en' => [$english, (string) trans('ui.voice_cloning.example_line', [], 'en')],
];

$service = app(VoiceCloningService::class);
$dir = storage_path('app/listen-deploy/voice-cloning-examples');
@mkdir($dir, 0700, true);
array_map('unlink', glob($dir.'/*') ?: []);
$failed = 0;
foreach ($lines as $language => [$voice, $line]) {
    $started = microtime(true);
    try {
        // The voice as recorded: no dialect, style or emotion added.
        $result = $service->synthesizeForVoice($voice, $line, trim($service->deliveryStyle(null)));
    } catch (\Throwable $e) {
        $failed++;
        echo "{$language}: not made with voice #{$voice->id}: ", mb_substr($e->getMessage(), 0, 300), "\n";

        continue;
    }
    $packaged = ShareableAudio::package((string) $result['audio_content']);
    $file = $dir.'/'.$language.'.'.$packaged['extension'];
    file_put_contents($file, $packaged['bytes']);
    $seconds = trim((string) shell_exec('ffprobe -v error -show_entries format=duration -of csv=p=0 '.escapeshellarg($file).' 2>/dev/null'));
    printf("%s: voice #%d (%s), %s s of audio, made in %.1f s, %d bytes, %s\n  \"%s\"\n",
        $language, $voice->id, $voice->language_code, $seconds !== '' ? round((float) $seconds, 1) : '?', microtime(true) - $started,
        strlen($packaged['bytes']), $packaged['extension'], $line);
}

exit($failed === count($lines) ? 1 : 0);
