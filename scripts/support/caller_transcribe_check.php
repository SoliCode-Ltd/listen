<?php

/*
 * Staging only, and read-only: a caller's words written again from their voice
 * (AgentCalls::hearPrompt and GeminiClient::transcribe, as hearCaller uses them), on the real
 * recorded call clips in public/audio/agents/calls, whose words are known. Each clip is given
 * a wrong hint, the way the live transcription gets Arabic wrong (in English, in Latin letters,
 * as spaced-out digits), and must come back in the clip's own language and dialect.
 *
 * Nothing is saved and no sound is made: the text model only listens to each clip.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/caller_transcribe_check.php
 */

use App\Models\Agent;
use App\Services\Agents\AgentCalls;
use App\Services\Agents\GeminiClient;
use App\Support\Agents\SpokenNumbers;
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

// Staging only: live runs real customers.
if (filter_var(config('listen.live_actions'), FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, "This check runs on staging only.\n");
    exit(1);
}
if (! method_exists(GeminiClient::class, 'transcribe') || ! method_exists(AgentCalls::class, 'hearPrompt')) {
    fwrite(STDERR, "This server's code does not write callers' lines again yet: deploy first.\n");
    exit(1);
}

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (! $ok) {
        $failures++;
    }
    echo '  '.($ok ? 'PASS ' : 'FAIL ').$what.PHP_EOL;
};

/** The words a clip says, from the landing page's real calls. */
$said = static function (string $clip, string $locale): string {
    foreach ((array) trans('agents_landing.real_calls', [], $locale) as $call) {
        foreach ((array) ($call['turns'] ?? []) as $turn) {
            if (($turn[3] ?? '') === $clip) {
                return (string) $turn[1];
            }
        }
    }

    return '';
};

/** A clip as 16 kHz mono 16-bit PCM (at most 15 s), as the call client sends a caller's line. */
$pcm = static function (string $file): string {
    $ffmpeg = trim((string) shell_exec('command -v ffmpeg')) ?: (is_file(storage_path('app/bin/ffmpeg')) ? storage_path('app/bin/ffmpeg') : '');
    if ($ffmpeg === '') {
        return '';
    }
    $p = proc_open([$ffmpeg, '-v', 'error', '-i', $file, '-t', '15', '-ac', '1', '-ar', '16000', '-f', 's16le', '-'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($p)) {
        return '';
    }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);

    return (string) $out;
};

$letters = static fn (string $text, string $script): int => preg_match_all($script === 'arabic' ? '/\p{Arabic}/u' : '/[A-Za-z]/', $text);

// clip, language, dialect, the wrong hint the live transcription might give.
$clips = [
    ['ar-sa-1', 'ar', 'ar-SA', 'Wa alaikum assalam, welcome to Lamsa dental clinic'],
    ['ar-eg-2', 'ar', 'ar-EG', '0 1 0 1 2 3 4 5 6 7 8'],
    ['ar-lb-2', 'ar', 'ar-LB', 'Tamam ya ustaz Jad, w raqmak'],
    ['ar-ae-1', 'ar', 'ar-AE', 'unbelievable'],
    ['en-us-2', 'en', 'en-US', 'شكرا جيك'],
    ['en-us-2', 'en', 'en-US', ''],
];
// The thinking level hearCaller uses ("minimal" was slower and gave empty answers here).
$levels = ['low'];

$gemini = app(GeminiClient::class);
$times = [];

// Two seconds of a quiet line (faint noise, no words): nothing must be written.
mt_srand(7);
$noise = '';
for ($i = 0; $i < 32000; $i++) {
    $noise .= pack('v', (mt_rand(-60, 60)) & 0xFFFF);
}
$agent = new Agent(['name' => 'Check', 'business_name' => 'Check', 'language' => 'ar', 'dialect' => 'ar-SA']);
$quiet = $gemini->transcribe(AgentCalls::hearPrompt($agent, 'شكرا', ''), AgentCalls::wav($noise, 16000), 15);
echo PHP_EOL.'== a quiet line (2 s of faint noise)'.PHP_EOL.'  answer: '.json_encode($quiet, JSON_UNESCAPED_UNICODE).PHP_EOL;
$check(AgentCalls::cleanHeard($quiet, 'شكرا') === null, 'nothing is made up from a quiet line');
foreach ($clips as [$clip, $language, $dialect, $hint]) {
    $file = public_path("audio/agents/calls/{$clip}.mp3");
    $words = $said($clip, $language);
    echo PHP_EOL."== {$clip} ({$dialect})".PHP_EOL.'  said:   '.$words.PHP_EOL.'  hint:   '.$hint.PHP_EOL;
    $audio = is_file($file) ? $pcm($file) : '';
    if ($audio === '' || $words === '') {
        $check(false, 'the clip and its words are on this server');

        continue;
    }
    $agent = new Agent(['name' => 'Check', 'business_name' => 'Check', 'language' => $language, 'dialect' => $dialect]);
    $line = null;
    foreach ($levels as $level) {
        $started = microtime(true);
        $answer = $gemini->transcribe(AgentCalls::hearPrompt($agent, $hint, ''), AgentCalls::wav($audio, 16000), 15, $level);
        $took = microtime(true) - $started;
        $times[$level][] = $took;
        echo sprintf('  %-8s %.1f s: %s', $level, $took, $answer === null ? '(no answer)' : json_encode($answer, JSON_UNESCAPED_UNICODE)).PHP_EOL;
        if ($level === 'low') {
            $raw = $answer;
            $seconds = $took;
        }
    }
    $line = AgentCalls::cleanHeard($raw, $words);
    $line = $line === null ? null : SpokenNumbers::digits($line);
    echo '  wrote:  '.($line ?? '(nothing)').PHP_EOL;
    echo sprintf('  %.1f s of sound, answered in %.1f s', strlen($audio) / 32000, $seconds).PHP_EOL;
    $check($line !== null, 'it wrote a line');
    if ($line === null) {
        continue;
    }
    if ($language === 'ar') {
        $check($letters($line, 'arabic') > 10 && $letters($line, 'latin') <= 3, 'in Arabic letters, not English or Latin letters');
    } else {
        $check($letters($line, 'latin') > 10 && $letters($line, 'arabic') === 0, 'in English, not the Arabic hint');
    }
    $expected = SpokenNumbers::digits($words);
    similar_text(preg_replace('/[^\p{L}\p{N}]+/u', '', $expected), preg_replace('/[^\p{L}\p{N}]+/u', '', $line), $percent);
    $check($percent >= 60, sprintf('close to what was said (%d%% alike)', $percent));
    if (preg_match('/\d{7,}/', $expected, $number)) {
        $check(str_contains(preg_replace('/\s+/', '', $line), $number[0]), 'the phone number as one number: '.$number[0]);
    }
}

echo PHP_EOL.'== Summary'.PHP_EOL;
foreach ($times as $level => $list) {
    sort($list);
    echo sprintf('  Answer time (%s thinking): %.1f s at most, %.1f s in the middle (the call shows the live line after 3.5 s).', $level, end($list), $list[intdiv(count($list), 2)]).PHP_EOL;
}
echo '  '.($failures === 0 ? 'Every check passed.' : $failures.' check(s) failed.').PHP_EOL;
exit($failures === 0 ? 0 : 1);
