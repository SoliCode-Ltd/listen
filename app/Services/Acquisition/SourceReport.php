<?php

namespace App\Services\Acquisition;

use App\Models\AdsPurchase;
use App\Models\BillingTrial;
use App\Models\ChatGptAdsEvent;
use App\Models\MemberSource;
use App\Models\UserReferralProfile;
use App\Support\Acquisition\Channels;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin → Where members come from.
 *
 * - The members who signed up in a period, grouped by how they found the site (MemberSource), with how
 *   many of them have paid, how many paid within a day of signing up, and everything they have paid
 *   so far (refunds taken off), by currency.
 * - The payments made in the period, renewals included, grouped by how each payer found the site.
 *
 * Members who joined before this was recorded have no MemberSource: they are grouped by what the site
 * kept then (a Google ad click seen at checkout, a ChatGPT ad click, an invite link), else "Not recorded".
 * Admins are left out.
 */
final class SourceReport
{
    public const COUNT_LAST = 'last';

    public const COUNT_FIRST = 'first';

    /** How many rows the campaign, page, site and country lists show. */
    public const TOP = 15;

    private const CHUNK = 2000;

    private const BEFORE = 'Joined before this was recorded';

    private const NOTHING_KEPT = 'Nothing could be kept';

    /** @var array<string, bool>|null which of the older traces' tables exist */
    private ?array $tables = null;

    /**
     * @return array{
     *     from: Carbon, to: Carbon, count: string, signed_up_in: string, recorded_since: ?Carbon,
     *     totals: array<string, mixed>, channels: list<array<string, mixed>>, campaigns: list<array<string, mixed>>,
     *     landings: list<array<string, mixed>>, sites: list<array<string, mixed>>, countries: list<array<string, mixed>>,
     *     signed_up: list<array<string, mixed>>, payments: array<string, mixed>, latest: list<array<string, mixed>>
     * }
     */
    public function build(Carbon $from, Carbon $to, string $count = self::COUNT_LAST, string $signedUpIn = 'all'): array
    {
        $first = $count === self::COUNT_FIRST;
        $totals = self::bucket('All new members');
        $channels = $campaigns = $landings = $sites = $countries = $signedUp = [];

        $this->members($from, $to, $signedUpIn)->chunkById(self::CHUNK, function (Collection $rows) use (
            $first, &$totals, &$channels, &$campaigns, &$landings, &$sites, &$countries, &$signedUp
        ): void {
            $money = $this->moneyByMember($rows->pluck('id')->all());
            $older = $this->olderTraces($rows->whereNull('source_id'));

            foreach ($rows as $row) {
                $place = $this->place($row, $first, $older);
                $metrics = self::metrics($row->created_at, $money[(int) $row->id] ?? null);

                self::add($totals, $metrics);

                $channel = $place['channel'];
                $channels[$channel] ??= self::bucket(Channels::label($channel)) + ['key' => $channel, 'details' => []];
                self::add($channels[$channel], $metrics);
                $detail = $place['detail'] ?? Channels::label($channel);
                $channels[$channel]['details'][$detail] ??= self::bucket($detail);
                self::add($channels[$channel]['details'][$detail], $metrics);

                if ($place['campaign'] !== null) {
                    $key = $channel."\n".$place['campaign'];
                    $campaigns[$key] ??= self::bucket($place['campaign']) + ['channel' => Channels::label($channel)];
                    self::add($campaigns[$key], $metrics);
                }
                if ($place['landing'] !== null) {
                    $landings[$place['landing']] ??= self::bucket($place['landing']);
                    self::add($landings[$place['landing']], $metrics);
                }
                if ($place['site'] !== null) {
                    $sites[$place['site']] ??= self::bucket($place['site']) + ['channel' => Channels::label($channel)];
                    self::add($sites[$place['site']], $metrics);
                }

                $country = strtoupper((string) ($row->country_code ?: '')) ?: '?';
                $countries[$country] ??= self::bucket($country);
                self::add($countries[$country], $metrics);

                $where = $row->source_id !== null ? (MemberSource::SIGNED_UP_LABELS[$row->signed_up_in] ?? 'Website') : self::BEFORE;
                $signedUp[$where] ??= self::bucket($where);
                self::add($signedUp[$where], $metrics);
            }
        }, 'u.id', 'id');

        $channels = array_values(array_map(function (array $channel): array {
            $channel['details'] = self::ranked($channel['details']);

            return $channel;
        }, $channels));
        usort($channels, fn (array $a, array $b) => [$b['members'], $b['paid']] <=> [$a['members'], $a['paid']]);

        return [
            'from' => $from,
            'to' => $to,
            'count' => $first ? self::COUNT_FIRST : self::COUNT_LAST,
            'signed_up_in' => $signedUpIn,
            'recorded_since' => self::recordedSince(),
            'totals' => $totals,
            'channels' => $channels,
            'campaigns' => array_slice(self::ranked($campaigns), 0, self::TOP),
            'landings' => array_slice(self::ranked($landings), 0, self::TOP),
            'sites' => array_slice(self::ranked($sites), 0, self::TOP),
            'countries' => array_slice(self::ranked($countries), 0, self::TOP),
            'signed_up' => self::ranked($signedUp),
            'payments' => $this->payments($from, $to, $first, $signedUpIn),
            'latest' => $this->latest($from, $to, $first, $signedUpIn),
        ];
    }

