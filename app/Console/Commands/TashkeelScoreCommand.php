<?php

namespace App\Console\Commands;

use App\Support\Tashkeel\TashkeelScore;
use Illuminate\Console\Command;

/**
 * Scores vowel marks (tashkeel) against the locked test set, the same way
 * after every change: what share of words are said wrong (a mark inside the
 * word) and what share only miss their ending, in all and for each kind of
 * sentence (Fusha, Egyptian, a homograph, a number, an English word ...).
 * Makes no request and no audio: it only compares texts.
 *
 *   php artisan tashkeel:score --candidates=out.json          {"id": "marked text", ...}
 *   php artisan tashkeel:score --system=input                 the sentences as members wrote them
 *   php artisan tashkeel:score --candidates=out.json --save=v2 --against=v1
 *
 * --against fails (exit 1) when any kind of sentence has more words said
 * wrong than in the saved run, so a fix for one kind cannot quietly break
 * another.
 */
class TashkeelScoreCommand extends Command
{
    protected $signature = 'tashkeel:score
        {--golden=tests/tashkeel/golden.json : The test set}
        {--candidates= : A JSON file of marked texts by sentence id}
        {--system= : "input" scores each sentence as it was written}
        {--all : Count sentences the owner has not approved yet}
        {--save= : Keep this run under this name (tests/tashkeel/results)}
        {--against= : A kept run to compare with; worse in any kind fails}
        {--show=15 : How many wrong words to list}';

    protected $description = 'Score vowel marks against the locked test set, by kind of sentence.';

    public function handle(): int
    {
        $golden = json_decode((string) @file_get_contents(base_path((string) $this->option('golden'))), true);
        if (! is_array($golden) || ! isset($golden['items'])) {
            $this->error('The test set could not be read: '.$this->option('golden'));

            return self::FAILURE;
        }
        $candidates = null;
        if ($this->option('candidates')) {
            $candidates = json_decode((string) @file_get_contents((string) $this->option('candidates')), true);
            if (! is_array($candidates)) {
                $this->error('The candidates file could not be read.');

                return self::FAILURE;
            }
        } elseif ($this->option('system') !== 'input') {
            $this->error('Give --candidates=file.json or --system=input.');

            return self::FAILURE;
        }

        $rows = [];
        $misses = [];
        $changed = [];
        $missing = 0;
        foreach ($golden['items'] as $item) {
            if (empty($item['approved']) && ! $this->option('all')) {
                continue;
            }
            $candidate = $candidates === null ? (string) $item['text'] : ($candidates[$item['id']] ?? null);
            if ($candidate === null) {
                $missing++;
                $candidate = TashkeelScore::strip((string) $item['text']);
            }
            $score = TashkeelScore::compare((string) $item['ref'], (string) $candidate);
            if ($score['letters_changed']) {
                $changed[] = $item['id'];
            }
            foreach (array_merge(['all', (string) ($item['variety'] ?? 'fusha')], (array) ($item['tags'] ?? [])) as $kind) {
                foreach (['words', 'letters', 'inside_words', 'ending_words', 'wrong_marks', 'wrong_marks_inside', 'extra_marks'] as $field) {
                    $rows[$kind][$field] = ($rows[$kind][$field] ?? 0) + $score[$field];
                }
                $rows[$kind]['sentences'] = ($rows[$kind]['sentences'] ?? 0) + 1;
            }
            foreach ($score['misses'] as $miss) {
                $misses[] = [$item['id'], $miss['kind'], $miss['word'], $miss['got']];
            }
        }
        if ($rows === []) {
            $this->warn('No approved sentences yet (use --all to count the drafts).');

            return self::SUCCESS;
        }

        $summary = [];
        foreach ($rows as $kind => $r) {
            $summary[$kind] = [
                'sentences' => $r['sentences'],
                'words' => $r['words'],
                // Words said wrong: a mark inside the word differs.
                'inside' => round(100 * $r['inside_words'] / max(1, $r['words']), 2),
                // Words whose ending alone differs.
                'ending' => round(100 * $r['ending_words'] / max(1, $r['words']), 2),
                // Marks wrong, of all the marks the reference writes; and leaving the endings out.
                'marks' => round(100 * $r['wrong_marks'] / max(1, $r['letters']), 2),
                'marks_inside' => round(100 * $r['wrong_marks_inside'] / max(1, $r['letters']), 2),
                'extra' => $r['extra_marks'],
            ];
        }
        $old = null;
        if ($this->option('against')) {
            $old = json_decode((string) @file_get_contents(base_path('tests/tashkeel/results/'.$this->option('against').'.json')), true)['summary'] ?? null;
            if ($old === null) {
                $this->error('No kept run named '.$this->option('against').'.');

                return self::FAILURE;
            }
        }

        $worse = [];
        $this->table(['Kind', 'Sentences', 'Words', 'Said wrong %', 'Ending only %', 'Marks wrong %', 'Extra marks'] + ($old ? [7 => 'Said wrong, before'] : []),
            collect($summary)->map(function (array $s, string $kind) use ($old, &$worse): array {
                $row = [$kind, $s['sentences'], $s['words'], $s['inside'], $s['ending'], $s['marks'], $s['extra']];
                if ($old) {
                    $before = $old[$kind]['inside'] ?? null;
                    $row[] = $before === null ? '-' : $before.($s['inside'] > $before + 0.005 ? '  << worse' : '');
                    if ($before !== null && $s['inside'] > $before + 0.005) {
                        $worse[] = $kind;
                    }
                }

                return $row;
            })->values()->all());
        if ($missing > 0) {
            $this->warn($missing.' sentence(s) had no candidate and were scored as unmarked.');
        }
        if ($changed !== []) {
            $this->warn('Letters changed (not only marks) in: '.implode(', ', array_slice($changed, 0, 20)));
        }
        foreach (array_slice($misses, 0, (int) $this->option('show')) as [$id, $kind, $word, $got]) {
            $this->line(sprintf('  %-8s %-7s %s  ←  %s', $id, $kind, $word, $got === '' ? '(none)' : $got));
        }
        if ($this->option('save')) {
            @mkdir(base_path('tests/tashkeel/results'), 0755, true);
            file_put_contents(base_path('tests/tashkeel/results/'.$this->option('save').'.json'),
                json_encode(['saved_at' => now()->toIso8601String(), 'golden_version' => $golden['version'] ?? null, 'summary' => $summary, 'letters_changed' => $changed], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
            $this->line('Kept as '.$this->option('save').'.');
        }
        if ($worse !== []) {
            $this->error('Worse than '.$this->option('against').' in: '.implode(', ', $worse));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
