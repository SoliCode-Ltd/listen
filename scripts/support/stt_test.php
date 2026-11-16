<?php

/*
 * For the "Server deploy" workflow's stt-test mode, on STAGING only: speech to
 * text from start to end with this server's code, for a throwaway member made
 * for the run and removed at its end (with everything it made). Nothing reaches
 * a real member, and nothing is emailed: mail goes to the array mailer here.
 *
 *  1. Two short recordings made from the studio's own voice samples: two Arabic
 *     voices one after the other (two speakers), and one English voice.
 *  2. Each written out the way the page does it (TranscriptionFlow), here, not
 *     on the queue: the minutes held and used, the speakers, the sentences and
 *     their times (in order, inside the audio, never a sentence twice), every
 *     download (TXT, SRT, VTT, DOCX), and a translation made once and kept.
 *  3. The developer API (/v1/transcriptions: a file, the list, one, its export,
 *     a refusal) and the MCP tools (transcribe_audio from a link to a sample on
 *     this server, get_transcription as subtitles), through this server's routes.
 *  4. The dashboard as this member and as a member without it, in English and
 *     Arabic: the section is there, no text shows as its key, and no word is
 *     said twice in a row.
 *
 * Speech to text is shown to this run only (the audience setting is not
 * changed). It uses the Gemini quota staging shares with live: a few requests
 * for about half a minute of audio, and Google Translate once. It refuses to
 * run on the live site.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/stt_test.php
 */

use App\Http\Controllers\Api\V1\TranscriptionController;
use App\Models\ApiKey;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Transcription;
use App\Models\User;
use App\Models\Voice;
use App\Services\Transcription\TranscriptEditor;
use App\Services\Transcription\TranscriptExport;
use App\Services\Transcription\TranscriptionFlow;
use App\Support\AudioFormats;
use App\Support\LiveServer;
use App\Support\Transcription\TranscriptionAccess;
use App\Support\Transcription\TranscriptionSettings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
ini_set('memory_limit', '1024M');
set_time_limit(0);
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";

if (LiveServer::allowsCustomerActions() || str_contains(strtolower((string) config('app.url')), (string) config('listen.live_host', 'listenai.studio'))) {
    echo "This is the live site: stt-test runs on staging only.\n";
    exit(1);
}

// This process only: its own cache (so the audience below is seen by nobody else),
// the work done here and not on the queue, and no email sent.
config(['cache.default' => 'array', 'queue.default' => 'sync', 'mail.default' => 'array']);
Cache::put('site_setting.transcription_audience', TranscriptionSettings::AUDIENCE_EVERYONE, 3600);

// STT_DRY=1, on a developer's machine only (never on the servers): the model's and the
// translator's answers are made up, to try the rest of this script without a key.
if (getenv('STT_DRY') === '1' && ! str_contains((string) config('app.url'), 'staging')) {
    $app->instance(\App\Services\Tts\GeminiKeyManager::class, new class extends \App\Services\Tts\GeminiKeyManager
    {
        public function getAllKeys(): array
        {
            return [['key' => 'dry', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1]];
        }
    });
    \Illuminate\Support\Facades\Http::fake([
        'generativelanguage.googleapis.com/*' => \Illuminate\Support\Facades\Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'language' => 'ar', 'speakers' => [['id' => 'A', 'voice' => 'female'], ['id' => 'B', 'voice' => 'male']],
            'paragraphs' => [['speaker' => 'A', 'text' => 'أهلًا وسهلًا بكم.'], ['speaker' => 'B', 'text' => 'شكرًا لكم، يسعدني أن أكون هنا.']],
        ], JSON_UNESCAPED_UNICODE)]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 900, 'candidatesTokenCount' => 30]]),
        'translate.googleapis.com/*' => \Illuminate\Support\Facades\Http::response([[['Hello and welcome.', 'x', null, null, 10]], null, 'ar']),
        '*' => \Illuminate\Support\Facades\Http::response('', 404),
    ]);
    echo "DRY RUN: made-up answers.\n";
}

