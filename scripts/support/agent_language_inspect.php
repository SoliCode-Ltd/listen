<?php

/*
 * Read-only: in which calls did an agent speak a language it should not (an Arabic agent in
 * Spanish or English, an English agent in Spanish), and how far into the call? For each call of
 * the last AGENT_DAYS days (60 by default): its length, its lines, and every agent line sorted
 * into Arabic, Arabic in Latin letters, English or another language. For a line in another
 * language: where in the call it came (line n of m, about how many seconds in) and its first
 * words (the agent's own words, never the caller's). Also the call's token counts, to see whether
 * the call had grown past the conversation it keeps (listen.agents.history_tokens). On MySQL the
 * session is READ ONLY before any query; nothing is written.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/agent_language_inspect.php
 */

use App\Models\Agent;
use App\Models\AgentConversation;
use App\Support\Agents\AgentCost;
use App\Support\Agents\AgentInstructions;
use App\Support\Agents\Tongue;
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

if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}

$days = max(1, (int) (getenv('AGENT_DAYS') ?: 60));
// The same sorting as the call client's guard (public/js/agent-call.js): this server may not have
// the class yet, so a copy of its rules stands in.
$tongue = class_exists(Tongue::class) ? [Tongue::class, 'of'] : static function (string $t): string {
    $arabic = preg_match_all('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}]/u', $t);
    $latin = preg_match_all('/[A-Za-z\x{00C0}-\x{024F}\x{1E00}-\x{1EFF}]/u', $t);
    $script = preg_match_all('/[\x{0370}-\x{03FF}\x{0400}-\x{04FF}\x{0590}-\x{05FF}\x{0900}-\x{097F}\x{0E00}-\x{0E7F}\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}]/u', $t);
    if ($script >= 4 && $script > $arabic && $script > $latin) {
        return 'other';
    }
    if ($arabic >= $latin) {
        return 'ar';
    }
    if ($latin < 8) {
        return 'short';
    }
    $words = array_flip(preg_split('/[^\p{L}\']+/u', mb_strtolower($t), -1, PREG_SPLIT_NO_EMPTY));
    $eu = count(array_intersect_key($words, array_flip(['que', 'qué', 'los', 'las', 'por', 'para', 'una', 'está', 'están', 'puedo', 'puede', 'usted', 'gracias', 'hola', 'también', 'pero', 'cómo', 'cuándo', 'dónde', 'muy', 'más', 'información', 'ahora', 'momento', 'ayudar', 'ayudarte', 'je', 'vous', 'nous', 'est', 'sont', 'les', 'des', 'une', 'pour', 'avec', 'pas', 'oui', 'merci', 'bonjour', 'très', 'votre', 'você', 'não', 'obrigado', 'obrigada', 'uma', 'sono', 'della', 'grazie', 'ich', 'und', 'ist', 'nicht', 'bitte', 'danke'])));
    $en = count(array_intersect_key($words, array_flip(['the', 'and', 'you', 'your', 'are', 'is', 'would', 'can', 'have', 'with', 'this', 'that', 'what', 'please', 'thank', 'thanks', 'hello', 'our', 'will', 'for', "it's", "i'm", "don't", 'help', 'sure', 'let', 'check'])));
    $eu += preg_match('/[¿¡ñçßãõœ]/u', mb_strtolower($t)) ? 2 : 0;
    $eu += preg_match_all('/[áéíóúàèìòùâêîôûäëïöü]/u', mb_strtolower($t)) >= 2 ? 1 : 0;
    if ($eu >= 2 && $eu > $en) {
        return 'other';
    }

    return $en >= 2 ? 'en' : 'latin';
};

echo "== Agent calls of the last {$days} days: which language each agent line was in".PHP_EOL;
$keep = max(2000, (int) config('listen.agents.history_tokens', 8000));
echo '  conversation kept besides the instructions: '.$keep.' tokens'.PHP_EOL;

$calls = AgentConversation::query()->where('created_at', '>=', now()->subDays($days))
    ->orderBy('id')->get(['id', 'agent_id', 'created_at', 'duration_seconds', 'transcript', 'usage']);
