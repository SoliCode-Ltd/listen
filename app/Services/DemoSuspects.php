<?php

namespace App\Services;

use App\Models\DemoVoiceGeneration;
use App\Models\MemberDevice;
use App\Models\User;
use App\Support\MailboxKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The accounts Admin → Suspicious Users lists: each one the free demo links to
 * at least one other account, by the demo's own rules (App\Services\DemoSamePerson),
 * with how it is linked:
 *
 * - browser: they share a browser (its cookie);
 * - inbox: their addresses reach one inbox (App\Support\MailboxKey);
 * - device: they share a device and were made close together;
 * - network: they were made on one network within a day of each other;
 * - fingerprint: they made demo voices with one demo fingerprint, close together.
 *
 * Worked out with a few grouping queries over indexed columns, never by comparing
 * every account with every other, and kept for CACHE_MINUTES.
 */
final class DemoSuspects
{
    public const BROWSER = 'browser';

    public const INBOX = 'inbox';

    public const DEVICE = 'device';

    public const NETWORK = 'network';

    public const FINGERPRINT = 'fingerprint';

    public const KINDS = [self::BROWSER, self::INBOX, self::DEVICE, self::NETWORK, self::FINGERPRINT];

    public const CACHE_KEY = 'admin:demo_suspects:v1';

    /** The navigation badge's number (forgotten wherever accounts or their links change). */
    public const COUNT_KEY = 'admin:suspicious_users_count';

    // Kept this long; listen:warm-demo-suspects works it out again every 5 minutes, so an
    // admin opening the page (or any admin page, for its badge) never waits for it.
    public const CACHE_MINUTES = 15;

    // One grouping of linked accounts larger than this is checked by neighbours in time only.
    private const PAIRWISE_LIMIT = 60;

    /**
     * Every linked account and how it is linked.
     *
     * @return array<int, list<string>> account id => kinds
     */
    public function all(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $found = $this->compute();
        Cache::put(self::CACHE_KEY, $found, now()->addMinutes(self::CACHE_MINUTES));

        return $found;
    }

    /** Works the list out again now and keeps it (the scheduler's listen:warm-demo-suspects). */
    public function refresh(): int
    {
        $found = $this->compute();
        Cache::put(self::CACHE_KEY, $found, now()->addMinutes(self::CACHE_MINUTES));
        Cache::put(self::COUNT_KEY, count($found), now()->addMinutes(self::CACHE_MINUTES));

        return count($found);
    }

    public function count(): int
    {
        return (int) Cache::remember(self::COUNT_KEY, self::CACHE_MINUTES * 60, fn (): int => count($this->all()));
    }

    /** After an admin blocks, unblocks or deletes: the page and its badge are worked out again. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::COUNT_KEY);
    }

    /** @return array<int, list<string>> */
    private function compute(): array
    {
        $kinds = [];
        $mark = function (iterable $ids, string $kind) use (&$kinds): void {
            foreach ($ids as $id) {
                $kinds[(int) $id][$kind] = true;
            }
        };

        $mark($this->sharedBrowser(), self::BROWSER);
        $mark($this->sharedInbox(), self::INBOX);
        $mark($this->sharedDevice(), self::DEVICE);
        $mark($this->sharedNetwork(), self::NETWORK);
        $mark($this->sharedFingerprint(), self::FINGERPRINT);

        ksort($kinds);

        return array_map(fn (array $set): array => array_values(array_intersect(self::KINDS, array_keys($set))), $kinds);
    }

    /** @return list<int> */
    private function sharedBrowser(): array
    {
        // A browser (its cookie) two accounts have, in any of the places it is kept: a few
        // grouping and joining queries over indexed columns (one grouping over all of them
        // together took seconds at hundreds of thousands of accounts).
        $shared = [];
        foreach (['registration_device_id', 'last_seen_device_id'] as $column) {
            $shared[] = User::query()->whereNotNull($column)->where($column, '!=', '')
                ->groupBy($column)->havingRaw('COUNT(*) > 1')->pluck($column)->all();
        }
        $shared[] = DB::table('users as a')->join('users as b', 'b.last_seen_device_id', '=', 'a.registration_device_id')
            ->whereColumn('a.id', '!=', 'b.id')->where('a.registration_device_id', '!=', '')
            ->distinct()->pluck('a.registration_device_id')->all();
        if (DemoLinkedAccounts::available()) {
            $shared[] = MemberDevice::query()->where('kind', MemberDevice::BROWSER)
                ->groupBy('signal')->havingRaw('COUNT(DISTINCT user_id) > 1')->pluck('signal')->all();
            foreach (['registration_device_id', 'last_seen_device_id'] as $column) {
                $shared[] = DB::table('member_devices as m')->join('users as u', "u.{$column}", '=', 'm.signal')
                    ->where('m.kind', MemberDevice::BROWSER)->whereColumn('u.id', '!=', 'm.user_id')
                    ->distinct()->pluck('m.signal')->all();
            }
        }
        $shared = array_values(array_unique(array_merge(...$shared)));

        $ids = [];
        foreach (array_chunk($shared, 1000) as $chunk) {
            $ids = array_merge($ids,
                User::query()->where(fn ($q) => $q->whereIn('registration_device_id', $chunk)->orWhereIn('last_seen_device_id', $chunk))->pluck('id')->all(),
                DemoLinkedAccounts::available()
                    ? MemberDevice::query()->where('kind', MemberDevice::BROWSER)->whereIn('signal', $chunk)->pluck('user_id')->all()
                    : [],
            );
        }

        return $ids;
    }

