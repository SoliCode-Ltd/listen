<?php

/*
 * For the "Server deploy" workflow's tashkeel-sample mode, on either site: a
 * sample of the Arabic sentences members really make voices of, for the
 * tashkeel (vowel marks) test set the owner reviews. Owner-approved
 * (2026-11-23): read only, anonymized, 300 sentences.
 *
 * In a READ ONLY database session it reads the finished Arabic voices of the
 * last 120 days and prints:
 *  - totals with no text: how many voices each dialect group has, how many
 *    texts already carry vowel marks, digits or English words;
 *  - single sentences, never a whole text, with no member, voice or date:
 *    5 to 24 words, at most two from one member, each one once, shuffled.
 *    A sentence with an email, a link, a handle, a long number (a phone, a
 *    card, an account) or a word about passwords and accounts is left out.
 * Admin accounts and test addresses are left out. Writes nothing. The run's
 * log is deleted once the sentences are read.
 *
 *   ssh user@host 'cd ~/public_html && COUNT=300 php' < scripts/support/tashkeel_sample.php
 */

use App\Support\ArabicDialects;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}
mt_srand(20261123);
$count = max(20, min(400, (int) (getenv('COUNT') ?: 300)));
$marks = '\x{064B}-\x{065F}\x{0670}';

// The owner's order: Fusha first, then Egyptian, Gulf, Levantine.
$group = function (?string $dialect): string {
    $region = (string) (ArabicDialects::ALL[trim((string) $dialect)]['region'] ?? '');

    return match (true) {
        $region === 'fusha', trim((string) $dialect) === '' => 'fusha',
        $region === 'nile' => 'egyptian',
        $region === 'gulf' => 'gulf',
        $region === 'levant' => 'levantine',
        default => 'other',
    };
};
$share = ['fusha' => 0.40, 'egyptian' => 0.25, 'gulf' => 0.20, 'levantine' => 0.15];

$staff = DB::table('users')->where('is_admin', true)->orWhere('email', 'like', '%@example.com')->orWhere('email', 'like', '%@example.test')->pluck('id')->all();
$rows = DB::table('audio_generations')
    ->where('status', 'done')
    ->where('target_language', 'ar')
    ->where('created_at', '>=', now()->subDays(120))
    ->whereNotIn('user_id', $staff ?: [0])
    ->orderByDesc('id')
    ->limit(12000)
    ->get(['user_id', 'input_text', 'dialect']);

$totals = ['voices' => 0, 'with_marks' => 0, 'with_digits' => 0, 'with_english' => 0, 'written_colloquial' => 0, 'by_group' => []];
$pool = [];
$seen = [];
foreach ($rows as $row) {
    $text = (string) $row->input_text;
    $g = $group($row->dialect);
    $totals['voices']++;
    $totals['by_group'][$g] = ($totals['by_group'][$g] ?? 0) + 1;
    $totals['with_marks'] += preg_match('/['.$marks.']/u', $text);
    $totals['with_digits'] += preg_match('/[0-9٠-٩]/u', $text);
    $totals['with_english'] += preg_match('/[A-Za-z]{2,}/', $text);
    $totals['written_colloquial'] += ArabicDialects::looksColloquial($text) ? 1 : 0;
    if (! isset($share[$g])) {
        continue;
    }
    // Sound and delivery tags are directions, not words.
    $plain = (string) preg_replace('/<[^<>\n]{1,40}>|\[[^\[\]\n]{1,40}\]/u', ' ', $text);
    foreach (preg_split('/(?<=[.!?؟…])\s+|\R+/u', $plain) ?: [] as $sentence) {
        $sentence = trim((string) preg_replace('/\s+/u', ' ', $sentence));
        $words = preg_match_all('/\p{Arabic}+/u', $sentence);
        if ($words < 5 || $words > 24 || mb_strlen($sentence) > 220) {
            continue;
        }
        // Mostly Arabic, and nothing that could name or reach a person.
        if (preg_match_all('/\p{Arabic}/u', $sentence) < 0.6 * mb_strlen((string) preg_replace('/\s/u', '', $sentence))
            || preg_match('/@|https?:|www\.|\.com|[0-9٠-٩][0-9٠-٩\s\-]{6,}[0-9٠-٩]|كلمة السر|كلمة المرور|باسورد|رقم الحساب|رقم البطاقة|آيبان|ايبان|IBAN|password/iu', $sentence)) {
            continue;
        }
        $key = (string) preg_replace('/[^\p{Arabic}]|['.$marks.']/u', '', $sentence);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $pool[$g][] = [
            'u' => (int) $row->user_id,
            'g' => $g,
            'c' => ArabicDialects::looksColloquial($sentence) ? 1 : 0,
            'f' => (preg_match('/[0-9٠-٩]/u', $sentence) ? 'n' : '').(preg_match('/[A-Za-z]{2,}/', $sentence) ? 'e' : '').(preg_match('/['.$marks.']/u', $sentence) ? 'm' : ''),
            't' => $sentence,
        ];
    }
}

echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";
echo 'TOTALS ', json_encode($totals + ['sentences_to_choose_from' => array_map('count', $pool)], JSON_UNESCAPED_UNICODE), "\n";

$picked = [];
foreach ($share as $g => $part) {
    $want = (int) round($count * $part);
    $list = $pool[$g] ?? [];
    shuffle($list);
    // A third of each group from sentences with numbers or English words: they are the hard ones to read aloud.
    usort($list, fn (array $a, array $b): int => (int) ($b['f'] !== '' && $b['f'] !== 'm') <=> (int) ($a['f'] !== '' && $a['f'] !== 'm'));
    $hard = 0;
    $perMember = [];
    $rest = [];
    foreach ($list as $s) {
        $isHard = $s['f'] !== '' && $s['f'] !== 'm';
        if ($isHard && $hard >= (int) ceil($want / 3)) {
            $rest[] = $s;

            continue;
        }
        if (($perMember[$s['u']] ?? 0) >= 2 || count(array_filter($picked, fn ($p) => $p['g'] === $g)) >= $want) {
            continue;
        }
        $perMember[$s['u']] = ($perMember[$s['u']] ?? 0) + 1;
        $hard += $isHard ? 1 : 0;
        $picked[] = $s;
    }
}
shuffle($picked);
foreach ($picked as $i => $s) {
    echo 'S ', json_encode(['n' => $i + 1, 'g' => $s['g'], 'c' => $s['c'], 'f' => $s['f'], 't' => $s['t']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}
echo count($picked), " sentence(s).\n";