$agents = Agent::query()->whereIn('id', $calls->pluck('agent_id')->unique())->get()->keyBy('id');
$total = ['calls' => 0, 'long' => 0, 'wrong' => 0, 'wrong_long' => 0, 'lines' => 0, 'wrong_lines' => 0];
$byMinute = [];

foreach ($calls as $c) {
    $agent = $agents[$c->agent_id] ?? null;
    if (! $agent) {
        continue;
    }
    $lines = array_values(array_filter((array) ($c->transcript ?? []), fn ($e) => is_array($e) && ($e['kind'] ?? '') !== 'links'));
    $m = count($lines);
    if ($m === 0) {
        continue;
    }
    $seconds = (int) $c->duration_seconds;
    $total['calls']++;
    $long = $seconds >= 170;
    $total['long'] += $long ? 1 : 0;
    $own = $agent->language === 'en' ? 'en' : 'ar';
    $classes = [];
    $wrong = [];
    $callerLast = $own;
    $callerSaid = null;
    foreach ($lines as $i => $e) {
        $said = (string) ($e['heard'] ?? $e['text'] ?? '');
        $k = $tongue($said);
        if (($e['role'] ?? '') === 'caller') {
            // The caller's line: its language as the live transcription heard it, and (after a
            // slash) as it was written again from their voice, when it was. Never their words.
            $live = $tongue((string) ($e['heard'] ?? $e['text'] ?? ''));
            $again = isset($e['heard']) ? $tongue((string) ($e['text'] ?? '')) : null;
            if (in_array($k, ['ar', 'en'], true)) {
                $callerLast = $k;
            }
            $callerSaid = $live.($again !== null ? '/'.$again : '');
            $classes[] = ['ar' => 'c', 'short' => 's', 'latin' => 'l', 'en' => 'e', 'other' => 'x'][$live];

            continue;
        }
        $total['lines']++;
        $classes[] = ['ar' => 'A', 'short' => 'a', 'latin' => 'L', 'en' => 'E', 'other' => 'X'][$k];
        $bad = $k === 'other' || ($k === 'en' && $own === 'ar' && $callerLast !== 'en') || ($k === 'ar' && $own === 'en' && $callerLast !== 'ar');
        if ($bad) {
            $at = $m > 1 ? (int) round($seconds * $i / ($m - 1)) : 0;
            $wrong[] = sprintf('line %d of %d (~%ds in, %s, after a caller line in %s): %s', $i + 1, $m, $at, $k, $callerSaid ?? '-', implode(' ', array_slice(preg_split('/\s+/u', trim($said)), 0, 6)));
            $byMinute[intdiv($at, 60)] = ($byMinute[intdiv($at, 60)] ?? 0) + 1;
            $total['wrong_lines']++;
        }
    }
    $usage = (array) ($c->usage ?? []);
    if ($wrong) {
        $total['wrong']++;
        $total['wrong_long'] += $long ? 1 : 0;
    }
    if ($wrong || $long) {
        $base = AgentCost::tokensIn(AgentInstructions::build($agent));
        printf("  call %d  agent %d (%s, %s)  %s  %ds  %d lines  prompt %s tok, instructions ~%d tok  %s\n",
            $c->id, $agent->id, $agent->language, (string) $agent->dialect, $c->created_at?->format('Y-m-d H:i'), $seconds, $m,
            number_format((int) ($usage['promptTokenCount'] ?? 0)), $base, implode('', $classes));
        foreach ($wrong as $w) {
            echo '      '.$w.PHP_EOL;
        }
    }
}

echo PHP_EOL.'  (agent: A Arabic, a short, L Latin letters, E English, X another language; caller as heard live: c Arabic, s short, l Latin letters, e English, x another language)'.PHP_EOL;
printf("  calls %d, of them 3 minutes or longer %d\n", $total['calls'], $total['long']);
printf("  calls with a line in a wrong language %d (3 minutes or longer: %d)\n", $total['wrong'], $total['wrong_long']);
printf("  agent lines %d, in a wrong language %d\n", $total['lines'], $total['wrong_lines']);
ksort($byMinute);
foreach ($byMinute as $min => $n) {
    printf("  wrong-language lines in minute %d of a call: %d\n", $min + 1, $n);
}
