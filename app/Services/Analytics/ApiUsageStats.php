<?php

namespace App\Services\Analytics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Text-to-speech API usage and cost across every source that calls a provider:
 * member generations, admin generations and the public demo.
 *
 * Cost per generation is the recorded estimate when the app logged one (> 0),
 * otherwise an estimate from config/analytics.php (per character for Google
 * Cloud voices, per token for Gemini). Cache hits cost nothing.
 */
class ApiUsageStats
{
    public function __construct(
        private readonly string $driver,
        private readonly Carbon $now,
        private readonly Carbon $since,
    ) {}

    public function build(): array
    {
        $groups = $this->groups();
        $last30 = $this->now->copy()->subDays(30)->startOfDay()->format('Y-m-d');
        $inPeriod = fn (array $g) => $g['month'] >= $this->since->format('Y-m');
        $recent = fn (array $g) => $g['day'] >= $last30;

        return [
            'totals_30d' => $this->totals($groups->filter($recent)),
            'totals_period' => $this->totals($groups->filter($inPeriod)),
            'totals_all_time' => $this->totals($groups),
            'by_model_30d' => $this->byModel($groups->filter($recent)),
            'by_model_period' => $this->byModel($groups->filter($inPeriod)),
            'monthly' => $this->monthly($groups->filter($inPeriod)),
            'chars_by_plan_30d' => $this->charsByPlan(),
            'quota_chars_by_month' => $this->quotaCharsByMonth(),
        ];
    }