$failures = [];
$check = function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? '  ok    ' : '  FAIL  '), $what, "\n";
    if (! $ok) {
        $failures[] = $what;
    }
};
$work = storage_path('app/listen-deploy/stt-test');
@mkdir($work, 0700, true);
$ffmpeg = AudioFormats::ffmpegPath();
if (! $ffmpeg) {
    echo "No ffmpeg on this server.\n";
    exit(1);
}

/** The homepage's voice samples (public/audio/voice-previews), with the words each one says. */
$clips = \App\Console\Commands\TranscribeMeasureCommand::clipCatalog();
$sample = function (string $prefix, array $not = []) use ($clips): ?array {
    foreach ($clips as $clip) {
        if (str_starts_with(strtolower($clip['dialect']), $prefix) && ! in_array($clip['slug'], $not, true)) {
            return ['name' => $clip['slug'].' ('.$clip['dialect'].')', 'slug' => $clip['slug'], 'path' => public_path($clip['file']), 'url' => url($clip['file']), 'said' => $clip['said']];
        }
    }
    echo "  no sample for {$prefix}: ".count($clips)." samples here\n";

    return null;
};
/** How close the words written out are to the words said, 0 to 100 (by letters, as the measuring tool counts). */
$likeness = function (string $heard, string $said): float {
    $norm = fn (string $t): string => trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s]/u', '', mb_strtolower($t)) ?? '') ?? '');
    $a = mb_str_split($norm($heard));
    $b = mb_str_split($norm($said));
    if ($b === []) {
        return 0.0;
    }
    $prev = range(0, count($b));
    foreach ($a as $i => $ca) {
        $cur = [$i + 1];
        foreach ($b as $j => $cb) {
            $cur[] = min($prev[$j + 1] + 1, $cur[$j] + 1, $prev[$j] + ($ca === $cb ? 0 : 1));
        }
        $prev = $cur;
    }

    return max(0.0, 100.0 * (1 - end($prev) / count($b)));
};
$run = function (array $command): void {
    (new Process($command))->setTimeout(120)->mustRun();
};
echo "\n== The recordings ==\n";
$arA = $sample('ar');
$arB = $arA ? $sample('ar', [$arA['slug']]) : null;
$en = $sample('en');
if (! $arA || ! $arB || ! $en) {
    echo "Could not find the voice samples (Arabic female, Arabic male, English).\n";
    exit(1);
}
$dialogue = $work.'/arabic-two-voices.mp3';
$run([$ffmpeg, '-v', 'error', '-y', '-i', $arA['path'], '-i', $arB['path'], '-filter_complex',
    '[0:a]aresample=24000,aformat=channel_layouts=mono[a];[1:a]aresample=24000,aformat=channel_layouts=mono[b];anullsrc=r=24000:cl=mono,atrim=0:1[g];[a][g][b]concat=n=3:v=0:a=1[o]',
    '-map', '[o]', '-c:a', 'libmp3lame', '-b:a', '64k', $dialogue]);
$english = $work.'/english.mp3';
copy($en['path'], $english);
echo '  Arabic: ', $arA['name'], ' then ', $arB['name'], "; English: ", $en['name'], "\n";

// ---------------------------------------------------------------- the member
// The cheapest plan with speech to text whose members the API serves.
$plan = Plan::query()->where('transcription_enabled', true)->where('transcription_minutes_month', '>', 0)->orderBy('price_cents')->get()
    ->first(fn (Plan $p): bool => \App\Support\Api\ApiPlanAccess::enabled($p));
if (! $plan) {
    echo "No plan includes both speech to text and the API.\n";
    exit(1);
}
$stamp = now()->format('YmdHis');
$member = User::query()->create(['name' => 'Speech to text test', 'email' => "stt-test-{$stamp}@example.invalid", 'password' => bin2hex(random_bytes(16))]);
$member->forceFill(['email_verified_at' => now(), 'subscription_status_checked_at' => now()])->save();
$subscription = Subscription::query()->where('user_id', $member->id)->latest('id')->first()
    ?? new Subscription(['user_id' => $member->id]);
