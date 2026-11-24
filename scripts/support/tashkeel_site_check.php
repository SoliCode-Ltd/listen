<?php

/**
 * Step 4 of the automatic pronunciation feature, checked on staging through
 * the site's own way of making a voice (the audio job, with the admin switch):
 * does the switch do what it says, what does the step cost for a text, and is
 * what was heard with one voice also heard with others. Staging only.
 *
 * It makes speech on the quota staging shares with live, so it is run only
 * when the owner has said yes, and it stops by itself at the minutes given
 * (4.5 unless ONLY carries capN).
 *
 *   ONLY=sitecheck,look   no speech: is the step on this server, the switch, the
 *                         test member and their plan, the premium Arabic voices
 *   ONLY=sitecheck        the check
 *   ONLY=sitecheck,first1 a trial: the first sentence of each set (then ONLY=sitecheck,skip1 for the rest)
 *
 * The check: written test sentences (homographs and names, not the known-hard
 * ones) are made for the test member, each twice: with the switch off ("today")
 * and with it on for test accounts and this member listed ("auto"). Two premium
 * voices other than the one heard so far read ten sentences each; the first of
 * them reads six more with a member's own direction. Two listening models say
 * which reading they hear; a word counts when both agree, and a disagreement is
 * measured afterwards (splits.json, tashkeel_formants.py).
 *
 * Before the "auto" voice of a sentence the step is run once by itself, inside
 * a cost meter: what Google bills for it, and the time it takes. The voice made
 * after it should then reuse the kept result, which the voice's record shows.
 * When the step marks nothing the request is the same as today's and no second
 * clip is made.
 *
 * The switch is left on "test accounts only" with this member listed.
 *
 * The voices are made for the staging checks' own paying test member
 * (staging-pages-paid@example.com, as the page check makes it): no Stripe
 * customer, the dearest plan for the run with model events off, back to the
 * free plan at the end. No email is sent.
 *
 *   ONLY=sitecheck,m123   another member of staging, as they are
 */

use App\Jobs\GenerateAudioJob;
use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voice;
use App\Services\PlanAccessService;
use App\Services\Tts\GeminiKeyManager;
use App\Support\AudioDuration;
use App\Support\GeminiSpend;
use App\Support\Tashkeel\AutoReadings;
use App\Support\Tashkeel\TashkeelScore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

ini_set('display_errors', 'stderr');
set_time_limit(2600);
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (str_contains(strtolower((string) config('app.url')), 'listenai.studio')) {
    fwrite(STDERR, "This is the live site: the tashkeel measurements run on staging only.\n");
    exit(1);
}
if (! class_exists(AutoReadings::class)) {
    echo "The automatic step is not on this server yet (App\\Support\\Tashkeel\\AutoReadings): deploy its branch to staging first.\n";
    exit(1);
}

$only = array_map('trim', explode(',', (string) getenv('ONLY')));
$look = in_array('look', $only, true);
$cap = 4.5;
$memberId = 0;
foreach ($only as $token) {
    if (preg_match('/^cap(\d)$/', $token, $m)) {
        $cap = (float) $m[1];
    }
    if (preg_match('/^m(\d+)$/', $token, $m)) {
        $memberId = (int) $m[1];
    }
}