    /**
     * Usage grouped by day, source, provider, model and voice: small enough to
     * post-process in PHP, where the per-model cost rules live.
     */
    private function groups(): Collection
    {
        $rows = collect();
        $chars = fn (string $table) => "coalesce({$table}.billable_chars, {$this->charLength($table.'.input_text')})";

        $ag = 'audio_generations';
        $member = DB::table($ag)
            ->leftJoin('users', 'users.id', '=', "{$ag}.user_id")
            ->selectRaw(implode(', ', [
                $this->day("{$ag}.created_at").' as day',
                Schema::hasColumn('users', 'is_admin')
                    ? "case when users.is_admin = 1 then 'admin' else 'members' end as source"
                    : "'members' as source",
                "coalesce({$ag}.tts_provider, 'unknown') as provider",
                "coalesce({$ag}.tts_model, '') as model",
                "coalesce({$ag}.voice_name, '') as voice",
                'count(*) as requests',
                "sum(case when {$ag}.status = 'failed' then 1 else 0 end) as failed",
                "sum(case when {$ag}.translate then 1 else 0 end) as translated",
                "coalesce(sum({$chars($ag)}), 0) as chars",
                "coalesce(sum({$ag}.duration_seconds), 0) as seconds",
                "coalesce(sum({$ag}.tts_cost_estimate_usd), 0) as recorded_cost",
                "coalesce(sum({$ag}.tts_latency_ms), 0) as latency_sum",
                "sum(case when {$ag}.tts_latency_ms is null then 0 else 1 end) as latency_n",
            ]))
            ->groupByRaw('1, 2, 3, 4, 5')
            ->get();
        $rows = $rows->concat($member);

        if (Schema::hasTable('demo_voice_generations')) {
            $dg = 'demo_voice_generations';
            $demo = DB::table($dg)
                ->selectRaw(implode(', ', [
                    $this->day("{$dg}.created_at").' as day',
                    "'demo' as source",
                    "coalesce({$dg}.voice_provider, 'unknown') as provider",
                    "coalesce({$dg}.tts_model, '') as model",
                    "coalesce({$dg}.voice_name, '') as voice",
                    'count(*) as requests',
                    '0 as failed',
                    "sum(case when {$dg}.translate then 1 else 0 end) as translated",
                    "coalesce(sum({$chars($dg)}), 0) as chars",
                    "coalesce(sum({$dg}.duration_seconds), 0) as seconds",
                    "coalesce(sum({$dg}.tts_cost_estimate_usd), 0) as recorded_cost",
                    "coalesce(sum({$dg}.tts_latency_ms), 0) as latency_sum",
                    "sum(case when {$dg}.tts_latency_ms is null then 0 else 1 end) as latency_n",
                ]))
                ->groupByRaw('1, 2, 3, 4, 5')
                ->get();
            $rows = $rows->concat($demo);
        }

        return $rows->map(function ($r) {
            $g = [
                'day' => (string) $r->day,
                'month' => substr((string) $r->day, 0, 7),
                'source' => (string) $r->source,
                'provider' => (string) $r->provider,
                'model' => (string) $r->model,
                'voice' => (string) $r->voice,
                'requests' => (int) $r->requests,
                'failed' => (int) $r->failed,
                'translated' => (int) $r->translated,
                'chars' => (int) $r->chars,
                'seconds' => (int) $r->seconds,
                'recorded_cost' => (float) $r->recorded_cost,
                'latency_sum' => (int) $r->latency_sum,
                'latency_n' => (int) $r->latency_n,
            ];
            [$g['cost'], $g['cost_basis']] = $this->cost($g);
            $g['label'] = $this->modelLabel($g);

            return $g;
        })->values();
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function cost(array $g): array
    {
        if ($g['provider'] === 'cache') {
            return [0.0, 'cache'];
        }
        if ($g['recorded_cost'] > 0) {
            return [$g['recorded_cost'], 'recorded'];
        }

        if ($g['provider'] === 'gemini') {
            $cfg = config('analytics.gemini_tts');
            if ($cfg['free_tier']) {
                return [0.0, 'free tier'];
            }
            $rates = str_contains(strtolower($g['model']), 'pro') ? $cfg['pro'] : $cfg['flash'];
            $outputTokens = $g['seconds'] * $cfg['audio_tokens_per_second'];
            $inputTokens = $g['chars'] / max(1, $cfg['chars_per_text_token']);

            return [
                ($outputTokens * $rates['output_per_million_tokens'] + $inputTokens * $rates['input_per_million_tokens']) / 1_000_000,
                'estimated',
            ];
        }

        if (str_starts_with($g['provider'], 'google')) {
            return [$g['chars'] / 1_000_000 * $this->googleRate($g['voice']), 'estimated'];
        }

        return [0.0, 'unknown'];
    }

    private function googleRate(string $voice): float
    {
        $voice = strtolower($voice);
        foreach (config('analytics.google_cloud_tts.per_million_chars') as $pattern => $rate) {
            if ($pattern !== '' && str_contains($voice, $pattern)) {
                return (float) $rate;
            }
        }

        return (float) config('analytics.google_cloud_tts.default_per_million_chars');
    }

    private function modelLabel(array $g): string
    {
        if ($g['provider'] === 'cache') {
            return 'Cache (re-used audio)';
        }
        if (str_starts_with($g['provider'], 'google')) {
            $voice = strtolower($g['voice']);
            foreach (array_keys(config('analytics.google_cloud_tts.per_million_chars')) as $pattern) {
                if (str_contains($voice, $pattern)) {
                    return 'Google Cloud TTS · '.ucwords(str_replace('-', ' ', $pattern));
                }
            }

            return 'Google Cloud TTS · Standard';
        }
        if ($g['provider'] === 'gemini') {
            return 'Gemini · '.($g['model'] !== '' ? $g['model'] : 'unknown model');
        }

        return trim($g['provider'].' '.$g['model']);
    }

    private function totals(Collection $groups): array
    {
        $paid = $groups->where('provider', '!=', 'cache');
        $bySource = fn (string $source) => $groups->where('source', $source);

        return [
            'requests' => $groups->sum('requests'),
            'api_requests' => $paid->sum('requests'),
            'cache_hits' => $groups->where('provider', 'cache')->sum('requests'),
            'failed' => $groups->sum('failed'),
            'translated' => $groups->sum('translated'),
            'chars' => $groups->sum('chars'),
            'api_chars' => $paid->sum('chars'),
            'audio_minutes' => round($groups->sum('seconds') / 60, 1),
            'cost' => round($groups->sum('cost'), 2),
            'recorded_cost' => round($groups->sum('recorded_cost'), 2),
            'estimated_share' => $this->ratio($groups->where('cost_basis', 'estimated')->sum('cost'), $groups->sum('cost')),
            'member_cost' => round($bySource('members')->sum('cost'), 2),
            'admin_cost' => round($bySource('admin')->sum('cost'), 2),
            'demo_cost' => round($bySource('demo')->sum('cost'), 2),
            'member_chars' => $bySource('members')->sum('chars'),
            'demo_chars' => $bySource('demo')->sum('chars'),
            'admin_chars' => $bySource('admin')->sum('chars'),
            'cost_per_1k_chars' => $paid->sum('chars') > 0 ? round($paid->sum('cost') / $paid->sum('chars') * 1000, 4) : 0,
            'cost_per_audio_minute' => $groups->sum('seconds') > 0 ? round($groups->sum('cost') / ($groups->sum('seconds') / 60), 4) : 0,
            'cache_hit_rate' => $this->ratio($groups->where('provider', 'cache')->sum('requests'), $groups->sum('requests')),
        ];
    }

    private function byModel(Collection $groups): array
    {
        return $groups->groupBy('label')->map(fn (Collection $rows, string $label) => [
            'model' => $label,
            'requests' => $rows->sum('requests'),
            'member_requests' => $rows->where('source', 'members')->sum('requests'),
            'demo_requests' => $rows->where('source', 'demo')->sum('requests'),
            'chars' => $rows->sum('chars'),
            'audio_minutes' => round($rows->sum('seconds') / 60, 1),
            'avg_latency_s' => $rows->sum('latency_n') > 0 ? round($rows->sum('latency_sum') / $rows->sum('latency_n') / 1000, 1) : null,
            'failure_rate' => $this->ratio($rows->sum('failed'), $rows->sum('requests')),
            'cost_usd' => round($rows->sum('cost'), 2),
            'cost_basis' => $rows->pluck('cost_basis')->unique()->sort()->implode(' + '),
            'cost_per_1k_chars' => $rows->sum('chars') > 0 ? round($rows->sum('cost') / $rows->sum('chars') * 1000, 4) : 0,
        ])->sortByDesc('requests')->values()->all();
    }

    private function monthly(Collection $groups): array
    {
        $months = [];
        for ($m = $this->since->copy(); $m <= $this->now; $m->addMonth()) {
            $months[$m->format('Y-m')] = [
                'month' => $m->format('Y-m'),
                'requests' => 0,
                'chars' => 0,
                'audio_minutes' => 0.0,
                'cost_total' => 0.0,
                'cost_google' => 0.0,
                'cost_gemini' => 0.0,
                'cost_other' => 0.0,
                'cost_members' => 0.0,
                'cost_demo' => 0.0,
                'cost_admin' => 0.0,
            ];
        }

        foreach ($groups as $g) {
            if (! isset($months[$g['month']])) {
                continue;
            }
            $row = &$months[$g['month']];
            $row['requests'] += $g['requests'];
            $row['chars'] += $g['chars'];
            $row['audio_minutes'] += $g['seconds'] / 60;
            $row['cost_total'] += $g['cost'];
            $providerKey = match (true) {
                str_starts_with($g['provider'], 'google') => 'cost_google',
                $g['provider'] === 'gemini' => 'cost_gemini',
                default => 'cost_other',
            };
            $row[$providerKey] += $g['cost'];
            $row['cost_'.($g['source'] === 'members' ? 'members' : $g['source'])] += $g['cost'];
            unset($row);
        }

        return array_values(array_map(function (array $row) {
            foreach ($row as $k => $v) {
                if (is_float($v)) {
                    $row[$k] = round($v, $k === 'audio_minutes' ? 1 : 2);
                }
            }

            return $row;
        }, $months));
    }

    /**
     * Characters members generated in the last 30 days, split by their current plan.
     */
    private function charsByPlan(): array
    {
        $ag = 'audio_generations';
        $query = DB::table($ag)
            ->join('users', 'users.id', '=', "{$ag}.user_id")
            ->leftJoin('subscriptions', fn ($j) => $j->on('subscriptions.user_id', '=', "{$ag}.user_id")->where('subscriptions.status', 'active'))
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where("{$ag}.created_at", '>=', $this->now->copy()->subDays(30));
        if (Schema::hasColumn('users', 'is_admin')) {
            $query->where('users.is_admin', false);
        }

        return $query
            ->selectRaw("coalesce(plans.name, 'No plan') as k, coalesce(sum(coalesce({$ag}.billable_chars, {$this->charLength($ag.'.input_text')})), 0) as n")
            ->groupByRaw("coalesce(plans.name, 'No plan')")
            ->orderByDesc('n')
            ->pluck('n', 'k')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Characters counted against plan quotas (usage_counters), per month.
     */
    private function quotaCharsByMonth(): array
    {
        if (! Schema::hasTable('usage_counters')) {
            return [];
        }

        return DB::table('usage_counters')
            ->where('period_ym', '>=', $this->since->format('Y-m'))
            ->selectRaw('period_ym as k, sum(total_chars) as chars, count(*) as members, sum(total_requests) as requests')
            ->groupBy('period_ym')
            ->orderBy('period_ym')
            ->get()
            ->map(fn ($r) => [
                'month' => $r->k,
                'chars' => (int) $r->chars,
                'members_with_usage' => (int) $r->members,
                'requests' => (int) $r->requests,
            ])
            ->all();
    }

    private function day(string $column): string
    {
        return match ($this->driver) {
            'sqlite' => "strftime('%Y-%m-%d', {$column})",
            'pgsql' => "to_char({$column}, 'YYYY-MM-DD')",
            default => "DATE_FORMAT({$column}, '%Y-%m-%d')",
        };
    }

    private function charLength(string $column): string
    {
        return match ($this->driver) {
            'mysql', 'mariadb' => "char_length({$column})",
            default => "length({$column})",
        };
    }

    private function ratio(int|float $part, int|float $whole): float
    {
        return $whole > 0 ? round($part / $whole, 4) : 0.0;
    }
}
