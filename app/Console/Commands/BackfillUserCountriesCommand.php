<?php

namespace App\Console\Commands;

use App\Services\LocalizedPricingService;
use App\Support\Countries;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills users.country_code for existing members from the IP they registered
 * (or last signed in) with. Each distinct IP is looked up once; only the
 * country is stored and no IP is printed. Safe to stop and run again.
 */
class BackfillUserCountriesCommand extends Command
{
    protected $signature = 'users:backfill-country
        {--limit=0 : Look up at most this many distinct IP addresses (0 = all)}
        {--sleep=250 : Pause between lookups, in milliseconds, to respect the free lookup services}
        {--dry-run : Show what would be done without saving}';

    protected $description = 'Fill in the country of existing members from their stored sign-up IP';

    public function handle(LocalizedPricingService $pricing): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $sleep = max(0, (int) $this->option('sleep'));
        $dryRun = (bool) $this->option('dry-run');

        $missing = DB::table('users')->whereNull('country_code')->count();
        $this->info("Members without a country: {$missing}");

        $ips = DB::table('users')
            ->whereNull('country_code')
            ->selectRaw('coalesce(nullif(registration_ip, \'\'), last_login_ip) as ip, count(*) as members')
            ->groupByRaw('coalesce(nullif(registration_ip, \'\'), last_login_ip)')
            ->havingRaw('coalesce(nullif(registration_ip, \'\'), last_login_ip) is not null')
            ->orderByDesc('members')
            ->get();

        if ($limit > 0) {
            $ips = $ips->take($limit);
        }

        $this->info("Distinct IP addresses to look up: {$ips->count()}".($dryRun ? ' (dry run)' : ''));
        $found = [];
        $updated = 0;
        $unresolved = 0;
        $bar = $this->output->createProgressBar($ips->count());

        foreach ($ips as $row) {
            $country = $pricing->countryForIp((string) $row->ip);
            if ($country === null) {
                $unresolved++;
            } else {
                $found[$country] = ($found[$country] ?? 0) + (int) $row->members;
                if (! $dryRun) {
                    $updated += DB::table('users')
                        ->whereNull('country_code')
                        ->where(fn ($q) => $q->where('registration_ip', $row->ip)
                            ->orWhere(fn ($q) => $q->where(fn ($q) => $q->whereNull('registration_ip')->orWhere('registration_ip', ''))
                                ->where('last_login_ip', $row->ip)))
                        ->update(['country_code' => $country]);
                }
            }
            $bar->advance();
            if ($sleep > 0) {
                usleep($sleep * 1000);
            }
        }
        $bar->finish();
        $this->newLine(2);

        arsort($found);
        $this->table(['Country', 'Members'], collect($found)->take(15)
            ->map(fn ($n, $code) => [Countries::name($code)." ({$code})", $n])->values()->all());
        $this->info(($dryRun ? 'Would update' : 'Updated').' '.($dryRun ? array_sum($found) : $updated).' members; '
            ."{$unresolved} IP addresses had no country (private address or lookup unavailable).");

        return self::SUCCESS;
    }
}