// No email leaves a check: anything the site would send stays in memory.
config(['mail.default' => 'array']);
$access = app(PlanAccessService::class);
// The member the voices are made for: the staging checks' own paying test member (as the page check
// makes it: no Stripe customer, its plan set with model events off, so no welcome email and nothing
// at Stripe), unless ONLY names another (m123). The live site's test member is not on staging.
if ($memberId === 0) {
    $member = User::query()->firstOrCreate(['email' => 'staging-pages-paid@example.com'], ['name' => 'Staging pages (paid)', 'password' => bcrypt(Str::random(40))]);
    $memberId = (int) $member->id;
    $ownMember = true;
} else {
    $member = User::query()->find($memberId);
    $ownMember = false;
}
$freePlan = $access->freePlan();
// The dearest plan on sale that lets a member write their own direction, when one does; otherwise the dearest.
$onSale = Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)->where('voice_tier', 'hd')->orderByDesc('price_cents');
$paidPlan = (clone $onSale)->where('custom_prompt_enabled', true)->first() ?? $onSale->first();
if ($ownMember && ! $look && $paidPlan) {
    // The dearest plan on sale for the run (premium voices, a member's own direction); back to free at the end.
    Subscription::withoutEvents(fn () => $access->assignPlan($member, $paidPlan));
}
// Whatever stops the run, the checks' test member does not stay on a paid plan.
register_shutdown_function(function () use (&$ownMember, $member, $freePlan, $access): void {
    if ($ownMember && $member && $freePlan) {
        Subscription::withoutEvents(fn () => $access->assignPlan($member, $freePlan));
    }
});
$plan = $member ? $access->resolveAccessPlanForUserId($memberId) : null;
// Premium Arabic voices, as the studio lists them; the one heard in every test so far is left out.
$voices = Voice::query()->where('is_active', true)->where('tier', 'hd')->where('language', 'like', 'ar%')->orderBy('sort_order')->orderBy('id')->get()
    ->filter(fn (Voice $v): bool => stripos((string) $v->provider_voice_id.' '.$v->internal_voice_id, 'kore') === false)->values();
$female = $voices->first(fn (Voice $v): bool => strtolower((string) $v->gender) === 'female');
$male = $voices->first(fn (Voice $v): bool => strtolower((string) $v->gender) === 'male');
$chosen = array_values(array_filter([$male, $female]));

echo 'Now (UTC): ', now()->utc()->toDateTimeString(), '; the switch: ', AutoReadings::mode(), '; test accounts: ', implode(', ', AutoReadings::testMembers()) ?: 'none', "\n";
echo 'Test member ', $memberId, $ownMember ? ' (the staging checks\' own)' : '', ': ', $member ? 'found' : 'NOT FOUND', '; plan: ', $plan ? $plan->slug.' (voices: '.$plan->voice_tier.', own direction: '.($plan->custom_prompt_enabled ? 'yes' : 'no').')' : 'none (free)', "\n";
echo 'Premium Arabic voices: ', $voices->count(), '; for the check: ', implode(' and ', array_map(fn (Voice $v): string => $v->display_name.' ('.$v->gender.', '.$v->internal_voice_id.')', $chosen)) ?: 'none found', "\n";
if ($look) {
    foreach ($voices->take(12) as $v) {
        echo '  ', $v->display_name, ' | ', $v->gender, ' | ', $v->internal_voice_id, ' | ', $v->provider, ' | ', $v->provider_voice_id, "\n";
    }
    exit(0);
}
if (! $member || count($chosen) < 2) {
    echo "The check needs the test member and two premium Arabic voices.\n";
    exit(1);
}

$dir = rtrim((string) getenv('DIR'), '/');
$golden = json_decode((string) @file_get_contents($dir.'/golden.json'), true);
if (! is_array($golden) || ! isset($golden['items'])) {
    fwrite(STDERR, "The test set was not found in DIR.\n");
    exit(1);
}
$homographs = $names = [];
foreach ($golden['items'] as $item) {
    if (($item['source'] ?? '') === 'written' && isset($item['listen']['want'], $item['listen']['options'], $item['word']) && ! in_array('known-hard', $item['tags'] ?? [], true)) {
        if (in_array('homograph', $item['tags'] ?? [], true)) {
            $homographs[] = $item;
        } else {
            $names[] = $item;
        }
    }
}
// Ten sentences for each voice (six homographs, four names), and six others for the member's own direction.
$forVoices = array_merge(array_slice($homographs, 0, 6), array_slice($names, 0, 4));
$forStyle = array_merge(array_slice($homographs, 6, 4), array_slice($names, 4, 2));
// "first1": only the first sentence of each set, to see the whole run work before the speech is spent.
foreach ($only as $token) {
    if (preg_match('/^first(\d+)$/', $token, $m)) {
        $forVoices = array_slice($forVoices, 0, (int) $m[1]);
        $forStyle = array_slice($forStyle, 0, (int) $m[1]);
    }
    // "skip1": leave out the first of each set, when a trial has already made it.
    if (preg_match('/^skip(\d+)$/', $token, $m)) {
        $forVoices = array_slice($forVoices, (int) $m[1]);
        $forStyle = array_slice($forStyle, (int) $m[1]);
    }
}
$direction = 'اقرأ بصوت مذيع أخبار هادئ وواثق';
// The third set is a delivery a member chooses: their own direction where the plan lets them write one,
// otherwise a style and a mood from the studio's lists.
$own = (bool) ($plan?->custom_prompt_enabled ?? false);
$sets = [
    ['label' => 'voice 1', 'voice' => $chosen[0], 'items' => $forVoices, 'style' => null, 'performance' => 'neutral', 'emotion' => 'neutral'],
    ['label' => 'voice 2', 'voice' => $chosen[1], 'items' => $forVoices, 'style' => null, 'performance' => 'neutral', 'emotion' => 'neutral'],
    $own
        ? ['label' => 'voice 1, own direction', 'voice' => $chosen[0], 'items' => $forStyle, 'style' => $direction, 'performance' => 'neutral', 'emotion' => 'neutral']
        : ['label' => 'voice 1, commercial style, excited', 'voice' => $chosen[0], 'items' => $forStyle, 'style' => null, 'performance' => 'commercial', 'emotion' => 'excited'],
];

