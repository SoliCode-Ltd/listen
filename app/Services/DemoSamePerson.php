<?php

namespace App\Services;

use App\Models\AgentConversation;
use App\Models\DemoLeftBehind;
use App\Models\DemoLeftBehindSignal;
use App\Models\DemoVoiceGeneration;
use App\Models\MemberDevice;
use App\Models\User;
use App\Models\VideoDub;
use App\Support\Agents\HouseAgent;
use App\Support\MailboxKey;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Who counts as one person for everything free: the text demo, the demo's
 * Voice Design try, the free video dub and the free voice-agent call time.
 * Each of them asks here, so none is a way round the others.
 *
 * One person is the member and every account linked to them:
 * - the same browser (its cookie) or the same inbox (App\Support\MailboxKey),
 *   however far apart the accounts were made, followed from account to account;
 * - the same device in another window or browser profile, when the accounts were
 *   made close together (App\Services\DemoLinkedAccounts: identical phones and
 *   computers report the same device);
 * - the same sign-up network, for accounts made within NETWORK_WINDOW_HOURS of
 *   each other (several accounts in a row; further apart they are usually
 *   strangers on one mobile network, campus or office);
 * - the demo fingerprint the page sends, within FINGERPRINT_WINDOW_HOURS;
 * - and what accounts like those had used before an admin deleted them (leaveBehind()).
 */
final class DemoSamePerson
{
    public const NETWORK_WINDOW_HOURS = 24;

    public const FINGERPRINT_WINDOW_HOURS = 72;

    // What demo_voice_generations.primary_identity_hash holds (MySQL refuses more).
    public const IDENTITY_COLUMN_LENGTH = 255;

    /** @var \WeakMap<Request, array<string, mixed>> */
    private \WeakMap $memo;

    public function __construct(private readonly DemoLinkedAccounts $linked)
    {
        $this->memo = new \WeakMap;
    }

    /**
     * The member and every account that is the same person (see above). Worked out
     * once for each request: a demo request asks several times.
     *
     * @return list<int>
     */
    public function ids(User $user, ?Request $request = null): array
    {
        return $this->remember($request, 'ids:'.$user->id, function () use ($user, $request): array {
            $ids = $this->linked->linkedUserIds($user, $request);
            $windowed = array_merge($this->sameNetwork($ids), $this->sameFingerprint($user, $request));
            if (array_diff($windowed, $ids) !== []) {
                $ids = $this->linked->withSameBrowser(array_merge($ids, $windowed));
            }
            if (! in_array((int) $user->id, $ids, true)) {
                $ids[] = (int) $user->id;
            }

            return array_values(array_unique(array_map('intval', $ids)));
        });
    }

    /** Demo characters this person has used: their accounts', and their deleted accounts'. */
    public function demoCharsUsed(User $user, ?Request $request = null): int
    {
        return $this->demoUsage($user, $request)['chars'];
    }

    /**
     * The accounts that are this person and the demo characters they have used
     * together, deleted accounts' included, from one working out.
     *
     * @return array{ids: list<int>, chars: int}
     */
    public function demoUsage(User $user, ?Request $request = null): array
    {
        $ids = $this->ids($user, $request);

        return [
            'ids' => $ids,
            'chars' => (int) DemoVoiceGeneration::query()->whereIn('user_id', $ids)->sum('billable_chars')
                + (int) $this->leftBehindFor($user, $ids, $request)->sum('demo_chars'),
        ];
    }

    /** Whether this person has had the demo's one Voice Design try. */
    public function designUsed(User $user, ?Request $request = null): bool
    {
        return DemoVoiceGeneration::query()
            ->whereIn('user_id', $this->ids($user, $request))
            ->whereNotNull('metadata->voice_design')
            ->exists()
            || $this->leftBehind($user, $request)->contains('design_used', true);
    }

    /** Free voice-agent test seconds this person's deleted accounts had used. */
    public function leftBehindCallSeconds(User $user, ?Request $request = null): int
    {
        return (int) $this->leftBehind($user, $request)->sum('free_call_seconds');
    }

    /** Whether one of this person's deleted accounts had had the free dub. */
    public function leftBehindFreeDub(User $user, ?Request $request = null): bool
    {
        return $this->leftBehind($user, $request)->contains('free_dub_used', true);
    }

