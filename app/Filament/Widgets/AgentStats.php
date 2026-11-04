<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AgentConversationResource;
use App\Filament\Resources\AgentResource;
use App\Models\Agent;
use App\Models\AgentConversation;
use App\Services\Agents\AgentCalls;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Agents at a glance, above the list of agents (Agents → Agents): how many
 * there are and how many are live, the calls today, this week and this
 * month, the minutes and credits they used, and what Listen's own agents
 * took (their calls are on Listen). Not on the main dashboard.
 */
class AgentStats extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = '30s';

    protected ?string $heading = 'Agents at a glance';

    protected ?string $description = 'Every agent\'s calls, members\' and Listen\'s own. Minutes are talk time; credits are what members\' plans paid. Cost to us is an estimate from the tokens each call counted.';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        try {
            return $this->stats();
        } catch (\Throwable $e) {
            // The list of agents must open whatever goes wrong here.
            report($e);

            return [Stat::make('Figures', '—')->description('Could not be worked out: '.mb_substr($e->getMessage(), 0, 120))->color('danger')];
        }
    }

    /** @return array<int, Stat> */
    private function stats(): array
    {
        $now = now();
        $month = fn (): Builder => AgentConversation::query()->where('started_at', '>=', $now->copy()->subDays(30));

        $byStatus = Agent::query()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->map(fn ($n): int => (int) $n);
        $deleted = Agent::onlyTrashed()->count();
        $agents = (int) $byStatus->sum();
        $liveNow = AgentConversationResource::whereLiveNow(AgentConversation::query())->count();

        // The month's figures in one pass over its calls (the polling keeps this light).
        $wrap = fn (string $column): string => AgentConversation::query()->getQuery()->getGrammar()->wrap($column);
        $houseKeys = AgentResource::houseAgentKeys();
        $isHouse = $houseKeys === [] ? '1 = 0' : $wrap('agent_id').' in ('.implode(', ', array_map('intval', $houseKeys)).')';
        $sums = $month()
            ->selectRaw('count(*) as calls')
            ->selectRaw('sum(case when '.$wrap('started_at').' >= ? then 1 else 0 end) as today', [$now->copy()->startOfDay()])
            ->selectRaw('sum(case when '.$wrap('started_at').' >= ? then 1 else 0 end) as week', [$now->copy()->subDays(7)])
            ->selectRaw('sum(case when '.$wrap('started_at').' >= ? and '.$wrap('lead').' is not null then 1 else 0 end) as week_leads', [$now->copy()->subDays(7)])
            ->selectRaw('sum('.$wrap('duration_seconds').') as seconds')
            ->selectRaw('sum('.$wrap('billed_seconds').') as billed')
            ->selectRaw('sum('.$wrap('credits').') as credits')
            ->selectRaw('sum(case when '.$wrap('free').' = 1 then 1 else 0 end) as free_calls')
            ->selectRaw('sum(case when '.$wrap('status').' = ? then 1 else 0 end) as failed', [AgentConversation::FAILED])
            ->selectRaw('sum('.$wrap('cost_usd').') as cost')
            ->selectRaw('sum(case when '.$isHouse.' then 1 else 0 end) as house_calls')
            ->selectRaw('sum(case when '.$isHouse.' then '.$wrap('duration_seconds').' else 0 end) as house_seconds')
            ->toBase()
            ->first();
        $sum = fn (string $key): int => (int) ($sums->{$key} ?? 0);

        $today = $sum('today');
        $weekCount = $sum('week');
        $weekLeads = $sum('week_leads');
        $monthCount = $sum('calls');
        $seconds = $sum('seconds');
        $billed = $sum('billed');
        $credits = $sum('credits');
        $free = $sum('free_calls');
        $failed = $sum('failed');
        $cost = (float) ($sums->cost ?? 0);
        $houseCalls = $sum('house_calls');
        $houseSeconds = $sum('house_seconds');

        $monthChannels = $month()->selectRaw('channel, count(*) as aggregate')->groupBy('channel')->pluck('aggregate', 'channel')->map(fn ($n): int => (int) $n)->all();
        $perDay = $month()->selectRaw('date(started_at) as day, count(*) as aggregate')->groupBy('day')->pluck('aggregate', 'day');
        $daily = collect(range(29, 0))->map(fn (int $ago): int => (int) ($perDay[$now->copy()->subDays($ago)->toDateString()] ?? 0))->all();
        $rate = AgentCalls::creditsPerMinute();

        $statuses = [];
        foreach ([Agent::LIVE => 'live', Agent::READY => 'ready', Agent::DRAFT => 'being set up', Agent::PAUSED => 'paused'] as $status => $word) {
            if (($count = (int) ($byStatus[$status] ?? 0)) > 0) {
                $statuses[] = number_format($count).' '.$word;
            }
        }
        if ($deleted > 0) {
            $statuses[] = number_format($deleted).' deleted by their owner';
        }

        return [
            Stat::make('Agents', number_format($agents))
                ->description($statuses !== [] ? implode(' · ', $statuses) : 'None yet')
                ->descriptionIcon('heroicon-m-chat-bubble-left-right')
                ->color('primary'),
            Stat::make('Calls today', number_format($today))
                ->description($liveNow > 0 ? number_format($liveNow).' live right now' : 'None live right now')
                ->descriptionIcon('heroicon-m-signal')
                ->color($liveNow > 0 ? 'success' : 'gray'),
            Stat::make('Calls in 7 days', number_format($weekCount))
                ->description(number_format($weekLeads).' left customer details')
                ->descriptionIcon('heroicon-m-identification'),
            Stat::make('Calls in 30 days', number_format($monthCount))
                ->description(AgentConversationResource::channelBreakdown($monthChannels) ?? 'No calls')
                ->descriptionIcon('heroicon-m-phone')
                ->chart($daily)
                ->color('primary'),
            Stat::make('Minutes in 30 days', AgentResource::minutes($seconds))
                ->description('Billed '.AgentResource::minutes($billed).' · '.AgentResource::plural($free, 'free call').($failed > 0 ? ' · '.number_format($failed).' failed' : ''))
                ->descriptionIcon('heroicon-m-clock'),
            Stat::make('Credits in 30 days', number_format($credits))
                ->description('From members\' plans, at '.number_format($rate).' credits a minute')
                ->descriptionIcon('heroicon-m-credit-card'),
            Stat::make('Listen\'s own agents, 30 days', AgentResource::minutes($houseSeconds))
                ->description(AgentResource::plural($houseCalls, 'call').', on Listen (worth '.number_format(AgentCalls::creditsFor($houseSeconds)).' credits)')
                ->descriptionIcon('heroicon-m-home')
                ->color('warning'),
            Stat::make('Cost to us, 30 days', '$'.number_format($cost, 2))
                ->description($seconds > 0 ? '$'.number_format($cost / ($seconds / 60), 4).' a minute talked' : 'No calls')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('danger'),
        ];
    }
}