$run = now()->utc()->format('Ymd-His');
$disk = Storage::disk('public');
$key = (string) (app(GeminiKeyManager::class)->getAvailableKey()['key'] ?? '');
$base = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
$listeners = ['gemini-3.8-flash', 'gemini-3.6-flash'];
mt_srand(11);

/** What each listener heard: the reading's id, "x" for none of them, "error" when it did not answer. */
$hear = function (string $bytes, array $item) use ($base, $key, $listeners): array {
    $ids = array_keys($item['listen']['options']);
    shuffle($ids);
    $letters = [];
    $lines = '';
    foreach ($ids as $i => $id) {
        [$marked, $said, $means] = $item['listen']['options'][$id];
        $letters[chr(65 + $i)] = $id;
        $lines .= chr(65 + $i).') '.$said.' — '.$marked.' ('.$means.")\n";
    }
    $sentence = TashkeelScore::strip($item['text']);
    $prompt = "Listen to this Arabic recording. The speaker reads: «{$sentence}»\n"
        .'Focus only on the word written «'.TashkeelScore::strip($item['word']).'». It can be said in more than one way. '
        .'Which way does the speaker actually say it? Judge by the sounds you hear (each vowel, each consonant, and whether a consonant is doubled), '
        ."NOT by which one would make more sense: the speaker may be saying the less likely one. Ignore any ending added to the word.\n"
        .$lines
        ."X) none of these, or it cannot be heard\n"
        .'Return only JSON: {"heard": "<the word as it sounded, in Latin letters>", "choice": "<one letter>"}';
    $body = [
        'contents' => [['role' => 'user', 'parts' => [
            ['inline_data' => ['mime_type' => str_starts_with($bytes, 'RIFF') ? 'audio/wav' : 'audio/mpeg', 'data' => base64_encode($bytes)]],
            ['text' => $prompt],
        ]]],
        'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0],
    ];
    $answers = Http::pool(fn (Pool $pool) => array_map(
        fn (string $model) => $pool->as($model)->timeout(90)->withHeaders(['x-goog-api-key' => $key])->post($base.'/models/'.$model.':generateContent', $body),
        $listeners,
    ));
    $out = [];
    foreach ($listeners as $model) {
        $r = $answers[$model] ?? null;
        $json = $r instanceof Response && $r->ok() ? json_decode((string) $r->json('candidates.0.content.parts.0.text'), true) : null;
        $out[] = is_array($json)
            ? ['id' => $letters[strtoupper(trim((string) ($json['choice'] ?? 'X')))] ?? 'x', 'heard' => mb_substr((string) ($json['heard'] ?? ''), 0, 24)]
            : ['id' => 'error', 'heard' => ''];
    }

    return $out;
};

$seconds = 0.0;
/**
 * One voice made the site's way: a request like the studio's for this member, then the audio job.
 * The switch is set first: off for "today", test accounts with this member for "auto".
 */
