<?php

/*
 * For the "Server deploy" workflow's plan-lines modes: takes the "Priority
 * Queue" / "Processing priority" / "أولوية المعالجة" line out of each plan's
 * own pricing-card lines (Admin → Plans), in English and Arabic. The site no
 * longer promises faster processing on every plan. plan-lines is a dry run
 * (READ ONLY database session) that prints what would go; plan-lines-apply
 * (APPLY=1) takes it out. Both print each plan's lines before, as JSON, so
 * any of them can be put back by hand. Changes only those plan lines; prints
 * no member data.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/plan_lines.php
 *   ssh user@host 'cd ~/public_html && APPLY=1 php' < scripts/support/plan_lines.php
 */

use App\Models\Plan;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$apply = getenv('APPLY') === '1';
if (! $apply && in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";
echo $apply ? "Taking the lines out.\n" : "Dry run: nothing changes (plan-lines-apply takes them out).\n";

$promisesSpeed = fn (array $line): bool => (bool) preg_match('/^\s*(priority queue|processing priority|أولوية المعالجة|قائمة الأولوية)\s*$/iu', (string) ($line['label'] ?? ''));
$changed = 0;
foreach (Plan::query()->orderBy('kind')->orderBy('price_cents')->get() as $plan) {
    $updates = [];
    foreach (['features_en', 'features_ar'] as $column) {
        $lines = (array) ($plan->{$column} ?? []);
        $kept = array_values(array_filter($lines, fn ($line) => ! (is_array($line) && $promisesSpeed($line))));
        if (count($kept) === count($lines)) {
            continue;
        }
        echo "\n{$plan->slug} {$column}, before: ", json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
        foreach (array_filter($lines, fn ($line) => is_array($line) && $promisesSpeed($line)) as $line) {
            printf("  %s: %s — %s\n", $apply ? 'taken out' : 'would go', (string) ($line['label'] ?? ''), (string) ($line['value'] ?? ''));
        }
        $updates[$column] = $kept;
    }
    if ($updates !== [] && $apply) {
        $plan->forceFill($updates)->save();
        $changed++;
    }
}
// The pricing page reads the plans as they are (no cache): nothing else to do.
echo $apply ? "\n{$changed} plan(s) changed.\n" : '';
