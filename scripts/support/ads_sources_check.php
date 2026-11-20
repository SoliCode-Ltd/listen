<?php

/*
 * For the "Server deploy" workflow's ads-sources mode, on either site: where the paying
 * members came from, as the site itself recorded it. Read-only (READ ONLY database
 * session); prints counts and amounts only, never a name, e-mail address or member id.
 *
 * For each window (since ChatGPT Ads began sending on 18 November, the last 7 and the
 * last 30 days):
 *   - every payment the site recorded (card, phone wallet, by hand), renewals included;
 *   - new plans and plan changes (the purchases ChatGPT Ads is told of), and how many of
 *     them the buyer reached from a ChatGPT ad click (its click id with the purchase);
 *   - purchases from a Google Ads click (kept for the Google Ads upload: a new plan's
 *     first payment or a plan change, within 90 days of the click), and free trials
 *     started from a Google click;
 *   - new accounts, for scale;
 *   - where the payments came from: members who paid before (renewals, plan changes),
 *     extra credits, and the first payment of a new paying member, with every trace the
 *     site keeps of how that member found it (a Google or ChatGPT ad click, a referral
 *     link, the phone app, an email from the site) and how they signed up, from where
 *     and how long after signing up they paid;
 *   - which ad and analytics tags the site's pages carry.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/ads_sources_check.php
 */

use App\Models\AdsPurchase;
use App\Models\AppDevice;
use App\Models\BillingTrial;
use App\Models\ChatGptAdsEvent;
use App\Models\NewsletterDelivery;
use App\Models\Payment;
use App\Models\PurchaseFollowUp;
use App\Models\ReferralConversion;
use App\Models\TextItem;
use App\Models\User;
use App\Models\UserReferralProfile;
use App\Support\SeoHelper;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

$line = fn (string $label, $value) => print '  '.str_pad($label, 56).(string) $value."\n";
$sums = function ($rows, string $amount, string $currency): string {
    $by = [];
    foreach ($rows as $row) {
        $code = strtoupper((string) ($row->{$currency} ?: 'USD'));
        $by[$code] = ($by[$code] ?? 0) + (int) $row->{$amount};
    }
    ksort($by);

    return $by === [] ? '-' : implode(', ', array_map(fn ($code, $minor) => number_format($minor / 100, 2).' '.$code, array_keys($by), $by));
};

echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";
$windows = [
    'Since ChatGPT Ads began (18 Nov)' => Carbon::parse('2026-11-18 00:00:00', 'UTC'),
    'Last 7 days' => now()->subDays(7),
    'Last 30 days' => now()->subDays(30),
];

$asDate = static fn ($value): ?Carbon => $value === null || $value === '' ? null : Carbon::parse($value);
$ids = static fn (iterable $values): array => array_fill_keys(collect($values)->filter()->map(fn ($v) => (int) $v)->unique()->values()->all(), true);

// An extra-credits payment: by card or phone wallet ("Listen Extra Credits (30 minutes)"), by
// hand (the same words), or an old character pack ("<pack> (100,000 characters)").
$isExtra = static fn (?string $description): bool => $description !== null
    && (str_starts_with($description, 'Listen Extra Credits') || str_ends_with($description, ' characters)'));

// Every payment of the members who paid in the widest window, oldest first: a member's first
// payment for a plan is what makes them a new paying member.
$widest = collect($windows)->min(fn (Carbon $from) => $from->getTimestamp());
$payers = Payment::query()->where('paid_at', '>=', Carbon::createFromTimestamp($widest))->whereNotNull('user_id')->distinct()->pluck('user_id')->map(fn ($v) => (int) $v)->all();
$firstPlanPayment = [];
$firstPaidAt = [];
foreach (Payment::query()->whereIn('user_id', $payers)->orderBy('paid_at')->orderBy('id')->get(['id', 'user_id', 'paid_at', 'description']) as $payment) {
    $firstPaidAt[(int) $payment->user_id] ??= $payment->paid_at;
    if (! $isExtra($payment->description)) {
        $firstPlanPayment[(int) $payment->user_id] ??= (int) $payment->id;
    }
}

$members = User::query()->whereIn('id', $payers)
    ->get(array_values(array_filter(['id', 'created_at', 'country_code', 'google_id', 'auth_provider', 'ads_click_id', Schema::hasColumn('users', 'apple_id') ? 'apple_id' : null])))
    ->keyBy('id');