    /** @return list<int> */
    private function sharedInbox(): array
    {
        if (! MailboxKey::available()) {
            return [];
        }
        // Confirmed addresses only, as the demo counts them (MailboxKey::confirmed()).
        $shared = MailboxKey::confirmedOnly(User::query()->whereNotNull(MailboxKey::COLUMN))->groupBy(MailboxKey::COLUMN)
            ->havingRaw('COUNT(*) > 1')->pluck(MailboxKey::COLUMN)->all();

        $ids = [];
        foreach (array_chunk($shared, 1000) as $chunk) {
            $ids = array_merge($ids, MailboxKey::confirmedOnly(User::query()->whereIn(MailboxKey::COLUMN, $chunk))->pluck('id')->all());
        }

        return $ids;
    }

    /** Accounts sharing a device with another made close together (DemoLinkedAccounts' windows). @return list<int> */
    private function sharedDevice(): array
    {
        if (! DemoLinkedAccounts::available()) {
            return [];
        }
        $shared = MemberDevice::query()->where('kind', MemberDevice::DEVICE)->groupBy('signal')
            ->havingRaw('COUNT(DISTINCT user_id) > 1')->pluck('signal')->all();

        $ids = [];
        foreach (array_chunk($shared, 500) as $chunk) {
            $rows = DB::table('member_devices')->join('users', 'users.id', '=', 'member_devices.user_id')
                ->where('member_devices.kind', MemberDevice::DEVICE)->whereIn('member_devices.signal', $chunk)
                ->get(['member_devices.signal as g', 'users.id as u', 'users.created_at as t', 'member_devices.touch as touch']);
            foreach ($rows->groupBy('g') as $group) {
                $ids = array_merge($ids, $this->closeInTime($group, fn ($a, $b): int => ((bool) $a->touch || (bool) $b->touch)
                    ? DemoLinkedAccounts::TOUCH_WINDOW_HOURS * 3600
                    : DemoLinkedAccounts::DESKTOP_WINDOW_DAYS * 86400));
            }
        }

        return $ids;
    }

    /** Accounts made on one network within a day of another (DemoSamePerson::NETWORK_WINDOW_HOURS). @return list<int> */
    private function sharedNetwork(): array
    {
        $shared = User::query()->whereNotNull('registration_ip')->where('registration_ip', '!=', '')
            ->groupBy('registration_ip')->havingRaw('COUNT(*) > 1')->pluck('registration_ip')->all();

        $ids = [];
        foreach (array_chunk($shared, 500) as $chunk) {
            $rows = DB::table('users')->whereIn('registration_ip', $chunk)->whereNotNull('created_at')
                ->get(['registration_ip as g', 'id as u', 'created_at as t']);
            foreach ($rows->groupBy('g') as $group) {
                $ids = array_merge($ids, $this->closeInTime($group, fn (): int => DemoSamePerson::NETWORK_WINDOW_HOURS * 3600));
            }
        }

        return $ids;
    }

    /** Accounts that made demo voices with one demo fingerprint (DemoSamePerson's rule). @return list<int> */
    private function sharedFingerprint(): array
    {
        $shared = DemoVoiceGeneration::query()->whereNotNull('user_id')->groupBy('primary_identity_hash')
            ->havingRaw('COUNT(DISTINCT user_id) > 1')->pluck('primary_identity_hash')->all();

        $ids = [];
        foreach (array_chunk($shared, 500) as $chunk) {
            $rows = DB::table('demo_voice_generations')->join('users', 'users.id', '=', 'demo_voice_generations.user_id')
                ->whereIn('demo_voice_generations.primary_identity_hash', $chunk)
                ->distinct()->get(['demo_voice_generations.primary_identity_hash as g', 'users.id as u', 'users.created_at as t']);
            foreach ($rows->groupBy('g') as $hash => $group) {
                $ids = str_starts_with((string) $hash, 'dc:')
                    // The browser cookie is one browser's own: linked however far apart.
                    ? array_merge($ids, $group->pluck('u')->all())
                    : array_merge($ids, $this->closeInTime($group, fn (): int => DemoSamePerson::FINGERPRINT_WINDOW_HOURS * 3600));
            }
        }

        return $ids;
    }

    /**
     * The accounts of one grouping (rows with u: id, t: created at) that were
     * made within $window seconds of another account of it.
     *
     * @param  Collection<int, object>  $group
     * @param  callable(object, object): int  $window
     * @return list<int>
     */
    private function closeInTime($group, callable $window): array
    {
        $rows = $group->unique('u')->filter(fn ($row) => $row->t !== null)
            ->map(function ($row) {
                $row->ts = Carbon::parse($row->t)->getTimestamp();

                return $row;
            })
            ->sortBy('ts')->values();
        $count = $rows->count();
        if ($count < 2) {
            return [];
        }

        $found = [];
        for ($i = 0; $i < $count; $i++) {
            // Small groupings: every pair. Large ones (one phone model, a busy network): the neighbours in time.
            $others = $count <= self::PAIRWISE_LIMIT ? range(0, $count - 1) : array_filter([$i - 1, $i + 1], fn ($j) => $j >= 0 && $j < $count);
            foreach ($others as $j) {
                if ($j !== $i && abs($rows[$i]->ts - $rows[$j]->ts) <= $window($rows[$i], $rows[$j])) {
                    $found[] = (int) $rows[$i]->u;

                    break;
                }
            }
        }

        return $found;
    }
}
