<?php

namespace App\Services;

use App\Models\MemberDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts made on one device share one free demo, whichever window or
 * browser profile they were made in. The demo page tells the server two
 * things about where it runs: its device cookie (an incognito window or
 * another browser starts without it) and a fingerprint of the device itself,
 * which an incognito window keeps: how it draws text and shapes, its graphics
 * card, its fonts, processor count, platform, time zone and kind of browser,
 * but not the window's size, the zoom or the browser's version, which change
 * by themselves (resources/views/demo.blade.php).
 *
 * Linked to a member: every account that shares a browser with them, however
 * far apart they were made; then every account that shares the device with
 * any of those and was made close enough to it, since identical phones (and
 * identical computers) report the same fingerprint: TOUCH_WINDOW_HOURS for a
 * phone or tablet, DESKTOP_WINDOW_DAYS for a computer; then every account
 * sharing a browser with those. HomeController::demoLinkedUserIds adds the
 * sign-up address and the older fingerprint rule.
 */
final class DemoLinkedAccounts
{
    public const BROWSER_COOKIE = 'listen_demo_device';

    public const DEVICE_HEADER = 'X-Client-Device';

    public const TOUCH_HEADER = 'X-Client-Device-Touch';

    public const TOUCH_WINDOW_HOURS = 72;

    public const DESKTOP_WINDOW_DAYS = 30;

    /** No pool grows past this many accounts (a computer many people use, say). */
    public const MAX_ACCOUNTS = 200;

    private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /** False until the member_devices migration has run: browsers then come from the users table only. */
    public static function available(): bool
    {
        return once(fn (): bool => rescue(fn () => Schema::hasTable('member_devices'), false, false));
    }

    /** The device cookie of this request, when it looks like one the site set. */
    public static function browserFrom(Request $request): ?string
    {
        $raw = trim((string) $request->cookie(self::BROWSER_COOKIE, ''));

        return $raw !== '' && preg_match('/^[A-Za-z0-9_-]{24,128}$/', $raw) === 1 ? $raw : null;
    }

    /**
     * The device fingerprint the demo page sent, and whether the device is a
     * phone or tablet (the page says so, or the browser does).
     *
     * @return array{signal: string, touch: bool}|null
     */
    public static function deviceFrom(Request $request): ?array
    {
        $signal = strtolower(trim((string) $request->header(self::DEVICE_HEADER, '')));
        if (preg_match('/^[a-f0-9]{64}$/', $signal) !== 1 || $signal === self::EMPTY_SHA256) {
            return null;
        }

        $touch = $request->header(self::TOUCH_HEADER) === '1'
            || $request->header('Sec-CH-UA-Mobile') === '?1'
            || preg_match('/Mobi|Android|iPhone|iPad|iPod/i', (string) $request->userAgent()) === 1;

        return ['signal' => $signal, 'touch' => $touch];
    }