    /**
     * The demo fingerprint of a request: the one the demo page sends, else the
     * browser cookie, else the network address and browser together.
     */
    public static function fingerprintFrom(Request $request): string
    {
        // The demo page sends 64 characters. A longer one, with its prefix, would not
        // fit the column: the voice would be made but never stored, so never counted.
        $fp = trim((string) $request->header('X-Client-FP', ''));
        if ($fp !== '' && strlen($fp) >= 16 && strlen('fp:'.$fp) <= self::IDENTITY_COLUMN_LENGTH) {
            return 'fp:'.$fp;
        }

        if (($browser = DemoLinkedAccounts::browserFrom($request)) !== null) {
            return 'dc:'.$browser;
        }

        // Request signature (Sec-CH-UA headers) is skipped on purpose: millions of
        // people with one browser version send the same one.
        $requestIdentity = trim((string) $request->ip()).'|'.trim((string) $request->userAgent());
        if ($requestIdentity !== '|') {
            return 'rq:'.hash('sha256', strtolower($requestIdentity));
        }

        return 'u:'.(int) ($request->user()?->id ?? 0);
    }

    /**
     * Keeps what an account had used of the free demo before it is deleted, with
     * one-way hashes of what recognises it (its browsers, devices, sign-up network,
     * inbox and demo fingerprints), so the same person's other accounts still
     * count it. Nothing is kept for an account that used none of it.
     */
    public function leaveBehind(User $user, ?User $admin = null): ?DemoLeftBehind
    {
        if (! self::leftBehindAvailable()) {
            return null;
        }

        $userId = (int) $user->id;
        $chars = (int) DemoVoiceGeneration::query()->where('user_id', $userId)->sum('billable_chars');
        $design = DemoVoiceGeneration::query()->where('user_id', $userId)->whereNotNull('metadata->voice_design')->exists();
        $dub = rescue(fn () => VideoDub::query()->where('user_id', $userId)->where('is_free', true)
            ->whereNotIn('status', VideoDub::GIVEN_BACK)->exists(), false, false);
        $seconds = rescue(fn () => $this->ownFreeCallSeconds($userId), 0, false);
        if ($chars <= 0 && ! $design && ! $dub && $seconds <= 0) {
            return null;
        }

        $made = $user->created_at;
        $signals = [];
        $add = function (string $kind, ?string $value, bool $touch = false) use (&$signals, $made): void {
            $value = trim((string) $value);
            if ($value === '') {
                return;
            }
            $signals[$kind.':'.$value] = ['kind' => $kind, 'signal' => DemoLeftBehindSignal::hash($value), 'touch' => $touch, 'account_created_at' => $made];
        };

        $add(DemoLeftBehindSignal::BROWSER, $user->registration_device_id);
        $add(DemoLeftBehindSignal::BROWSER, $user->last_seen_device_id);
        $add(DemoLeftBehindSignal::NETWORK, $user->registration_ip);
        $add(DemoLeftBehindSignal::MAILBOX, MailboxKey::for($user->email));
        if (DemoLinkedAccounts::available()) {
            foreach (MemberDevice::query()->where('user_id', $userId)->get(['kind', 'signal', 'touch']) as $device) {
                $add($device->kind === MemberDevice::DEVICE ? DemoLeftBehindSignal::DEVICE : DemoLeftBehindSignal::BROWSER, $device->signal, (bool) $device->touch);
            }
        }
        foreach (DemoVoiceGeneration::query()->where('user_id', $userId)->distinct()->pluck('primary_identity_hash') as $fingerprint) {
            $add(DemoLeftBehindSignal::FINGERPRINT, (string) $fingerprint);
        }

        return DB::transaction(function () use ($userId, $made, $chars, $design, $dub, $seconds, $admin, $signals): DemoLeftBehind {
            $left = DemoLeftBehind::query()->create([
                'former_user_id' => $userId,
                'account_created_at' => $made,
                'demo_chars' => $chars,
                'design_used' => $design,
                'free_dub_used' => $dub,
                'free_call_seconds' => $seconds,
                'deleted_by' => $admin?->id,
            ]);
            foreach (array_chunk(array_values($signals), 200) as $chunk) {
                DemoLeftBehindSignal::query()->insert(array_map(fn (array $row): array => $row + ['demo_left_behind_id' => $left->id], $chunk));
            }

            return $left;
        });
    }

    /**
     * What this person's deleted accounts had used: those recognised by one of
     * their browsers or inboxes at any time, by one of their devices or their
     * sign-up network when the accounts were made close together, or by the
     * request's demo fingerprint.
     *
     * @return Collection<int, DemoLeftBehind>
     */
    public function leftBehind(User $user, ?Request $request = null): Collection
    {
        return self::leftBehindAvailable() ? $this->leftBehindFor($user, $this->ids($user, $request), $request) : collect();
    }