// What the site keeps of how each of them found it.
$fromGoogle = $ids(collect()
    ->merge(AdsPurchase::query()->whereIn('user_id', $payers)->pluck('user_id'))
    ->merge($members->filter(fn (User $u) => filled($u->ads_click_id))->keys())
    ->merge(BillingTrial::query()->whereIn('user_id', $payers)->whereNotNull('gclid')->where('gclid', '!=', '')->pluck('user_id')));
$fromChatGpt = $ids(ChatGptAdsEvent::query()->whereIn('user_id', $payers)->get(['user_id', 'payload'])
    ->filter(fn ($event) => is_array($event->payload) && filled($event->payload['oppref'] ?? null))->pluck('user_id'));
$referred = $ids(collect()
    ->merge(Schema::hasTable('user_referral_profiles') ? UserReferralProfile::query()->whereIn('user_id', $payers)->whereNotNull('referred_by_user_id')->pluck('user_id') : [])
    ->merge(Schema::hasTable('referral_conversions') ? ReferralConversion::query()->whereIn('referred_user_id', $payers)->pluck('referred_user_id') : []));
$appDevices = Schema::hasTable('app_devices')
    ? AppDevice::query()->whereIn('user_id', $payers)->get(['user_id', 'platform', 'created_at'])->groupBy(fn ($row) => (int) $row->user_id)
    : collect();
$appVoices = $ids(TextItem::query()->whereIn('user_id', $payers)->where('source_type', 'app')->distinct()->pluck('user_id'));
$newsletters = Schema::hasTable('newsletter_deliveries')
    ? NewsletterDelivery::query()->whereIn('user_id', $payers)->where('is_test', false)->where('status', NewsletterDelivery::STATUS_SENT)->whereNotNull('sent_at')->get(['user_id', 'sent_at'])->groupBy(fn ($row) => (int) $row->user_id)
    : collect();
$followUps = Schema::hasTable('purchase_follow_ups')
    ? PurchaseFollowUp::query()->whereIn('user_id', $payers)->whereNotNull('email_sent_at')->get(['user_id', 'email_sent_at'])->groupBy(fn ($row) => (int) $row->user_id)
    : collect();

/** The account made in the app: the app signed in (and kept its phone) within an hour of it. */
$madeInApp = function (int $userId, ?Carbon $createdAt) use ($appDevices, $asDate): bool {
    if ($createdAt === null) {
        return false;
    }

    return collect($appDevices->get($userId, []))->contains(function ($device) use ($createdAt, $asDate): bool {
        $at = $asDate($device->created_at);

        return $at !== null && $at->between($createdAt->copy()->subMinutes(5), $createdAt->copy()->addHour());
    });
};

echo 'Payments recorded since: ', optional($asDate(Payment::query()->min('paid_at')))->format('Y-m-d') ?? '-', "\n";

