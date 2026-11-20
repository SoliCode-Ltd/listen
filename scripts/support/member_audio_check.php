<?php

/*
 * For the "Server deploy" workflow's member-audio-check mode: how one member's
 * voices got past the wording rule. Read-only (the database session is READ
 * ONLY where the server allows it).
 *
 * Prints the account's state, how many voices it made and by which way in
 * (studio, developer API, extension), what the wording rule refused for it,
 * how many of its texts today's rule would refuse and for which words, and the
 * words it used most (words and counts only, in no order that rebuilds a
 * sentence). It never prints a text.
 *
 *   ssh user@host 'cd ~/public_html && MEMBER=123 php' < scripts/support/member_audio_check.php
 */

use App\Models\AudioGeneration;
use App\Models\BlockedGeneration;
use App\Models\User;
use App\Services\PlanAccessService;
use App\Services\ProfanityFilter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

ini_set('display_errors', 'stderr');

$root = getcwd();
if (! is_file($root.'/artisan')) {
    fwrite(STDERR, "Run this from the Laravel app root.\n");
    exit(1);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, 'The check stopped on an error: '.$e::class.': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().")\nFAILED\n");
    exit(1);
});

try {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
} catch (Throwable) {
    // Not a server that takes it: the script only reads all the same.
}

$id = (int) preg_replace('/\D/', '', (string) getenv('MEMBER'));
$user = $id > 0 ? User::query()->find($id) : null;
if (! $user) {
    fwrite(STDERR, "No member with that id.\n");
    exit(1);
}

$line = fn (string $label, $value) => print '  '.str_pad($label, 44).(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value)."\n";
$plan = app(PlanAccessService::class)->resolveActivePlanForUserId($user->id);

echo "== Member {$user->id} ==\n";
$line('Joined', (string) $user->created_at);
$line('Plan now', $plan ? $plan->slug.' ($'.number_format(((int) $plan->price_cents) / 100, 2).')' : 'none');
$line('Blocked', (bool) $user->is_blocked);
$line('Blocked by', $user->blocked_by ? 'admin '.$user->blocked_by : ($user->is_blocked ? 'the wording rule' : '-'));
$line('Blocked at', (string) ($user->blocked_at ?? '-'));

$voices = AudioGeneration::query()->where('user_id', $user->id);
echo "\n== Voices ==\n";
$line('All', (clone $voices)->count());
foreach ((clone $voices)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status') as $status => $n) {
    $line('  '.$status, $n);
}
$line('By the developer API', (clone $voices)->whereNotNull('api_key_id')->count());
$line('Dialogues', (clone $voices)->where('is_dialogue', true)->count());
$line('With a style prompt', (clone $voices)->whereNotNull('style_prompt')->where('style_prompt', '<>', '')->count());
$line('First / last', (string) (clone $voices)->min('created_at').' / '.(string) (clone $voices)->max('created_at'));
foreach ((clone $voices)->selectRaw('date(created_at) as d, count(*) as n')->groupBy('d')->orderBy('d')->pluck('n', 'd') as $day => $n) {
    $line('  '.$day, $n);
}
foreach ((clone $voices)->selectRaw('target_language as l, count(*) as n')->groupBy('l')->pluck('n', 'l') as $lang => $n) {
    $line('  language '.$lang, $n);
}

$refused = BlockedGeneration::query()->where('user_id', $user->id);
echo "\n== What the wording rule refused for this member ==\n";
$line('Refused texts', (clone $refused)->count());
foreach ((clone $refused)->selectRaw('route_name as r, matched_term as t, count(*) as n')->groupBy('r', 't')->orderByDesc('n')->limit(40)->get() as $row) {
    $line('  '.($row->r ?: 'unknown way in'), '"'.$row->t.'" x'.$row->n);
}

$filter = app(ProfanityFilter::class);
$today = [];
$words = [];
$texts = 0;
$chars = 0;
$fields = ['input_text', 'style_prompt'];
(clone $voices)->orderBy('id')->select(array_merge(['id', 'dialogue_metadata'], $fields))->chunkById(200, function ($chunk) use ($filter, &$today, &$words, &$texts, &$chars, $fields): void {
    foreach ($chunk as $voice) {
        foreach ($fields as $field) {
            $text = (string) $voice->{$field};
            if (trim($text) === '') {
                continue;
            }
            if ($field === 'input_text') {
                $texts++;
                $chars += mb_strlen($text);
            }
            $match = $filter->firstMatch($text);
            if ($match) {
                $key = $field.': "'.$match['term'].'"';
                $today[$key] = ($today[$key] ?? 0) + 1;
            }
            // Words and counts only: lower-cased, marks and digits dropped, at least three letters.
            $plain = mb_strtolower((string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text));
            foreach (preg_split('/[^\p{L}]+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                if (mb_strlen($word) >= 3) {
                    $words[$word] = ($words[$word] ?? 0) + 1;
                }
            }
        }
    }
});

echo "\n== Its texts against today's wording rule ==\n";
$line('Texts read', $texts.' ('.number_format($chars).' characters)');
$line('Would be refused today', array_sum($today));
arsort($today);
foreach (array_slice($today, 0, 30, true) as $what => $n) {
    $line('  '.$what, 'x'.$n);
}

echo "\n== The words it used most (word: times) ==\n";
arsort($words);
$top = array_slice($words, 0, 260, true);
echo wordwrap(implode('  ', array_map(fn ($w, $n) => $w.':'.$n, array_keys($top), $top)), 150, "\n", false)."\n";
