<?php

namespace App\Filament\Widgets;

use App\Models\AudioGeneration;
use App\Models\DemoVoiceGeneration;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voice;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $currentMonthRange = [now()->startOfMonth(), now()->endOfMonth()];
        $monthlyAudioGenerations =
            AudioGeneration::whereBetween('created_at', $currentMonthRange)->count()
            + DemoVoiceGeneration::whereBetween('created_at', $currentMonthRange)->count();

        return [
            Stat::make('Total Users', User::count())
                ->description('All registered users')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary')
                ->chart($this->getUserGrowthChart()),
            Stat::make('Active Subscriptions', Subscription::where('status', 'active')->count())
                ->description('Currently active')
                ->descriptionIcon('heroicon-m-credit-card')
                ->color('success'),
            Stat::make('Audio Generations', $monthlyAudioGenerations)
                ->description('This month')
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