foreach ($windows as $title => $from) {
    echo "\n== {$title}: from ".$from->utc()->format('Y-m-d H:i')." UTC ==\n";

    $line('New accounts', number_format(User::query()->where('created_at', '>=', $from)->count()));

    $payments = Payment::query()->where('paid_at', '>=', $from)->get(['amount_minor', 'refunded_minor', 'currency', 'method']);
    $line('Payments recorded (renewals included)', $payments->count().'  ('.$sums($payments, 'amount_minor', 'currency').')');
    $byMethod = $payments->groupBy('method')->map->count()->sortDesc();
    $line('  by way of paying', $byMethod->isEmpty() ? '-' : $byMethod->map(fn ($n, $m) => ($m ?: '?').' '.$n)->implode(', '));

    if (Schema::hasTable('chatgpt_ads_events') || Schema::hasTable((new ChatGptAdsEvent)->getTable())) {
        $events = ChatGptAdsEvent::query()->where('created_at', '>=', $from)->get(['payload', 'source']);
        $fromClick = $events->filter(fn ($e) => is_array($e->payload) && isset($e->payload['oppref']))->count();
        $line('New plans and plan changes (sent to ChatGPT Ads)', $events->count().'  (card '.$events->where('source', 'card')->count().', by hand '.$events->where('source', 'manual')->count().')');
        $line('  of them reached from a ChatGPT ad click', $fromClick);
    }

    $google = AdsPurchase::query()->where('purchased_at', '>=', $from)->get(['value_minor', 'currency', 'source']);
    $line('Purchases from a Google Ads click', $google->count().'  ('.$sums($google, 'value_minor', 'currency').')');
    $line('  card / by hand', $google->where('source', AdsPurchase::SOURCE_CARD)->count().' / '.$google->where('source', AdsPurchase::SOURCE_MANUAL)->count());

    $trials = BillingTrial::query()->where('started_at', '>=', $from);
    $line('Free trials started', (clone $trials)->count());
    $line('  of them from a Google Ads click', (clone $trials)->whereNotNull('gclid')->where('gclid', '!=', '')->count());
    $line('  of them now paid', (clone $trials)->where('status', BillingTrial::STATUS_PAID)->count());

    $line('Members at checkout with a Google click (90 days)', User::query()->where('ads_click_at', '>=', $from)->count());

    // New accounts that used the phone app (made a voice in it, or the app kept their phone).
    $newInApp = collect()
        ->merge(TextItem::query()->join('users', 'users.id', '=', 'text_items.user_id')->where('users.created_at', '>=', $from)->where('text_items.source_type', 'app')->distinct()->pluck('text_items.user_id'))
        ->merge(Schema::hasTable('app_devices') ? AppDevice::query()->join('users', 'users.id', '=', 'app_devices.user_id')->where('users.created_at', '>=', $from)->distinct()->pluck('app_devices.user_id') : [])
        ->map(fn ($v) => (int) $v)->unique()->count();
    $line('New accounts that used the phone app', number_format($newInApp));

    echo "\n  -- Where these payments came from --\n";
    $rows = Payment::query()->where('paid_at', '>=', $from)->get(['id', 'user_id', 'amount_minor', 'currency', 'method', 'description', 'paid_at']);
    $isFirst = fn (Payment $p): bool => $p->user_id !== null && ($firstPlanPayment[(int) $p->user_id] ?? null) === (int) $p->id;
    $extra = $rows->filter(fn (Payment $p) => $isExtra($p->description));
    $first = $rows->filter($isFirst);
    $before = $rows->filter(fn (Payment $p) => $p->user_id !== null && ! $isExtra($p->description) && ! $isFirst($p));
    $unlinked = $rows->filter(fn (Payment $p) => $p->user_id === null && ! $isExtra($p->description));

    $line('Members who had paid before (renewals, plan changes)', $before->count().'  ('.$sums($before, 'amount_minor', 'currency').')');
    $line('  members behind them', $before->pluck('user_id')->unique()->count());
    $line('  of them first paid before this window', $before->pluck('user_id')->unique()->filter(fn ($id) => isset($firstPaidAt[(int) $id]) && $firstPaidAt[(int) $id]->lt($from))->count());
    $line('  of them first came from a Google ad click', $before->pluck('user_id')->unique()->filter(fn ($id) => isset($fromGoogle[(int) $id]))->count());
    $line('Extra credits', $extra->count().'  ('.$sums($extra, 'amount_minor', 'currency').')');
    if ($unlinked->isNotEmpty()) {
        $line('Not linked to a member', $unlinked->count().'  ('.$sums($unlinked, 'amount_minor', 'currency').')');
    }
    $line('First payment of a new paying member', $first->count().'  ('.$sums($first, 'amount_minor', 'currency').')');

    $source = function (Payment $p) use ($fromGoogle, $fromChatGpt, $referred): string {
        $id = (int) $p->user_id;

        return match (true) {
            isset($fromGoogle[$id]) => 'google',
            isset($fromChatGpt[$id]) => 'chatgpt',
            isset($referred[$id]) => 'referral',
            default => 'none',
        };
    };
    $bySource = $first->groupBy($source);
    foreach ([
        'google' => 'from a Google ad click',
        'chatgpt' => 'from a ChatGPT ad click',
        'referral' => "from a member's referral link",
        'none' => 'no ad click or referral recorded',
    ] as $key => $label) {
        $group = $bySource->get($key, collect());
        $line('  '.$label, $group->count().($group->isEmpty() ? '' : '  ('.$sums($group, 'amount_minor', 'currency').')'));
    }

    $untraced = $bySource->get('none', collect());
    if ($untraced->isEmpty()) {
        continue;
    }

    echo "  For the {$untraced->count()} with no ad click or referral recorded:\n";
    $facet = fn (string $label, callable $of) => $line('    '.$label, $untraced->map($of)->countBy()->sortDesc()->map(fn ($n, $k) => $k.' '.$n)->implode(', '));
    $member = fn (Payment $p): ?User => $members->get((int) $p->user_id);

    $facet('signed up with', function (Payment $p) use ($member): string {
        $u = $member($p);

        return match (true) {
            $u === null => 'unknown',
            filled($u->google_id) || $u->auth_provider === 'firebase_google' => 'Google',
            filled($u->apple_id ?? null) => 'Apple',
            $u->auth_provider === 'phone' => 'phone number',
            default => 'email and password',
        };
    });
    $facet('the phone app', function (Payment $p) use ($appDevices, $appVoices): string {
        $id = (int) $p->user_id;
        $platforms = collect($appDevices->get($id, []))->pluck('platform')->unique()->sort()->values();
        if ($platforms->isEmpty()) {
            return isset($appVoices[$id]) ? 'used the app' : 'never used it';
        }

        return $platforms->map(fn ($platform) => $platform === 'ios' ? 'iPhone app' : 'Android app')->implode(' and ');
    });
    $facet('account made in the app', fn (Payment $p) => $madeInApp((int) $p->user_id, $asDate($member($p)?->created_at)) ? 'yes' : 'no or not known');
    $facet('paid after signing up', function (Payment $p) use ($member, $asDate): string {
        $created = $asDate($member($p)?->created_at);
        if ($created === null || $p->paid_at === null) {
            return 'unknown';
        }
        $hours = abs($created->diffInMinutes($p->paid_at)) / 60;

        return match (true) {
            $hours < 24 => 'within a day',
            $hours <= 7 * 24 => '1 to 7 days',
            $hours <= 30 * 24 => '8 to 30 days',
            $hours <= 90 * 24 => '31 to 90 days',
            default => 'over 90 days',
        };
    });
    $facet('an email from the site in the 14 days before', function (Payment $p) use ($newsletters, $followUps, $asDate): string {
        $id = (int) $p->user_id;
        $since = $p->paid_at->copy()->subDays(14);
        $within = fn ($rows, string $column): bool => collect($rows)->contains(function ($row) use ($column, $since, $p, $asDate): bool {
            $at = $asDate($row->{$column});

            return $at !== null && $at->between($since, $p->paid_at);
        });

        return match (true) {
            $within($followUps->get($id, []), 'email_sent_at') => 'a purchase follow-up',
            $within($newsletters->get($id, []), 'sent_at') => 'a newsletter',
            default => 'none',
        };
    });
    $facet('country', fn (Payment $p) => strtoupper((string) ($member($p)?->country_code ?: '?')));
    $facet('paid by', fn (Payment $p) => (string) ($p->method ?: '?'));
}

