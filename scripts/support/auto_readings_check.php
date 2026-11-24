<?php

/*
 * For the "Server deploy" workflow's readings-check mode, on either site: what
 * the automatic pronunciation step (App\Support\Tashkeel\AutoReadings) did on
 * the voices made lately. Read-only (the database session is READ ONLY where
 * the server allows it).
 *
 * Prints the switch and its test accounts, then for the last DAYS days (7
 * unless given): how many premium Arabic voices were made, on how many the
 * step ran, how many words it marked, whether it reused a kept result, how
 * long it took, what it took each text to be written in, and how many of the
 * texts it took for Fusha use a common colloquial word (the site's own list,
 * ArabicDialects::looksColloquial). That last count is an underestimate of the
 * dialect texts taken for Fusha: the list is short, and a dialect text without
 * one of its words is not caught.
 *
 * Counts and member ids only: a text is read to count it, never printed.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/auto_readings_check.php
 *   ssh user@host 'cd ~/public_html && DAYS=30 php' < scripts/support/auto_readings_check.php
 */

use App\Models\AudioGeneration;
use App\Support\ArabicDialects;
use App\Support\Tashkeel\AutoReadings;
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

if (! class_exists(AutoReadings::class)) {
    echo "The automatic pronunciation step is not on this server.\n";
    exit(1);
}

try {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
} catch (Throwable) {
    // Not a server that takes it: the script only reads all the same.
}

$days = max(1, min(90, (int) (getenv('DAYS') ?: 7)));
$since = now()->subDays($days);
$line = fn (string $label, $value) => print '  '.str_pad($label, 58).$value."\n";
$share = fn (int $part, int $all): string => $all > 0 ? $part.' ('.round(100 * $part / $all).'%)' : (string) $part;

echo "== The switch ==\n";
$line('Automatic pronunciation', ['0' => 'off', 'test' => 'test accounts only', '1' => 'on for everyone'][AutoReadings::mode()]);
$line('Test accounts (member ids)', implode(', ', AutoReadings::testMembers()) ?: 'none');

$premium = 0;
$ran = [];
AudioGeneration::query()
    ->where('created_at', '>=', $since)
    ->where('status', 'done')
    ->where('voice_tier', 'hd')
    ->where('target_language', 'like', 'ar%')
    ->select(['id', 'user_id', 'input_text', 'settings_snapshot'])
    ->orderBy('id')
    ->chunkById(500, function ($voices) use (&$premium, &$ran): void {
        foreach ($voices as $voice) {
            $premium++;
            $info = ($voice->settings_snapshot ?? [])['auto_readings'] ?? null;
            if (is_array($info)) {
                // The text is read only to see whether it uses a common colloquial word.
                $ran[] = $info + ['member' => (int) $voice->user_id, 'colloquial' => ArabicDialects::looksColloquial((string) $voice->input_text)];
            }
        }
    });

$count = fn (callable $test): int => count(array_filter($ran, $test));
$asked = array_values(array_filter($ran, fn (array $r): bool => ! ($r['reused'] ?? false)));
$failed = $count(fn (array $r): bool => ! ($r['reused'] ?? false) && (int) ($r['answers'] ?? 0) < 2);
$marked = $count(fn (array $r): bool => (int) ($r['words'] ?? 0) > 0);
$words = array_sum(array_map(fn (array $r): int => (int) ($r['words'] ?? 0), $ran));
$fusha = array_values(array_filter($ran, fn (array $r): bool => ($r['written_in'] ?? null) === 'fusha'));
$dialect = array_values(array_filter($ran, fn (array $r): bool => ($r['written_in'] ?? null) === 'dialect'));
$fushaColloquial = array_values(array_filter($fusha, fn (array $r): bool => $r['colloquial']));

echo "\n== The last {$days} day(s) ==\n";
$line('Premium Arabic voices made', $premium);
$line('The step ran on', $share(count($ran), $premium));
$line('  marked at least one word', $share($marked, count($ran)));
$line('  marked nothing', $share(count($ran) - $marked - $failed, count($ran)));
$line('  too few answers in time (read as written)', $share($failed, count($ran)));
$line('Words marked in all', $words.($marked > 0 ? ' ('.round($words / $marked, 1).' a voice that had any)' : ''));
$line('Reused a kept result (another take of the same text)', $share(count($ran) - count($asked), count($ran)));

$ms = array_map(fn (array $r): int => (int) ($r['ms'] ?? 0), $asked);
sort($ms);
if ($ms !== []) {
    $at = fn (float $p): string => round($ms[(int) floor($p * (count($ms) - 1))] / 1000, 1).' s';
    $line('Time added when the text model was asked', $at(0.5).' at the middle, '.$at(0.9).' for the slowest tenth, '.$at(1.0).' at most');
}

echo "\n== What the step took the texts to be written in ==\n";
$line('Fusha (full treatment)', $share(count($fusha), count($ran)));
$line('A dialect (names only)', $share(count($dialect), count($ran)));
$line('Not said', $share(count($ran) - count($fusha) - count($dialect), count($ran)));
$line('Taken for Fusha, yet with a common colloquial word', $share(count($fushaColloquial), count($fusha)));
$line('  words marked on those', (string) array_sum(array_map(fn (array $r): int => (int) ($r['words'] ?? 0), $fushaColloquial)));
echo "  (An underestimate of the dialect texts taken for Fusha: the list of colloquial words is short.)\n";

echo "\n== By member (ids only) ==\n";
$byMember = array_count_values(array_column($ran, 'member'));
arsort($byMember);
foreach (array_slice($byMember, 0, 20, true) as $member => $n) {
    $line('Member '.$member, $n.' voice(s)');
}
echo $byMember === [] ? "  none\n" : '';
