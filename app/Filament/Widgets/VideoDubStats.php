<?php

namespace App\Filament\Widgets;

use App\Models\VideoDub;
use App\Support\VideoDubbing;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Dub my video at a glance, above the list of dubs (Video Dubbing → Dubs):
 * how many, free and paid, what members spent and what it cost us, how long
 * they waited and what fails. Not on the main dashboard.
 */
class VideoDubStats extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = '30s';

    protected ?string $heading = 'Dub my video';

    protected ?string $description = 'Last 30 days unless it says otherwise. Our cost is an estimate from the counted tokens and seconds (config listen.dubbing.prices).';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        try {
            return $this->stats();
        } catch (\Throwable $e) {
            // The list of dubs must open whatever goes wrong here.
            report($e);

            return [Stat::make('Figures', '—')->description('Could not be worked out: '.mb_substr($e->getMessage(), 0, 120))->color('danger')];
        }
    }

    /** @return array<int, Stat> */
    private function stats(): array
    {
        $since = now()->subDays(30);
        $month = VideoDub::query()->where('created_at', '>=', $since)->get(['id', 'user_id', 'status', 'is_free', 'video_seconds', 'reservation', 'report', 'created_at']);
        $today = $month->filter(fn (VideoDub $d): bool => $d->created_at >= now()->startOfDay());
        $done = $month->where('status', VideoDub::READY);
        $removed = $month->where('status', VideoDub::REMOVED);
        $failed = $month->where('status', VideoDub::FAILED);
        $finished = $done->count() + $failed->count();
        // A dub removed afterwards was still made: it cost us all the same.
        $costs = $done->merge($removed)->map(fn (VideoDub $d): float => VideoDubbing::cost($d->report)['total'] ?? 0.0);
        $freeCost = $done->where('is_free', true)->sum(fn (VideoDub $d): float => VideoDubbing::cost($d->report)['total'] ?? 0.0);
        $credits = $month->sum(fn (VideoDub $d): int => is_array($d->reservation) ? (int) $d->reservation['credits'] : 0);
        $waits = $done->map(fn (VideoDub $d): float => (float) ($d->report['total_seconds'] ?? 0))->filter();
        $freeCap = (int) config('listen.dubbing.free_per_day', 50);
        $freeToday = $today->where('is_free', true)->whereNotIn('status', VideoDub::GIVEN_BACK)->count();
        $daily = collect(range(29, 0))->map(fn (int $ago): int => $month->filter(fn (VideoDub $d): bool => $d->created_at->isSameDay(now()->subDays($ago)))->count())->all();

        // Members who had their free dub and then paid for one: what the free dub is for.
        $freeUsers = VideoDub::query()->where('is_free', true)->whereNotIn('status', VideoDub::GIVEN_BACK)->distinct()->pluck('user_id');
        $converted = $freeUsers->isEmpty() ? 0 : VideoDub::query()->whereIn('user_id', $freeUsers)->whereNotNull('reservation')->distinct()->count('user_id');

        return [
            Stat::make('Dubs today', number_format($today->count()))
                ->description($today->where('is_free', true)->count().' free · '.$today->where('is_free', false)->count().' paid · '.number_format($month->count()).' in 30 days')
                ->descriptionIcon('heroicon-m-film')
                ->chart($daily)
                ->color('primary'),
            Stat::make('Free dubs today', $freeToday.' / '.$freeCap)
                ->description('The most a day for the whole site')
                ->descriptionIcon('heroicon-m-gift')
                ->color($freeCap > 0 && $freeToday >= $freeCap ? 'danger' : 'gray'),
            Stat::make('Video dubbed', number_format($month->sum('video_seconds') / 60, 1).' min')
                ->description(number_format($month->where('is_free', false)->sum('video_seconds') / 60, 1).' min paid · '.number_format($month->where('is_free', true)->sum('video_seconds') / 60, 1).' min free')
                ->descriptionIcon('heroicon-m-clock'),
            Stat::make('Credits members spent', number_format($credits))
                ->description('= '.number_format($credits / 900, 1).' voice minutes from their plans')
                ->descriptionIcon('heroicon-m-credit-card'),
            Stat::make('Cost to us', '$'.number_format($costs->sum(), 2))
                ->description('$'.number_format($freeCost, 2).' of it on free dubs · $'.number_format($costs->avg() ?? 0, 4).' a dub')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('danger'),
            Stat::make('Failed', $finished > 0 ? round($failed->count() / $finished * 100).'%' : '—')
                ->description($failed->count().' of '.$finished.' finished dubs · credits given back'.($removed->isNotEmpty() ? ' · '.$removed->count().' removed by an admin' : ''))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($finished > 0 && $failed->count() / $finished > 0.2 ? 'danger' : 'gray'),
            Stat::make('Average wait', $waits->isEmpty() ? '—' : number_format($waits->avg(), 0).' s')
                ->description($waits->isEmpty() ? 'From upload to ready' : 'Longest '.number_format($waits->max(), 0).' s')
                ->descriptionIcon('heroicon-m-bolt'),
            Stat::make('Members dubbing', number_format($month->pluck('user_id')->unique()->count()))
                ->description($converted.' of '.$freeUsers->count().' who had a free dub went on to pay for one (all time)')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('success'),
        ];
    }
}
