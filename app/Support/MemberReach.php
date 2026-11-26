<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Where Listen's members are, for the homepage's reach section: how many members, how many
 * countries they are in (users.country_code, kept at sign-up), and how strongly to light each
 * country on the map. Counts only, cached for an hour; admin accounts are left out.
 */
final class MemberReach
{
    /** A new key starts every server from a fresh count the moment it is deployed. */
    public const CACHE_KEY = 'member_reach:v2';

    /** How long a count is kept: a new country or the next thousand members shows within the hour. */
    public const FRESH_MINUTES = 60;

    /** Below this, the section is not shown (a new install, a test database). */
    public const MIN_MEMBERS = 100;

    public const MIN_COUNTRIES = 5;

    /** Members per country that light a country at each step: some, many, the most. */
    public const TIERS = [1 => 1, 2 => 50, 3 => 500];

    /** Countries the globe never lights or names, whatever their members (the owner's choice). They still count in the totals. */
    public const NOT_ON_GLOBE = ['IL'];

    /**
     * @return array{members: int, countries: array<string, int>}
     */
    public static function counts(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(self::FRESH_MINUTES), function (): array {
            $members = DB::table('users')->where(fn ($q) => $q->where('is_admin', false)->orWhereNull('is_admin'));
            $byCountry = (clone $members)
                ->whereNotNull('country_code')->where('country_code', '!=', '')
                ->select('country_code', DB::raw('count(*) as n'))
                ->groupBy('country_code')
                ->pluck('n', 'country_code')
                ->mapWithKeys(fn ($n, $code) => [strtoupper(trim((string) $code)) => (int) $n])
                ->filter(fn (int $n, string $code) => preg_match('/^[A-Z]{2}$/', $code) === 1)
                ->all();

            return ['members' => (clone $members)->count(), 'countries' => $byCountry];
        });
    }

    public static function show(): bool
    {
        $c = self::counts();

        return $c['members'] >= self::MIN_MEMBERS && count($c['countries']) >= self::MIN_COUNTRIES;
    }

    /** The member count as the page claims it: rounded down, so the claim stays true ("9,000+"). */
    public static function membersRoundedDown(): int
    {
        $n = self::counts()['members'];
        $unit = $n >= 1000 ? 1000 : 100;

        return intdiv($n, $unit) * $unit;
    }

    public static function countryCount(): int
    {
        return count(self::counts()['countries']);
    }

    public static function tierFor(int $members): int
    {
        $tier = 0;
        foreach (self::TIERS as $t => $from) {
            if ($members >= $from) {
                $tier = $t;
            }
        }

        return $tier;
    }

    /**
     * The globe's markers: one per member country, on its main landmass, how bright (1 some, 2 many,
     * 3 the most), and when it appears in the wave that spreads from the Arab world outward (ms).
     *
     * @return array<int, array{code: string, at: array{0: float, 1: float}, tier: int, delay: int}>
     */
    public static function markers(): array
    {
        $points = self::points();
        $hub = [26.0, 38.0]; // between Egypt and the Gulf
        $out = [];
        foreach (self::counts()['countries'] as $code => $n) {
            if (! isset($points[$code]) || in_array($code, self::NOT_ON_GLOBE, true)) {
                continue;
            }
            $out[] = ['code' => $code, 'at' => $points[$code], 'tier' => self::tierFor($n), 'arc' => self::arc($hub, $points[$code])];
        }
        usort($out, fn (array $a, array $b) => $a['arc'] <=> $b['arc']);

        return array_map(fn (array $m) => [
            'code' => $m['code'], 'at' => $m['at'], 'tier' => $m['tier'],
            'delay' => (int) round(150 + 1500 * ($m['arc'] / M_PI) ** 0.85),
        ], $out);
    }

    /** Angle between two points on the globe (radians), for the wave's timing. */
    private static function arc(array $a, array $b): float
    {
        [$la1, $lo1, $la2, $lo2] = array_map('deg2rad', [$a[0], $a[1], $b[0], $b[1]]);
        $c = sin($la1) * sin($la2) + cos($la1) * cos($la2) * cos($lo2 - $lo1);

        return acos(max(-1.0, min(1.0, $c)));
    }

    /** A country's name in the page's language (resources/data/country-names.json), or its code if none is known. */
    public static function countryName(string $code, string $locale): string
    {
        static $names = null;
        $names ??= json_decode((string) file_get_contents(resource_path('data/country-names.json')), true);
        $code = strtoupper($code);

        return (string) ($names[$locale === 'ar' ? 'ar' : 'en'][$code] ?? $code);
    }

    /** @return array<string, array{0: float, 1: float}> latitude and longitude per country */
    private static function points(): array
    {
        static $points = null;

        return $points ??= json_decode((string) file_get_contents(resource_path('data/country-points.json')), true);
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
