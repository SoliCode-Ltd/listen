<?php

/*
 * Read-only: how a member's credits for their current period were used, voice by
 * voice: what each request was estimated at (and so allowed against what was left),
 * what its real audio was charged, and the running total, so a total over the plan's
 * limit shows which voice took it there and why. Prints member ids and numbers only:
 * no text a member wrote, no email but a masked one.
 *
 * The member is named by their id (MEMBER_ID). On MySQL the session is READ ONLY
 * before any query.
 *
 *   ssh user@host 'cd ~/public_html && MEMBER_ID=5369 php' < scripts/support/credit_use_inspect.php
 */

use App\Models\AudioGeneration;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Billing\MemberUsageMeter;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use App\Support\Credits;
use App\Support\PlanUsageGuard;
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

$id = (int) getenv('MEMBER_ID');
$user = $id > 0 ? User::query()->find($id) : null;
if (! $user) {
    fwrite(STDERR, "MEMBER_ID must be the id of a member.\n");
    exit(1);
}

$line = static function (string $label, $value): void {
    echo '  '.str_pad($label, 44).(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value).PHP_EOL;
};
$mask = static function (?string $email): string {
    $email = (string) $email;
    $at = strpos($email, '@');

    return $at === false ? '***' : substr($email, 0, min(3, $at)).'***'.substr($email, $at);
};
$n = static fn (int $value): string => number_format($value);

$access = app(PlanAccessService::class);
$active = $access->resolveActivePlanForUserId((int) $user->id);
$quota = $access->resolveQuotaPlanForUserId((int) $user->id, $active);
$period = CreditPeriod::current((int) $user->id);
$counter = UsageCounter::query()->where('user_id', $user->id)->where('period_ym', $period['key'])->first();
$limit = PlanUsageGuard::charsLimit($quota, $counter);
$used = (int) ($counter->total_chars ?? 0);

echo "== Member\n";
$line('Member', $user->id.' '.$mask($user->email).', joined '.$user->created_at?->toDateString());
$line('Plan (credits from)', ($active?->slug ?? '-').' ('.($quota?->slug ?? '-').')');
$line('Plan credits a period', $n(PlanUsageGuard::planChars($quota)).' = '.$n(Credits::minutesFor(PlanUsageGuard::planChars($quota))).' min');
$line('Credits given for this period', $n(PlanUsageGuard::giftChars($counter)));
$line('Limit this period', $n($limit));
$line('Over the limit allowed (overage)', PlanUsageGuard::allowsOverage($quota));
$line('Credits a second of audio', Credits::forSeconds(1));

echo "== Credit period\n";
$line('Period', $period['key'].': '.$period['starts_at']->toDateTimeString().' to '.$period['ends_at']->toDateTimeString().' UTC');
$line('Used / limit', $n($used).' / '.$n($limit).($used > $limit ? ' ('.$n($used - $limit).' over = '.round(($used - $limit) / max(1, Credits::forSeconds(1)), 1).' s of audio)' : ''));
$line('Seconds / requests counted', $n((int) ($counter->total_seconds ?? 0)).' s / '.$n((int) ($counter->total_requests ?? 0)));
$line('Counter last changed (UTC)', $counter?->updated_at?->toDateTimeString() ?? '-');

echo "== Plan rows (newest first)\n";
Subscription::query()->where('user_id', $user->id)->latest('id')->limit(4)->with('plan')->get()
    ->each(function (Subscription $row) use ($line): void {
        $line('#'.$row->id.' '.($row->plan?->slug ?? '-').' '.$row->status,
            ($row->current_period_start?->toDateTimeString() ?? '-').' to '.($row->current_period_end?->toDateTimeString() ?? '-')
            .' state='.((string) $row->billing_payment_state ?: '-'));
    });

echo "== Voices charged to this period, in the order they were asked for\n";
echo "  id      asked (UTC)          status      from  estimate  charged  seconds  total after  left before  note\n";
$generations = AudioGeneration::query()
    ->where('user_id', $user->id)
    ->where('created_at', '>=', $period['starts_at']->copy()->subDays(2))
    ->orderBy('id')
    ->get()
    ->filter(fn (AudioGeneration $g): bool => CreditPeriod::ofGeneration($g) === $period['key']);

$total = 0;
$charged = 0;
$crossed = null;
foreach ($generations as $g) {
    $reservation = MemberUsageMeter::reservationOf($g);
    $estimate = (int) $reservation['credits'];
    $done = (string) $g->status === 'done';
    $pack = (bool) $g->billed_to_pack;
    $cost = $done ? Credits::forSeconds((int) $g->duration_seconds) : 0;
    $leftBefore = $limit - $total;
    $note = [];
    if ($pack) {
        $note[] = 'paid from a pack';
    } elseif ($done) {
        $total += $cost;
        $charged += $cost;
        if ($estimate <= $leftBefore && $cost > $leftBefore) {
            $note[] = 'estimate fitted, real audio longer: over by '.$n($cost - $leftBefore);
            $crossed ??= $g->id;
        } elseif ($total > $limit && $total - $cost <= $limit) {
            $note[] = 'took the total over';
            $crossed ??= $g->id;
        } elseif ($estimate > $leftBefore) {
            $note[] = 'ASKED FOR MORE THAN WAS LEFT';
        }
        if (! $g->duration_measured) {
            $note[] = 'length not measured';
        }
    } else {
        $note[] = 'not charged ('.$g->status.')';
    }
    printf("  %-7d %-20s %-11s %-5s %8s %8s %8s %12s %12s  %s\n",
        $g->id, $g->created_at?->toDateTimeString(), (string) $g->status, $g->api_key_id ? 'api' : 'site',
        $n($estimate), $done ? $n($cost) : '-', $done ? $n((int) $g->duration_seconds) : '-', $n($total), $n($leftBefore), implode('; ', $note));
}

echo "== Sums\n";
$line('Voices in the period / done', $generations->count().' / '.$generations->where('status', 'done')->count());
$line('Charged for done voices (not packs)', $n($charged));
$line('Counted on the member (usage counter)', $n($used));
$line('Difference (other changes to the counter)', $n($used - $charged));
$line('Voice that took the total over', $crossed !== null ? '#'.$crossed : 'none');