$subscription->forceFill(['plan_id' => $plan->id, 'status' => 'active', 'current_period_start' => now()->startOfDay(), 'current_period_end' => now()->addMonth()])->save();
$free = User::query()->create(['name' => 'Speech to text free test', 'email' => "stt-test-free-{$stamp}@example.invalid", 'password' => bin2hex(random_bytes(16))]);
$free->forceFill(['email_verified_at' => now(), 'subscription_status_checked_at' => now()])->save();
$apiKey = null;
echo "\nThrowaway members {$member->id} (plan {$plan->slug}: {$plan->transcription_minutes_month} min, files up to {$plan->transcription_max_file_minutes} min) and {$free->id} (free).\n";

$http = $app->make(HttpKernel::class);
$call = function (string $method, string $uri, array $params = [], array $files = [], array $headers = [], ?string $json = null) use ($http, $app): array {
    $server = ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1'];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }
    if ($json !== null) {
        $server['CONTENT_TYPE'] = 'application/json';
    }
    $request = Request::create(url($uri), $method, $params, [], $files, $server, $json);
    $response = $http->handle($request);
    $http->terminate($request, $response);
    $app->instance('request', Request::create(url('/')));

    return [$response->getStatusCode(), (string) $response->getContent(), $response];
};

try {
    // ------------------------------------------------------------ the page's flow
    $flow = app(TranscriptionFlow::class);
    $results = [];
    foreach (['ar' => $dialogue, 'en' => $english] as $language => $file) {
        echo "\n== {$language}: as the page makes it ==\n";
        $copy = $work.'/upload-'.$language.'.mp3';
        copy($file, $copy);
        $before = TranscriptionAccess::balance($member)['used'];
        $started = microtime(true);
        $t = $flow->start($member, $copy, ['title' => basename($file), 'file_name' => basename($file), 'source' => 'upload', 'language' => 'auto']);
        $t->refresh();
        $took = microtime(true) - $started;
        $results[$language] = $t;
        $used = TranscriptionAccess::balance($member)['used'] - $before;
        printf("  %.1f s of audio, written out in %.1f s, cost \$%.5f, model %s\n", (float) $t->seconds, $took, (float) $t->cost_usd, (string) $t->model);
        $check($t->status === Transcription::READY, "it is ready (status {$t->status}".($t->error ? ": {$t->error}" : '').')');
        if ($t->status !== Transcription::READY) {
            continue;
        }
        $check($used === TranscriptionAccess::chargeFor((float) $t->seconds) && $used === $t->charged_seconds, "every second used, no more: {$used} s for ".round((float) $t->seconds, 2).' s');
        $check($t->language === $language, "language found: {$t->language}");
        $speakers = $t->speakerCount();
        $check($language === 'ar' ? $speakers === 2 : $speakers === 1, "speakers: {$speakers}");
        foreach ((array) $t->paragraphs as $p) {
            echo '    [', $p['speaker'], ' ', number_format((float) $p['start'], 1), '-', number_format((float) $p['end'], 1), '] ', $p['text'], "\n";
        }
        $segments = array_values((array) $t->segments);
        $inOrder = true;
        $inside = true;
        $previous = -1.0;
        foreach ($segments as $s) {
            $inOrder = $inOrder && (float) $s['start'] >= $previous - 0.01 && (float) $s['end'] >= (float) $s['start'];
            $inside = $inside && (float) $s['end'] <= (float) $t->seconds + 0.5;
            $previous = (float) $s['end'];
        }
        $check($segments !== [] && $inOrder, count($segments).' sentences, in order, none ending before it starts');
        $check($inside, 'every sentence inside the audio');
        $norm = fn (string $s): string => mb_strtolower(trim(preg_replace('/[\p{P}\s]+/u', ' ', $s) ?? ''));
        $texts = array_map(fn ($s) => $norm((string) $s['text']), $segments);
        $check(count($texts) === count(array_unique($texts)), 'no sentence twice');
        $said = $language === 'ar' ? $arA['said'].' '.$arB['said'] : $en['said'];
        $close = $likeness(implode(' ', array_column((array) $t->paragraphs, 'text')), $said);
        echo '    said: ', preg_replace('/\s+/u', ' ', $said), "\n";
        $check($close >= 85.0, sprintf('the words match what was said: %.1f%% alike', $close));
        $words = preg_split('/\s+/u', $norm(implode(' ', array_column((array) $t->paragraphs, 'text')))) ?: [];
        $twice = [];
        for ($i = 1; $i < count($words); $i++) {
            if ($words[$i] !== '' && $words[$i] === $words[$i - 1] && mb_strlen($words[$i]) > 2) {
                $twice[] = $words[$i];
            }
        }
        $check($twice === [], 'no word twice in a row'.($twice ? ': '.implode(', ', $twice) : ''));

        foreach (TranscriptExport::FORMATS as $format) {
            $out = app(TranscriptExport::class)->export($t, $format);
            $body = (string) $out['body'];
            $ok = match ($format) {
                'txt' => trim($body) !== '',
                'srt' => preg_match_all('/\d\d:\d\d:\d\d,\d{3} --> \d\d:\d\d:\d\d,\d{3}/', $body) >= 1,
                'vtt' => str_starts_with(ltrim($body, "\u{FEFF}"), 'WEBVTT'),
                'docx' => (function () use ($body, $work): bool {
                    $file = $work.'/check.docx';
                    file_put_contents($file, $body);
                    $zip = new ZipArchive;
                    $ok = $zip->open($file) === true && str_contains((string) $zip->getFromName('word/document.xml'), '<w:t');
                    $ok && $zip->close();

                    return $ok;
                })(),
            };
            if ($format === 'srt') {
                preg_match_all('/-->[^\n]*\n(.+?)(?:\n\n|$)/s', $body, $cues);
                $cueTexts = array_map(fn ($c) => $norm($c), $cues[1] ?? []);
                $repeats = count($cueTexts) - count(array_unique($cueTexts));
                $check($repeats === 0, 'SRT: no subtitle twice ('.count($cueTexts).' cues)');
            }
            $check($ok, strtoupper($format).' download ('.strlen($body).' bytes, '.$out['name'].')');
        }

        $to = $language === 'ar' ? 'en' : 'ar';
        $translated = app(TranscriptEditor::class)->translate($t, $to);
        $check(count($translated['paragraphs']) === count((array) $t->paragraphs) && trim((string) ($translated['paragraphs'][0]['text'] ?? '')) !== '', "translated to {$to}: ".mb_substr((string) ($translated['paragraphs'][0]['text'] ?? ''), 0, 80));
        $again = app(TranscriptEditor::class)->translate($t->fresh(), $to);
        $check($again['at'] === $translated['at'], 'the translation is kept, not made again');
        $exported = (string) app(TranscriptExport::class)->export($t->fresh(), 'srt', $to)['body'];
        $check(str_contains($exported, mb_substr(trim((string) $translated['segments'][0]['text']), 0, 12)), "SRT in {$to}");
    }

    // ------------------------------------------------------------ the developer API
    echo "\n== The developer API ==\n";
    ['key' => $apiKey, 'plain' => $plain] = ApiKey::issue($member, 'Speech to text test');
    $auth = ['X-Listen-Api-Key' => $plain];
    $upload = $work.'/api-upload.mp3';
    copy($english, $upload);
    [$status, $body] = $call('POST', '/v1/transcriptions', ['language_code' => 'en', 'title' => 'API test'], ['file' => new UploadedFile($upload, 'api-test.mp3', 'audio/mpeg', null, true)], $auth);
    $made = json_decode($body, true) ?: [];
    $check($status === 202 && str_starts_with((string) ($made['transcription_id'] ?? ''), 'tr_'), "POST /v1/transcriptions with a file: {$status}");
    if (isset($made['transcription_id'])) {
        [$status, $body] = $call('GET', '/v1/transcriptions/'.$made['transcription_id'], [], [], $auth);
        $one = json_decode($body, true) ?: [];
        $check($status === 200 && ($one['status'] ?? '') === 'completed' && trim((string) ($one['text'] ?? '')) !== '', 'GET it: '.($one['status'] ?? $status).', "'.mb_substr((string) ($one['text'] ?? ''), 0, 60).'"');
        $check(count($one['sentences'] ?? []) > 0 && array_keys($one['download_urls'] ?? []) === TranscriptExport::FORMATS, 'its sentences and downloads');
        [$status, $body, $response] = $call('GET', '/v1/transcriptions/'.$made['transcription_id'].'/export', ['format' => 'vtt'], [], $auth);
        $check($status === 200 && str_contains($body, 'WEBVTT'), "export as VTT: {$status}");
    }
    [$status, $body] = $call('GET', '/v1/transcriptions', ['page_size' => 2], [], $auth);
    $list = json_decode($body, true) ?: [];
    $check($status === 200 && count($list['transcriptions'] ?? []) === 2 && ($list['has_more'] ?? false) === true, 'GET /v1/transcriptions: 2 of 3, has_more');
    [$status, $body] = $call('GET', '/v1/user/subscription', [], [], $auth);
    $minutes = (json_decode($body, true) ?: [])['speech_to_text'] ?? [];
    $check($status === 200 && ($minutes['seconds_used'] ?? -1) === TranscriptionAccess::balance($member)['used'], 'the account shows the minutes used: '.($minutes['seconds_used'] ?? '?').' s, '.($minutes['seconds_left'] ?? '?').' s left');
    [$status, $body] = $call('POST', '/v1/transcriptions', [], [], $auth, json_encode(['url' => 'https://example.invalid/none.mp3']));
    $error = (json_decode($body, true) ?: [])['error'] ?? [];
    $check($status === 422 && ($error['code'] ?? '') === 'url_not_fetchable', 'a link that does not work is refused: '.($error['code'] ?? $status));
    $freeKey = ApiKey::issue($free, 'Speech to text test')['plain'];
    [$status, $body] = $call('GET', '/v1/transcriptions', [], [], ['X-Listen-Api-Key' => $freeKey]);
    $check(in_array($status, [403], true), "the free plan's key is refused: {$status} ".((json_decode($body, true) ?: [])['error']['code'] ?? ''));
    ApiKey::query()->where('user_id', $free->id)->delete();

    // ------------------------------------------------------------ the MCP tools
    echo "\n== The MCP tools ==\n";
    $mcp = function (string $method, array $params = []) use ($call, $plain): array {
        [$status, $body] = $call('POST', '/mcp', [], [], ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'],
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method] + ($params === [] ? [] : ['params' => $params])));

        return [$status, json_decode($body, true) ?: []];
    };
    [, $tools] = $mcp('tools/list');
    $names = array_column($tools['result']['tools'] ?? [], 'name');
    $check(count(array_intersect(['transcribe_audio', 'get_transcription', 'list_transcriptions'], $names)) === 3, 'tools/list has the three speech to text tools');
    if ($en['url'] !== null) {
        [, $started] = $mcp('tools/call', ['name' => 'transcribe_audio', 'arguments' => ['audio_url' => $en['url']]]);
        $id = (string) ($started['result']['structuredContent']['transcription_id'] ?? '');
        $check($id !== '' && empty($started['result']['isError']), 'transcribe_audio from a link on this server: '.mb_substr((string) ($started['result']['content'][0]['text'] ?? json_encode($started)), 0, 140));
        if ($id !== '') {
            [, $read] = $mcp('tools/call', ['name' => 'get_transcription', 'arguments' => ['transcription_id' => $id, 'format' => 'srt']]);
            $text = (string) ($read['result']['content'][0]['text'] ?? '');
            $check(str_contains($text, '-->'), 'get_transcription as SRT');
        }
    } else {
        echo "  (no public link to a sample here: transcribe_audio not tried)\n";
    }
    [, $account] = $mcp('tools/call', ['name' => 'get_account', 'arguments' => (object) []]);
    $check(str_contains((string) ($account['result']['content'][0]['text'] ?? ''), 'Speech to text:'), 'get_account shows the minutes');

    // ------------------------------------------------------------ the dashboard
    echo "\n== The dashboard ==\n";
    foreach ([[$member, 'with the plan'], [$free, 'free plan']] as [$who, $label]) {
        foreach (['en', 'ar'] as $locale) {
            // The dashboard as its controller renders it for this member, in this language.
            app()->setLocale($locale);
            \Mcamara\LaravelLocalization\Facades\LaravelLocalization::setLocale($locale);
            $request = Request::create(url('/'.$locale.'/dashboard'), 'GET');
            $request->setLaravelSession(app('session')->driver());
            $request->setUserResolver(fn () => $who);
            $app->instance('request', $request);
            Auth::guard('web')->setUser($who);
            \Illuminate\Support\Facades\View::share('errors', new \Illuminate\Support\ViewErrorBag);
            try {
                $view = app(\App\Http\Controllers\DashboardController::class)->index($request);
                $html = $view instanceof \Illuminate\Contracts\View\View ? $view->render() : (string) ($view->getContent() ?? '');
                $status = $view instanceof \Symfony\Component\HttpFoundation\Response ? $view->getStatusCode() : 200;
            } finally {
                Auth::guard('web')->logout();
                app()->setLocale('en');
            }
            // The speech to text section only, as read: its text, without scripts, styles or hidden templates.
            $dom = new DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
            $node = $dom->getElementById('transcribeSection');
            $section = $node ? $dom->saveHTML($node) : '';
            $text = '';
            if ($node) {
                foreach ((new DOMXPath($dom))->query('.//script|.//style|.//template', $node) as $drop) {
                    $drop->parentNode->removeChild($drop);
                }
                $parts = [];
                foreach ((new DOMXPath($dom))->query('.//text()', $node) as $piece) {
                    $parts[] = $piece->nodeValue;
                }
                $text = trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');
            }
            $check($status === 200 && $section !== '', "{$label}, {$locale}: the section is there ({$status})");
            $check(! preg_match('/transcribe\.(page|js|refused|failed)\./', $html), "{$label}, {$locale}: no text shown as its key");
            $nav = $locale === 'ar' ? 'تحويل الكلام إلى نص' : 'Speech to text';
            $check(str_contains($html, $nav), "{$label}, {$locale}: the menu says \"{$nav}\"");
            $words = preg_split('/\s+/u', mb_strtolower($text)) ?: [];
            $twice = [];
            for ($i = 1; $i < count($words); $i++) {
                if ($words[$i] === $words[$i - 1] && preg_match('/\p{L}{3,}/u', $words[$i])) {
                    $twice[] = $words[$i];
                }
            }
            $check($twice === [], "{$label}, {$locale}: no word twice in a row".($twice ? ': '.implode(', ', array_unique($twice)) : ''));
            $root = $locale === 'ar' ? 'ترجم' : 'translat';
            echo "        \"{$root}\" ", substr_count(mb_strtolower($text), $root), " times; the text: ", mb_substr($text, 0, 1600), "\n";
        }
    }
} catch (\Throwable $e) {
    $check(false, 'stopped: '.get_class($e).': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine());
} finally {
    echo "\n== Clean up ==\n";
    foreach ([$member, $free] as $who) {
        foreach (Transcription::withTrashed()->where('user_id', $who->id)->get() as $t) {
            Storage::disk(TranscriptionFlow::DISK)->deleteDirectory(TranscriptionFlow::folder($t));
            $t->forceDelete();
        }
        try {
            \App\Models\ApiRequestLog::query()->where('user_id', $who->id)->delete();
            ApiKey::query()->where('user_id', $who->id)->delete();
            Subscription::query()->where('user_id', $who->id)->delete();
            method_exists($who, 'forceDelete') ? $who->forceDelete() : $who->delete();
        } catch (\Throwable $e) {
            $check(false, "removing member {$who->id}: ".$e->getMessage());
        }
    }
    foreach (glob($work.'/*') ?: [] as $file) {
        @unlink($file);
    }
    echo '  The throwaway members, their transcripts, keys and files are gone: ',
        User::query()->whereIn('email', ["stt-test-{$stamp}@example.invalid", "stt-test-free-{$stamp}@example.invalid"])->count() === 0 ? 'yes' : 'NO', "\n";
}

echo "\n", $failures === [] ? 'ALL CHECKS PASSED' : count($failures).' CHECK(S) FAILED:'."\n  - ".implode("\n  - ", $failures), "\n";
exit($failures === [] ? 0 : 1);