$make = function (array $item, array $set, int $n, string $which) use ($memberId, $disk, $run, $hear, &$seconds): array {
    SiteSetting::set(AutoReadings::SETTING, $which === 'auto' ? AutoReadings::TEST : AutoReadings::OFF, 'tts');
    SiteSetting::set(AutoReadings::MEMBERS_SETTING, (string) $memberId, 'tts');
    $voice = $set['voice'];
    $generation = AudioGeneration::create([
        'user_id' => $memberId, 'input_text' => $item['text'], 'source_language' => 'ar', 'target_language' => 'ar',
        'voice_tier' => 'hd', 'voice_name' => $voice->internal_voice_id, 'tts_provider' => $voice->provider,
        'translate' => false, 'status' => 'queued', 'dialect' => 'ar-XA',
        'performance_style' => $set['performance'], 'emotion' => $set['emotion'], 'style_prompt' => $set['style'],
    ]);
    GenerateAudioJob::dispatchSync($generation->id);
    $generation->refresh();
    if ($generation->status !== 'done' || ! $generation->audio_path) {
        throw new RuntimeException('the voice was not made: '.mb_substr((string) $generation->error_message, 0, 140));
    }
    $bytes = (string) $disk->get($generation->audio_path);
    $seconds += AudioDuration::ofStoredFile($generation->audio_path) ?? 0;
    $clip = 's'.$n.'-'.$item['id'].'-'.$which;
    $file = $clip.'.'.pathinfo($generation->audio_path, PATHINFO_EXTENSION);
    $disk->put('tashkeel-listen/'.$run.'/'.$file, $bytes);
    [$a, $b] = $hear($bytes, $item);
    $want = $item['listen']['want'];
    $snapshot = (array) $generation->settings_snapshot;

    return ['clip' => $clip, 'file' => $file, 'a' => $a, 'b' => $b,
        'result' => $a['id'] === $want && $b['id'] === $want ? 'ok' : ($a['id'] !== $want && $b['id'] !== $want ? 'not' : 'split'),
        'chars' => (int) $generation->billable_chars, 'cost' => (float) ($generation->tts_cost_estimate_usd ?? 0), 'kept_text' => $generation->input_text === $item['text'],
        'spoken' => (string) ($snapshot['spoken_text'] ?? ''), 'step' => $snapshot['auto_readings'] ?? null, 'style_used' => $generation->style_prompt];
};

echo 'Listeners: ', implode(' + ', $listeners), '; stops at ', $cap, ' minutes of speech; page: ', $disk->url('tashkeel-listen/'.$run.'/index.html'), "\n";

$rows = [];
$costs = [];
$times = [];
$stopped = false;
foreach ($sets as $n => $set) {
    echo "\n== ", $set['label'], ': ', $set['voice']->display_name, ' (', $set['voice']->gender, ')', $set['style'] ? ', told: '.$set['style'] : '', " ==\n";
    foreach ($set['items'] as $item) {
        if ($seconds > $cap * 60 - 12) {
            $stopped = true;
            break 2;
        }
        $kind = in_array('homograph', $item['tags'] ?? [], true) ? 'homograph' : 'name';
        try {
            $today = $make($item, $set, $n + 1, 'today');
            // The step by itself, metered: what it costs and how long it takes the first time a text is made.
            $spend = GeminiSpend::start();
            try {
                $step = AutoReadings::for($item['text']);
            } finally {
                $spend->stop();
            }
            if (! $step['info']['reused']) {
                $costs[] = ['cost' => $spend->cost(), 'usage' => $spend->summary()];
                $times[] = $step['info']['ms'];
            }
            // How the step marked the sentence's word, when it did.
            $given = '';
            foreach (preg_split('/\s+/u', $step['text']) ?: [] as $token) {
                $token = (string) preg_replace('/[^\p{L}\p{M}]/u', '', $token);
                if ($token !== TashkeelScore::strip($token) && TashkeelScore::strip($token) === TashkeelScore::strip($item['word'])) {
                    $given = $token;
                }
            }
            $auto = $step['info']['words'] === 0 ? ['same' => true] + $today : ['same' => false] + $make($item, $set, $n + 1, 'auto');
        } catch (Throwable $e) {
            echo sprintf("%-4s ERROR %s\n", $item['id'], mb_substr($e->getMessage(), 0, 180));

            continue;
        }
        $rows[] = ['set' => $n, 'item' => $item, 'kind' => $kind, 'today' => $today, 'auto' => $auto, 'words' => $step['info']['words'], 'given' => $given];
        echo sprintf("%-4s %-9s want %-9s today %-5s (%s | %s)   auto %-5s (%s | %s)   %s\n", $item['id'], $kind, $item['listen']['want'],
            $today['result'], $today['a']['id'], $today['b']['id'],
            $auto['same'] ? 'same' : $auto['result'], $auto['a']['id'], $auto['b']['id'],
            $auto['same'] ? 'the step marked nothing' : 'the step marked '.$step['info']['words'].' word(s)'.($given !== '' ? ', this one as '.$given : ', not this one'));
    }
}
// The switch as the owner asked for it on staging: test accounts only.
SiteSetting::set(AutoReadings::SETTING, AutoReadings::TEST, 'tts');
SiteSetting::set(AutoReadings::MEMBERS_SETTING, (string) $memberId, 'tts');
echo $stopped ? "\nStopped: close to ".$cap." minutes of speech.\n" : '', "\nSpeech made: ", round($seconds / 60, 1), ' minutes. The switch is left on: ', AutoReadings::mode(), ' (', implode(', ', AutoReadings::testMembers()), ").\n";

