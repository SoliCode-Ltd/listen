<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\UsageCounterResource;
use App\Filament\Widgets\Concerns\ReadsUsageFilters;
use App\Models\User;
use App\Services\Analytics\UsageInsights;
use App\Support\PlanCostEstimate;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * The month's totals on the Monthly usage page, for the month and plan picked
 * in the table's filters, each compared with the month before. Counted from the
 * generations made in the calendar month (pack characters included), like the
 * chart and the lists below the table; gifts come from the usage rows.
 */
class MonthlyUsageStats extends BaseWidget
{
    use ReadsUsageFilters;

    protected static bool $isDiscovered = false;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $ym = $this->usageMonth();
        $previous = $ym === null ? null : Carbon::createFromFormat('!Y-m', $ym)->subMonth()->format('Y-m');
        $insights = app(UsageInsights::class);
        $made = $insights->summary($this->usageGenerations($ym));
        $now = ['members' => $made['members'], 'generations' => $made['made'], 'chars' => $made['chars'], 'seconds' => $made['seconds']];
        $before = null;
        if ($previous !== null) {
            $was = $insights->summary($this->usageGenerations($previous));
            $before = ['members' => $was['members'], 'generations' => $was['made'], 'chars' => $was['chars'], 'seconds' => $was['seconds']];
        }
        $gifted = (int) $this->usageCounters($ym)->sum('bonus_chars');

        $paying = UsageCounterResource::onPlan(User::query()->whereIn('id', UsageCounterResource::payingMemberIds()), $this->usagePlan(), 'id');
        if ($this->usageWithoutAdmins()) {
            $paying->where('is_admin', false);
        }
        $payingCount = (clone $paying)->count();
        $payingActive = $this->usageGenerations($ym)->whereIn('audio_generations.user_id', (clone $paying)->select('id'))
            ->distinct()->count('audio_generations.user_id');
        $idle = $payingCount - $payingActive;

        $failRate = $made['attempts'] > 0 ? $made['failed'] / $made['attempts'] : 0;
        $cost = PlanCostEstimate::forChars($now['chars'])['cost'];

        return [
            $this->trend(Stat::make('Members who generated', number_format($now['members'])), $now['members'], $before['members'] ?? null, $previous)
                ->description($this->trendText($now['members'], $before['members'] ?? null, $previous) ?? 'In '.$this->usageMonthLabel()),
            Stat::make('Paying members who generated', number_format($payingActive).' of '.number_format($payingCount))
                ->description($payingCount === 0 ? 'No one on a paid plan in this selection' : ($idle > 0 ? number_format($idle).' paying now made nothing in '.$this->usageMonthLabel() : 'Every paying member made something'))
                ->color($idle > 0 ? 'warning' : 'success'),
            Stat::make('First-time creators', $ym === null ? '—' : number_format($insights->firstTimers($ym, $this->usageWithoutAdmins())))
                ->description('Made their first audio ever this month'),
            $this->trend(Stat::make('Generations', number_format($now['generations'])), $now['generations'], $before['generations'] ?? null, $previous)
                ->description(($this->trendText($now['generations'], $before['generations'] ?? null, $previous) ?? 'In '.$this->usageMonthLabel())
                    .($now['members'] > 0 ? ' · ≈ '.number_format($now['generations'] / $now['members'], 1).' per member' : '')),
            $this->trend(Stat::make('Credits used', number_format($now['chars'])), $now['chars'], $before['chars'] ?? null, $previous)
                ->description(($this->trendText($now['chars'], $before['chars'] ?? null, $previous) ?? 'In '.$this->usageMonthLabel())
                    .' · ≈ $'.number_format($cost, 2).' voice cost at most'),
            $this->trend(Stat::make('Audio made', $this->duration($now['seconds'])), $now['seconds'], $before['seconds'] ?? null, $previous)
                ->description(($this->trendText($now['seconds'], $before['seconds'] ?? null, $previous) ?? 'Real length of the audio')
                    .($now['members'] > 0 ? ' · ≈ '.$this->duration((int) round($now['seconds'] / $now['members'])).' per member' : '')),
            Stat::make('Failed generations', number_format($made['failed']))
                ->description(number_format($failRate * 100, 1).'% of '.number_format($made['attempts']).' tries · credits are given back')
                ->color($failRate > 0.05 ? 'danger' : ($made['failed'] > 0 ? 'warning' : 'success')),
            Stat::make('Credits gifted', number_format($gifted))
                ->description('Added to members\' allowances'),
        ];
    }

    /** "+20% vs September", or null when there is no month to compare with. */
    private function trendText(int $now, ?int $before, ?string $previous): ?string
    {
        if ($previous === null || $before === null) {
            return null;
        }
        $month = $this->usageMonthLabel($previous);
        if ($before === 0) {
            return $now > 0 ? "None in {$month}" : "Same as {$month}";
        }
        $change = (int) round(($now - $before) / $before * 100);

        return $change === 0 ? "Same as {$month}" : ($change > 0 ? '+' : '−').abs($change)."% vs {$month}";
    }

    private function trend(Stat $stat, int $now, ?int $before, ?string $previous): Stat
    {
        if ($previous === null || $before === null || $now === $before) {
            return $stat;
        }

        return $stat
            ->descriptionIcon($now > $before ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($now > $before ? 'success' : 'danger');
    }

    private function duration(int $seconds): string
    {
        if ($seconds < 3600) {
            return number_format($seconds / 60, 1).' min';
        }

        return intdiv($seconds, 3600).' h '.intdiv($seconds % 3600, 60).' min';
    }
}