    /** When the first member's source was kept, or null before anything was. */
    public static function recordedSince(): ?Carbon
    {
        if (! MemberSource::ready()) {
            return null;
        }
        $at = MemberSource::query()->min('created_at');

        return $at !== null ? Carbon::parse($at) : null;
    }

    /** @param  array<string, int>  $money  minor units by currency */
    public static function money(array $money): string
    {
        $money = array_filter($money, fn (int $minor) => $minor !== 0);
        if ($money === []) {
            return '–';
        }
        ksort($money);

        return implode(' · ', array_map(fn (string $currency, int $minor) => $currency.' '.number_format($minor / self::divisor($currency), 2), array_keys($money), $money));
    }

    /**
     * The same amounts, one currency to a line.
     *
     * @param  array<string, int>  $money
     * @return list<string>
     */
    public static function moneyLines(array $money): array
    {
        $text = self::money($money);

        return $text === '–' ? ['–'] : explode(' · ', $text);
    }

    public static function percent(int $part, int $whole): string
    {
        if ($whole <= 0) {
            return '–';
        }
        $share = $part / $whole * 100;

        return number_format($share, $share > 0 && $share < 10 ? 1 : 0).'%';
    }

    private function members(Carbon $from, Carbon $to, string $signedUpIn): Builder
    {
        return DB::table('users as u')
            ->leftJoin('member_sources as ms', 'ms.user_id', '=', 'u.id')
            ->where('u.created_at', '>=', $from)
            ->where('u.created_at', '<', $to)
            ->where(fn ($q) => $q->where('u.is_admin', false)->orWhereNull('u.is_admin'))
            ->when($signedUpIn !== 'all', fn ($q) => $q->where('ms.signed_up_in', $signedUpIn))
            ->select([
                'u.id', 'u.created_at', 'u.country_code', 'u.ads_click_id',
                'ms.id as source_id', 'ms.signed_up_in', 'ms.channel', 'ms.detail', 'ms.campaign', 'ms.landing_path', 'ms.referrer_host',
                'ms.first_channel', 'ms.first_detail', 'ms.first_campaign', 'ms.first_landing_path', 'ms.first_referrer_host',
            ]);
    }

    /**
     * Everything each member has paid (refunds taken off), by currency, and when they first paid.
     *
     * @param  list<int|string>  $ids
     * @return array<int, array{money: array<string, int>, first_paid_at: ?string}>
     */
    private function moneyByMember(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $paid = [];
        foreach (DB::table('payments')->whereIn('user_id', $ids)
            ->selectRaw('user_id, currency, SUM(amount_minor) as amount, SUM(refunded_minor) as refunded, MIN(paid_at) as first_paid_at')
            ->groupBy('user_id', 'currency')
            ->get() as $row) {
            $id = (int) $row->user_id;
            $currency = strtoupper((string) ($row->currency ?: 'USD'));
            $paid[$id]['money'][$currency] = ($paid[$id]['money'][$currency] ?? 0) + max(0, (int) $row->amount - (int) $row->refunded);
            $earlier = $paid[$id]['first_paid_at'] ?? null;
            $paid[$id]['first_paid_at'] = $earlier === null || (string) $row->first_paid_at < $earlier ? (string) $row->first_paid_at : $earlier;
        }

        return $paid;
    }