    /**
     * @param  list<int>  $ids  this person's accounts (ids())
     * @return Collection<int, DemoLeftBehind>
     */
    private function leftBehindFor(User $user, array $ids, ?Request $request): Collection
    {
        if (! self::leftBehindAvailable()) {
            return collect();
        }

        return $this->remember($request, 'left:'.$user->id, function () use ($user, $ids, $request): Collection {
            try {
                return $this->findLeftBehind($user, $ids, $request);
            } catch (\Throwable $e) {
                // Never stops the demo: what is left behind only adds to what is counted.
                Log::warning('Demo: deleted accounts could not be checked.', ['user_id' => $user->id, 'error' => $e->getMessage()]);

                return collect();
            }
        });
    }

    /** False until the migration that makes the demo_left_behind tables has run. */
    public static function leftBehindAvailable(): bool
    {
        return once(fn (): bool => rescue(fn () => Schema::hasTable('demo_left_behind_signals'), false, false));
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, DemoLeftBehind>
     */
    private function findLeftBehind(User $user, array $ids, ?Request $request): Collection
    {
        $columns = ['id', 'created_at', 'registration_ip', 'registration_device_id', 'last_seen_device_id'];
        if (MailboxKey::available()) {
            $columns[] = MailboxKey::COLUMN;
        }
        $accounts = User::query()->whereIn('id', $ids)->get($columns)->keyBy('id');

        $anyTime = [DemoLeftBehindSignal::BROWSER => [], DemoLeftBehindSignal::MAILBOX => []];
        $devices = [];   // hash => list of [created_at, touch]
        $networks = [];  // hash => list of created_at
        foreach ($accounts as $account) {
            foreach ([$account->registration_device_id, $account->last_seen_device_id] as $browser) {
                if (filled($browser)) {
                    $anyTime[DemoLeftBehindSignal::BROWSER][DemoLeftBehindSignal::hash((string) $browser)] = true;
                }
            }
            $inbox = MailboxKey::available() ? $account->getAttribute(MailboxKey::COLUMN) : null;
            if (filled($inbox)) {
                $anyTime[DemoLeftBehindSignal::MAILBOX][DemoLeftBehindSignal::hash((string) $inbox)] = true;
            }
            if (filled($account->registration_ip) && $account->created_at) {
                $networks[DemoLeftBehindSignal::hash((string) $account->registration_ip)][] = $account->created_at;
            }
        }
        if ($request !== null && ($browser = DemoLinkedAccounts::browserFrom($request)) !== null) {
            $anyTime[DemoLeftBehindSignal::BROWSER][DemoLeftBehindSignal::hash($browser)] = true;
        }
        if (DemoLinkedAccounts::available()) {
            foreach (MemberDevice::query()->whereIn('user_id', $ids)->get(['user_id', 'kind', 'signal', 'touch']) as $row) {
                $hash = DemoLeftBehindSignal::hash((string) $row->signal);
                if ($row->kind === MemberDevice::BROWSER) {
                    $anyTime[DemoLeftBehindSignal::BROWSER][$hash] = true;
                } elseif (($made = $accounts->get($row->user_id)?->created_at) !== null) {
                    $devices[$hash][] = [$made, (bool) $row->touch];
                }
            }
        }
        if ($request !== null && ($device = DemoLinkedAccounts::deviceFrom($request)) !== null && $user->created_at) {
            $devices[DemoLeftBehindSignal::hash($device['signal'])][] = [$user->created_at, $device['touch']];
        }
        $fingerprint = $request !== null ? self::fingerprintFrom($request) : null;
        $fingerprintHash = $fingerprint !== null ? DemoLeftBehindSignal::hash($fingerprint) : null;

        $wanted = [
            DemoLeftBehindSignal::BROWSER => array_keys($anyTime[DemoLeftBehindSignal::BROWSER]),
            DemoLeftBehindSignal::MAILBOX => array_keys($anyTime[DemoLeftBehindSignal::MAILBOX]),
            DemoLeftBehindSignal::DEVICE => array_keys($devices),
            DemoLeftBehindSignal::NETWORK => array_keys($networks),
            DemoLeftBehindSignal::FINGERPRINT => $fingerprintHash !== null ? [$fingerprintHash] : [],
        ];
        $wanted = array_filter($wanted);
        if ($wanted === []) {
            return collect();
        }

        $rows = DemoLeftBehindSignal::query()
            ->where(function ($query) use ($wanted): void {
                foreach ($wanted as $kind => $hashes) {
                    $query->orWhere(fn ($q) => $q->where('kind', $kind)->whereIn('signal', $hashes));
                }
            })
            ->limit(DemoLinkedAccounts::MAX_ACCOUNTS * 10)
            ->get(['demo_left_behind_id', 'kind', 'signal', 'touch', 'account_created_at']);

        $close = static fn (?Carbon $a, ?Carbon $b, int $seconds): bool => $a !== null && $b !== null
            && abs($a->getTimestamp() - $b->getTimestamp()) <= $seconds;

        $matched = [];
        foreach ($rows as $row) {
            $theirs = $row->account_created_at;
            $counts = match ($row->kind) {
                DemoLeftBehindSignal::BROWSER, DemoLeftBehindSignal::MAILBOX => true,
                DemoLeftBehindSignal::NETWORK => collect($networks[$row->signal] ?? [])
                    ->contains(fn (Carbon $ours): bool => $close($ours, $theirs, self::NETWORK_WINDOW_HOURS * 3600)),
                DemoLeftBehindSignal::DEVICE => collect($devices[$row->signal] ?? [])
                    ->contains(fn (array $ours): bool => $close($ours[0], $theirs, ($ours[1] || $row->touch)
                        ? DemoLinkedAccounts::TOUCH_WINDOW_HOURS * 3600
                        : DemoLinkedAccounts::DESKTOP_WINDOW_DAYS * 86400)),
                DemoLeftBehindSignal::FINGERPRINT => str_starts_with((string) $fingerprint, 'dc:')
                    || $close($user->created_at, $theirs, self::FINGERPRINT_WINDOW_HOURS * 3600),
                default => false,
            };
            if ($counts) {
                $matched[(int) $row->demo_left_behind_id] = true;
            }
        }

        return $matched === [] ? collect() : DemoLeftBehind::query()->whereIn('id', array_keys($matched))->get();
    }

    /**
     * Accounts made on the sign-up network of one of these accounts within
     * NETWORK_WINDOW_HOURS of it.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function sameNetwork(array $ids): array
    {
        // Not cached: a second account made a minute ago must count at once.
        $made = User::query()->whereIn('id', $ids)->whereNotNull('registration_ip')->where('registration_ip', '!=', '')
            ->whereNotNull('created_at')->get(['id', 'registration_ip', 'created_at']);
        if ($made->isEmpty()) {
            return [];
        }

        $window = self::NETWORK_WINDOW_HOURS * 3600;
        $found = [];
        foreach ($made->groupBy('registration_ip') as $ip => $accounts) {
            $from = $accounts->min('created_at')->copy()->subHours(self::NETWORK_WINDOW_HOURS);
            $to = $accounts->max('created_at')->copy()->addHours(self::NETWORK_WINDOW_HOURS);
            $neighbours = User::query()->where('registration_ip', (string) $ip)->whereBetween('created_at', [$from, $to])
                ->limit(DemoLinkedAccounts::MAX_ACCOUNTS)->get(['id', 'created_at']);
            foreach ($neighbours as $neighbour) {
                foreach ($accounts as $account) {
                    if (abs($neighbour->created_at->getTimestamp() - $account->created_at->getTimestamp()) <= $window) {
                        $found[] = (int) $neighbour->id;

                        break;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Accounts whose demo voices were made with this request's demo fingerprint.
     * Identical phones share a fingerprint and everyone on one network shares the
     * address-and-browser fallback, so those count only within
     * FINGERPRINT_WINDOW_HOURS; the browser cookie is this browser's own.
     *
     * @return list<int>
     */
    private function sameFingerprint(User $user, ?Request $request): array
    {
        if ($request === null) {
            return [];
        }
        $fp = self::fingerprintFrom($request);

        $ids = DemoVoiceGeneration::query()->where('primary_identity_hash', $fp)->whereNotNull('user_id')
            ->distinct()->limit(DemoLinkedAccounts::MAX_ACCOUNTS)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        if ($ids === [] || str_starts_with($fp, 'dc:')) {
            return $ids;
        }
        if (! $user->created_at) {
            return [];
        }

        return User::query()->whereIn('id', $ids)
            ->whereBetween('created_at', [
                $user->created_at->copy()->subHours(self::FINGERPRINT_WINDOW_HOURS),
                $user->created_at->copy()->addHours(self::FINGERPRINT_WINDOW_HOURS),
            ])
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Free test-call seconds this one account has used, as App\Services\Agents\AgentCalls counts them. */
    private function ownFreeCallSeconds(int $userId): int
    {
        $house = HouseAgent::agentKeys();

        return (int) AgentConversation::query()
            ->where('user_id', $userId)->where('channel', 'test')->where('free', true)
            ->when($house !== [], fn ($q) => $q->whereNotIn('agent_id', $house))
            ->get(['billed_seconds', 'reservation', 'settled_at'])
            ->sum(fn (AgentConversation $c) => $c->settled_at ? (int) $c->billed_seconds : (int) ($c->reservation['seconds'] ?? 0));
    }

    private function remember(?Request $request, string $key, callable $work): mixed
    {
        if ($request === null) {
            return $work();
        }
        $memo = $this->memo[$request] ?? [];
        if (! array_key_exists($key, $memo)) {
            $memo[$key] = $work();
            $this->memo[$request] = $memo;
        }

        return $memo[$key];
    }
}