foreach ($sets as $n => $set) {
    $mine = array_values(array_filter($rows, fn (array $r): bool => $r['set'] === $n));
    if ($mine === []) {
        continue;
    }
    $count = fn (string $which, string $result): int => count(array_filter($mine, fn (array $r): bool => $r[$which]['result'] === $result));
    echo "\n", $set['label'], ' (', $set['voice']->display_name, '): ', count($mine), " sentences\n";
    foreach (['today' => 'today', 'auto' => 'automatic'] as $which => $label) {
        echo sprintf("  %-10s both heard the reading meant %2d, both heard another %2d, the listeners disagree %2d\n", $label, $count($which, 'ok'), $count($which, 'not'), $count($which, 'split'));
    }
    $moves = [];
    foreach ($mine as $r) {
        if (! $r['auto']['same'] && $r['today']['result'] !== $r['auto']['result']) {
            $moves[] = $r['item']['id'].' '.$r['today']['result'].' -> '.$r['auto']['result'];
        }
    }
    echo '  The step marked nothing in ', count(array_filter($mine, fn (array $r): bool => $r['auto']['same'])), " sentences.\n  Changed between the two: ", $moves === [] ? 'none' : implode('; ', $moves), "\n";
}

// The switch and the record: what the site kept for each voice.
$autos = array_values(array_filter($rows, fn (array $r): bool => ! $r['auto']['same']));
echo "\nThe site's own record of the ", count($autos), " voices the step changed, and of today's ", count($rows), ":\n";
echo '  today: the step ran on ', count(array_filter($rows, fn (array $r): bool => $r['today']['step'] !== null)), " (the switch was off: should be 0)\n";
echo '  auto: the step ran on ', count(array_filter($autos, fn (array $r): bool => $r['auto']['step'] !== null)), ', reused the kept result on ',
    count(array_filter($autos, fn (array $r): bool => (bool) ($r['auto']['step']['reused'] ?? false))), ', kept the marked text for support on ',
    count(array_filter($autos, fn (array $r): bool => $r['auto']['spoken'] !== '' && $r['auto']['spoken'] !== $r['item']['text'] && TashkeelScore::strip($r['auto']['spoken']) === TashkeelScore::strip($r['item']['text']))), "\n";
echo '  the member\'s text kept as written on ', count(array_filter($autos, fn (array $r): bool => $r['auto']['kept_text'])), '; charged the same as today on ',
    count(array_filter($autos, fn (array $r): bool => $r['auto']['chars'] === $r['today']['chars'])), ' of ', count($autos), " (the characters the member is billed for)\n";
if ($rows !== []) {
    echo sprintf("  what Google billed for a voice, as the site recorded it: today \$%.5f on average, with the step \$%.5f (its kept result reused)\n",
        array_sum(array_map(fn (array $r): float => $r['today']['cost'], $rows)) / count($rows),
        $autos === [] ? 0 : array_sum(array_map(fn (array $r): float => $r['auto']['cost'], $autos)) / count($autos));
}