    /**
     * For members without a MemberSource (they joined before it was kept): what the site kept of an ad
     * or invite then.
     *
     * @return array{google: array<int, true>, chatgpt: array<int, true>, invite: array<int, true>}
     */
    private function olderTraces(Collection $rows): array
    {
        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $traces = ['google' => [], 'chatgpt' => [], 'invite' => []];
        if ($ids === []) {
            return $traces;
        }
        $tables = $this->tables ??= [
            'ads_purchases' => Schema::hasTable('ads_purchases'),
            'billing_trials' => Schema::hasTable('billing_trials'),
            'chatgpt_ads_events' => Schema::hasTable('chatgpt_ads_events'),
            'user_referral_profiles' => Schema::hasTable('user_referral_profiles'),
        ];
        $set = fn (iterable $values): array => array_fill_keys(collect($values)->map(fn ($v) => (int) $v)->unique()->values()->all(), true);

        $traces['google'] = $set(collect()
            ->merge($rows->filter(fn ($row) => trim((string) $row->ads_click_id) !== '')->pluck('id'))
            ->merge($tables['ads_purchases'] ? AdsPurchase::query()->whereIn('user_id', $ids)->pluck('user_id') : [])
            ->merge($tables['billing_trials'] ? BillingTrial::query()->whereIn('user_id', $ids)->whereNotNull('gclid')->where('gclid', '!=', '')->pluck('user_id') : []));
        if ($tables['chatgpt_ads_events']) {
            $traces['chatgpt'] = $set(ChatGptAdsEvent::query()->whereIn('user_id', $ids)->get(['user_id', 'payload'])
                ->filter(fn ($event) => is_array($event->payload) && filled($event->payload['oppref'] ?? null))
                ->pluck('user_id'));
        }
        if ($tables['user_referral_profiles']) {
            $traces['invite'] = $set(UserReferralProfile::query()->whereIn('user_id', $ids)->whereNotNull('referred_by_user_id')->pluck('user_id'));
        }

        return $traces;
    }

    /**
     * Where one member came from, as the chosen count reads it.
     *
     * @param  array{google: array<int, true>, chatgpt: array<int, true>, invite: array<int, true>}  $older
     * @return array{channel: string, detail: ?string, campaign: ?string, landing: ?string, site: ?string}
     */
    private function place(object $row, bool $first, array $older): array
    {
        if ($row->source_id !== null) {
            $useFirst = $first && $row->first_channel !== null;
            $channel = (string) ($useFirst ? $row->first_channel : $row->channel);

            return [
                'channel' => $channel,
                'detail' => ($useFirst ? $row->first_detail : $row->detail) ?? ($channel === Channels::UNKNOWN ? self::NOTHING_KEPT : null),
                'campaign' => $useFirst ? $row->first_campaign : $row->campaign,
                'landing' => $useFirst ? $row->first_landing_path : $row->landing_path,
                'site' => $useFirst ? $row->first_referrer_host : $row->referrer_host,
            ];
        }

        $id = (int) $row->id;
        [$channel, $detail] = match (true) {
            isset($older['google'][$id]) => [Channels::GOOGLE_ADS, 'Google Ads (seen at checkout)'],
            isset($older['chatgpt'][$id]) => [Channels::CHATGPT_ADS, 'ChatGPT Ads'],
            isset($older['invite'][$id]) => [Channels::INVITE, 'Invite link'],
            default => [Channels::UNKNOWN, self::BEFORE],
        };

        return ['channel' => $channel, 'detail' => $detail, 'campaign' => null, 'landing' => null, 'site' => null];
    }

    /**
     * The payments made in the period (renewals and extra credits included), by how each payer found the site.
     *
     * @return array{rows: list<array<string, mixed>>, payments: int, money: array<string, int>}
     */
    private function payments(Carbon $from, Carbon $to, bool $first, string $signedUpIn): array
    {
        $payments = DB::table('payments')
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $to)
            ->whereNotNull('user_id')
            ->get(['user_id', 'currency', 'amount_minor', 'refunded_minor']);
        $payerIds = $payments->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        $payers = collect();
        foreach (array_chunk($payerIds, self::CHUNK) as $ids) {
            $payers = $payers->merge(DB::table('users as u')
                ->leftJoin('member_sources as ms', 'ms.user_id', '=', 'u.id')
                ->whereIn('u.id', $ids)
                ->where(fn ($q) => $q->where('u.is_admin', false)->orWhereNull('u.is_admin'))
                ->when($signedUpIn !== 'all', fn ($q) => $q->where('ms.signed_up_in', $signedUpIn))
                ->get(['u.id', 'u.created_at', 'u.ads_click_id', 'ms.id as source_id', 'ms.signed_up_in', 'ms.channel', 'ms.detail', 'ms.campaign',
                    'ms.landing_path', 'ms.referrer_host', 'ms.first_channel', 'ms.first_detail', 'ms.first_campaign', 'ms.first_landing_path', 'ms.first_referrer_host']));
        }
        $payers = $payers->keyBy(fn ($row) => (int) $row->id);
        $older = $this->olderTraces($payers->whereNull('source_id'));

