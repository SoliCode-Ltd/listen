<?php

namespace App\Filament\Widgets;

use App\Models\AudioGeneration;
use App\Models\DemoVoiceGeneration;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voice;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $totalAudioGenerations =
            AudioGeneration::count()
            + DemoVoiceGeneration::count();

        return [
            Stat::make('Total Users', User::count())
                ->description('All registered users')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary')
                ->chart($this->getUserGrowthChart()),
            Stat::make(
                'Active Subscriptions',
                Subscription::query()
                    ->where('status', 'active')
                    ->whereHas('user', fn (Builder $query) => $query->where('is_blocked', false))
                    ->distinct('user_id')
                    ->count('user_id')
            )
                ->description('Currently active')
                ->descriptionIcon('heroicon-m-credit-card')
                ->color('success'),
            Stat::make('Audio Generations', $totalAudioGenerations)
                ->description('Total (demo + user)')
                ->descriptionIcon('heroicon-m-musical-note')
                ->color('warning'),
            Stat::make('Active Voices', Voice::where('is_active', true)->count())
                ->description('Available TTS voices')
                ->descriptionIcon('heroicon-m-speaker-wave')
                ->color('info'),
        ];
    }

    protected function getUserGrowthChart(): array
    {
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $data[] = User::whereDate('created_at', now()->subDays($i))->count();
        }
        return $data;
    }
}