// What the step costs and how long it takes, the first time a text is made.
if ($costs !== []) {
    $c = array_column($costs, 'cost');
    sort($times);
    echo "\nThe step by itself, first time for a text (", count($costs), " texts): what Google bills\n";
    echo sprintf("  a text: \$%.6f on average, \$%.6f at most; a thousand texts: \$%.2f\n", array_sum($c) / count($c), max($c), 1000 * array_sum($c) / count($c));
    echo '  time: ', $times[(int) floor(0.5 * (count($times) - 1))], ' ms at the middle, ', end($times), " ms at most\n";
    echo '  one text\'s requests and tokens: ', json_encode($costs[0]['usage'], JSON_UNESCAPED_SLASHES), "\n";
}

// For the measurement afterwards: every clip the listeners disagree on, with the same sentence's other clip when the two agree on it.
$splits = [];
foreach ($rows as $r) {
    foreach (['today' => 'auto', 'auto' => 'today'] as $which => $other) {
        if ($r[$which]['result'] !== 'split' || ($which === 'auto' && $r['auto']['same'])) {
            continue;
        }
        $partner = ! $r['auto']['same'] && $r[$other]['result'] !== 'split' ? $r[$other] : null;
        $splits[] = ['clip' => $r[$which]['file'], 'id' => $r[$which]['clip'], 'want' => $r['item']['listen']['want'],
            'heard' => [$r[$which]['a']['id'], $r[$which]['b']['id']],
            'partner' => $partner['file'] ?? null, 'partner_result' => $partner['result'] ?? null];
    }
}
$disk->put('tashkeel-listen/'.$run.'/splits.json', json_encode($splits, JSON_UNESCAPED_UNICODE));
echo "\nClips the listeners disagree on: ", count($splits), ' (', count(array_filter($splits, fn (array $s): bool => $s['partner'] !== null)),
    " have the same sentence's other clip to be measured against). Run id for the measurement: ", $run, "\n";

// The page to check by ear.
$e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
$html = '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="robots" content="noindex"><meta name="viewport" content="width=device-width, initial-scale=1">'
    .'<title>Tashkeel site check '.$e($run).'</title><style>body{font:16px system-ui;margin:16px;background:#111;color:#eee}article{border:1px solid #333;border-radius:12px;padding:12px;margin:0 0 12px}'
    .'h2{font-size:24px;margin:0 0 6px;line-height:1.9}p{margin:4px 0;direction:ltr;text-align:left;color:#bbb}audio{width:100%;margin-top:8px}.split{border-color:#c90}.not{border-color:#c33}b{color:#fff}</style>'
    .'<p>Voices made the site\'s own way on staging, each sentence as today and with the automatic step. Orange: the two listeners disagree. Red: both heard another reading.</p>';
foreach ($rows as $r) {
    $want = $r['item']['listen']['options'][$r['item']['listen']['want']];
    foreach (['today', 'auto'] as $which) {
        if ($which === 'auto' && $r['auto']['same']) {
            continue;
        }
        $c = $r[$which];
        $html .= '<article class="'.$e($c['result']).'"><h2>'.$e($which === 'auto' && $c['spoken'] !== '' ? $c['spoken'] : $r['item']['text']).'</h2>'
            .'<p><b>'.$e($c['clip']).'</b> · '.$e($sets[$r['set']]['label'].': '.$sets[$r['set']]['voice']->display_name).' · '.($which === 'today' ? 'as the site reads it today' : 'with the automatic step').'</p>'
            .'<p>The word «'.$e($r['item']['word']).'» is meant as <b>'.$e($want[1].' — '.$want[0].' ('.$want[2].')').'</b></p>'
            .'<p>Listener 1 heard: '.$e($c['a']['id'].' ('.$c['a']['heard'].')').' · Listener 2 heard: '.$e($c['b']['id'].' ('.$c['b']['heard'].')').'</p>'
            .'<audio controls preload="none" src="'.$e($c['file']).'"></audio></article>';
    }
}
$disk->put('tashkeel-listen/'.$run.'/index.html', $html.'</html>');