        $rows = [];
        $count = 0;
        $money = [];
        foreach ($payments as $payment) {
            $payer = $payers->get((int) $payment->user_id);
            if ($payer === null) {
                continue;
            }
            $channel = $this->place($payer, $first, $older)['channel'];
            $currency = strtoupper((string) ($payment->currency ?: 'USD'));
            $net = max(0, (int) $payment->amount_minor - (int) $payment->refunded_minor);
            $joinedNow = Carbon::parse($payer->created_at)->greaterThanOrEqualTo($from);

            $rows[$channel] ??= ['key' => $channel, 'label' => Channels::label($channel), 'payments' => 0, 'payers' => [], 'from_new' => 0, 'money' => []];
            $rows[$channel]['payments']++;
            $rows[$channel]['payers'][(int) $payment->user_id] = true;
            $rows[$channel]['from_new'] += $joinedNow ? 1 : 0;
            $rows[$channel]['money'][$currency] = ($rows[$channel]['money'][$currency] ?? 0) + $net;
            $count++;
            $money[$currency] = ($money[$currency] ?? 0) + $net;
        }

        $rows = array_values(array_map(function (array $row): array {
            $row['payers'] = count($row['payers']);

            return $row;
        }, $rows));
        usort($rows, fn (array $a, array $b) => [$b['payments'], $b['payers']] <=> [$a['payments'], $a['payers']]);

        return ['rows' => $rows, 'payments' => $count, 'money' => $money];
    }

    /**
     * The newest members of the period, one by one, to read single cases.
     *
     * @return list<array<string, mixed>>
     */
    private function latest(Carbon $from, Carbon $to, bool $first, string $signedUpIn, int $limit = 25): array
    {
        $rows = $this->members($from, $to, $signedUpIn)
            ->addSelect(['u.name', 'u.email'])
            ->orderByDesc('u.created_at')
            ->orderByDesc('u.id')
            ->limit($limit)
            ->get();
        $money = $this->moneyByMember($rows->pluck('id')->all());
        $older = $this->olderTraces($rows->whereNull('source_id'));

        return $rows->map(function (object $row) use ($first, $older, $money): array {
            $place = $this->place($row, $first, $older);
            $paid = $money[(int) $row->id] ?? null;

            return [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                'joined' => Carbon::parse($row->created_at),
                'channel' => Channels::label($place['channel']),
                'detail' => $place['detail'],
                'campaign' => $place['campaign'],
                'landing' => $place['landing'],
                'signed_up_in' => $row->source_id !== null ? (MemberSource::SIGNED_UP_LABELS[$row->signed_up_in] ?? 'Website') : null,
                'country' => strtoupper((string) ($row->country_code ?: '')) ?: null,
                'paid' => $paid['money'] ?? [],
            ];
        })->values()->all();
    }

    /** @return array{label: string, members: int, paid: int, within_day: int, money: array<string, int>} */
    private static function bucket(string $label): array
    {
        return ['label' => $label, 'members' => 0, 'paid' => 0, 'within_day' => 0, 'money' => []];
    }

    /**
     * @param  array{money: array<string, int>, first_paid_at: ?string}|null  $paid
     * @return array{paid: bool, within_day: bool, money: array<string, int>}
     */
    private static function metrics(mixed $joined, ?array $paid): array
    {
        $money = $paid['money'] ?? [];
        $hasPaid = array_sum($money) > 0;
        $withinDay = false;
        if ($hasPaid && ($paid['first_paid_at'] ?? null) !== null && $joined !== null) {
            $withinDay = Carbon::parse($joined)->diffInSeconds(Carbon::parse($paid['first_paid_at']), false) <= 86400;
        }

        return ['paid' => $hasPaid, 'within_day' => $withinDay, 'money' => $money];
    }

    /** @param  array{paid: bool, within_day: bool, money: array<string, int>}  $metrics */
    private static function add(array &$bucket, array $metrics): void
    {
        $bucket['members']++;
        $bucket['paid'] += $metrics['paid'] ? 1 : 0;
        $bucket['within_day'] += $metrics['within_day'] ? 1 : 0;
        foreach ($metrics['money'] as $currency => $minor) {
            $bucket['money'][$currency] = ($bucket['money'][$currency] ?? 0) + $minor;
        }
    }

    /** @return list<array<string, mixed>> */
    private static function ranked(array $buckets): array
    {
        $buckets = array_values($buckets);
        usort($buckets, fn (array $a, array $b) => [$b['members'], $b['paid'], $a['label']] <=> [$a['members'], $a['paid'], $b['label']]);

        return $buckets;
    }

    private static function divisor(string $currency): int
    {
        return match (true) {
            in_array($currency, ['JPY', 'KRW', 'VND', 'CLP', 'IDR', 'IQD'], true) => 1,
            in_array($currency, ['BHD', 'KWD', 'OMR', 'JOD', 'TND', 'LYD'], true) => 1000,
            default => 100,
        };
    }
}