echo "\n== Ad and analytics tags on the site's pages ==\n";
$idKind = static fn (string $id): string => $id === '' ? 'not set' : (preg_match('/^(G|AW|GT|GTM|DC)-/i', $id, $m) ? 'set ('.strtoupper($m[1]).'-...)' : 'set');
$line('Google Analytics id', $idKind(SeoHelper::analyticsId()));
$line('Google tag id', $idKind(SeoHelper::googleTagId()));
$line('Own code in place of the Google tag', SeoHelper::googleTagHeadCode() !== '' ? 'set' : 'not set');
$line('Facebook pixel code', SeoHelper::facebookPixelScript() !== '' ? 'set' : 'not set');
$pasted = strtolower(implode("\n", [SeoHelper::googleTagHeadCode(), SeoHelper::facebookPixelScript(), SeoHelper::customHeadCode(), SeoHelper::purchaseEventCode()]));
$found = array_keys(array_filter([
    'Google tag' => str_contains($pasted, 'googletagmanager') || str_contains($pasted, 'gtag('),
    'Meta pixel' => str_contains($pasted, 'fbq(') || str_contains($pasted, 'connect.facebook.net'),
    'TikTok pixel' => str_contains($pasted, 'analytics.tiktok.com') || str_contains($pasted, 'ttq.'),
    'Snapchat pixel' => str_contains($pasted, 'snaptr') || str_contains($pasted, 'sc-static.net'),
    'ChatGPT Ads pixel' => str_contains($pasted, 'openai') || str_contains($pasted, 'oaiq'),
    'Microsoft Clarity' => str_contains($pasted, 'clarity.ms'),
    'Microsoft Ads' => str_contains($pasted, 'bat.bing.com'),
    'X pixel' => str_contains($pasted, 'twq(') || str_contains($pasted, 'ads-twitter.com'),
    'LinkedIn tag' => str_contains($pasted, 'licdn.com'),
]));
$line('Tags found in the pasted code', $found === [] ? '-' : implode(', ', $found));

echo "\nOK\n";