    /** Remembers the browser and device of this request for the member, at most once an hour each. */
    public function record(User $user, Request $request): void
    {
        if (! self::available()) {
            return;
        }

        $seen = [];
        if (($browser = self::browserFrom($request)) !== null) {
            $seen[] = [MemberDevice::BROWSER, $browser, false];
        }
        if (($device = self::deviceFrom($request)) !== null) {
            $seen[] = [MemberDevice::DEVICE, $device['signal'], $device['touch']];
        }

        foreach ($seen as [$kind, $signal, $touch]) {
            $key = 'member-device:'.$user->id.':'.$kind.':'.sha1($signal);
            if (! Cache::add($key, 1, now()->addHour())) {
                continue;
            }

            $now = now();
            try {
                MemberDevice::query()->upsert(
                    [['user_id' => $user->id, 'kind' => $kind, 'signal' => $signal, 'touch' => $touch,
                        'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now]],
                    ['user_id', 'kind', 'signal'],
                    ['touch', 'last_seen_at', 'updated_at'],
                );
            } catch (\Throwable $e) {
                Cache::forget($key);
                Log::warning('A member device was not recorded.', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * The member and every account linked to them through a browser or the
     * device. The browser and device of the request count as the member's own.
     *
     * @return list<int>
     */
    public function linkedUserIds(User $user, ?Request $request = null): array
    {
        $requestBrowsers = [];
        $requestDevices = [];
        if ($request !== null) {
            if (($browser = self::browserFrom($request)) !== null) {
                $requestBrowsers[] = $browser;
            }
            if (($device = self::deviceFrom($request)) !== null) {
                $requestDevices[$device['signal']] = $device['touch'];
            }
        }

        $ids = $this->withSameBrowser([(int) $user->id], $requestBrowsers);
        if (! self::available()) {
            return $ids;
        }

        // The devices of this browser group, and when each of its accounts that used one was made.
        $own = MemberDevice::query()->where('kind', MemberDevice::DEVICE)->whereIn('user_id', $ids)
            ->get(['user_id', 'signal', 'touch']);
        $holders = [];
        $touch = $requestDevices;
        foreach ($own as $row) {
            $holders[$row->signal][] = (int) $row->user_id;
            $touch[$row->signal] = ($touch[$row->signal] ?? false) || (bool) $row->touch;
        }
        foreach (array_keys($requestDevices) as $signal) {
            $holders[$signal][] = (int) $user->id;
        }
        if ($holders === []) {
            return $ids;
        }

        $others = MemberDevice::query()->where('kind', MemberDevice::DEVICE)
            ->whereIn('signal', array_keys($holders))->whereNotIn('user_id', $ids)
            ->limit(self::MAX_ACCOUNTS)->get(['user_id', 'signal', 'touch']);
        if ($others->isEmpty()) {
            return $ids;
        }

        $made = User::query()->whereIn('id', array_merge($ids, $others->pluck('user_id')->all()))->pluck('created_at', 'id');
        $linked = [];
        foreach ($others as $row) {
            $theirs = $made[$row->user_id] ?? null;
            if ($theirs === null) {
                continue;
            }
            $window = (($touch[$row->signal] ?? false) || $row->touch)
                ? self::TOUCH_WINDOW_HOURS * 3600
                : self::DESKTOP_WINDOW_DAYS * 86400;
            foreach (array_unique($holders[$row->signal]) as $holder) {
                $ours = $made[$holder] ?? null;
                if ($ours !== null && abs($ours->getTimestamp() - $theirs->getTimestamp()) <= $window) {
                    $linked[] = (int) $row->user_id;

                    break;
                }
            }
        }

        return $linked === [] ? $ids : $this->withSameBrowser(array_merge($ids, $linked));
    }

    /**
     * These accounts and every account that shares a browser with them,
     * followed from account to account: one browser is one person's.
     *
     * @param  list<int>  $ids
     * @param  list<string>  $browsers  more browsers that belong to these accounts
     * @return list<int>
     */
    public function withSameBrowser(array $ids, array $browsers = []): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $followed = [];

        for ($round = 0; $round < 4 && count($ids) < self::MAX_ACCOUNTS; $round++) {
            $theirs = User::query()->whereIn('id', $ids)->get(['registration_device_id', 'last_seen_device_id'])
                ->flatMap(fn (User $u): array => [(string) $u->registration_device_id, (string) $u->last_seen_device_id])
                ->all();
            if (self::available()) {
                $theirs = array_merge($theirs, MemberDevice::query()->where('kind', MemberDevice::BROWSER)
                    ->whereIn('user_id', $ids)->pluck('signal')->all());
            }
            $new = array_values(array_diff(array_unique(array_filter(array_merge($browsers, $theirs))), $followed));
            $browsers = [];
            if ($new === []) {
                break;
            }
            $followed = array_merge($followed, $new);

            $more = User::query()
                ->where(fn ($q) => $q->whereIn('registration_device_id', $new)->orWhereIn('last_seen_device_id', $new))
                ->limit(self::MAX_ACCOUNTS)->pluck('id')->all();
            if (self::available()) {
                $more = array_merge($more, MemberDevice::query()->where('kind', MemberDevice::BROWSER)
                    ->whereIn('signal', $new)->limit(self::MAX_ACCOUNTS)->pluck('user_id')->all());
            }
            $ids = array_values(array_unique(array_merge($ids, array_map('intval', $more))));
        }

        return array_slice($ids, 0, self::MAX_ACCOUNTS);
    }
}
