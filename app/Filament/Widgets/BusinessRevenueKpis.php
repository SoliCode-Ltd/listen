<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesBusinessStats;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BusinessRevenueKpis extends BaseWidget
{
    use UsesBusinessStats;

    protected static ?int $sort = -29;

    protected static ?string $pollingInterval = null;

    protected ?string $heading = 'Revenue & conversion';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $s = $this->stats();
        $revenue = $s['revenue'] ?? [];
        $trials = $s['trials'] ?? [];
        $manual = $s['manual_payments'] ?? [];
        $monthly = collect($s['monthly'] ?? []);
        $mrr = (int) ($revenue['mrr_cents'] ?? 0);
        $stripe = $revenue['stripe_by_status'] ?? [];

        $manualTotals = collect($manual['approved_by_currency'] ?? [])
            ->map(fn ($amount, $currency) => $currency.' '.number_format($amount, 2))
            ->implode(' · ');

        return [
            Stat::make('Est. MRR', static::money($mrr))
                ->description('ARR '.static::money($mrr * 12).' · Stripe list prices')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),
            Stat::make('Paying Stripe subscriptions', number_format($revenue['paying_stripe'] ?? 0))
                ->description('ARPU '.static::money($revenue['arpu_cents'] ?? 0).' / month')
                ->descriptionIcon('heroicon-m-credit-card')
                ->chart($monthly->pluck('stripe_started')->all()),
            Stat::make('Members on paid plans', number_format($revenue['members_on_paid_plans'] ?? 0))
                ->description(static::percent($revenue['paid_conversion'] ?? 0).' free-to-paid conversion · Stripe + manual')
                ->descriptionIcon('heroicon-m-arrow-up-circle'),
            Stat::make('Subscriptions ended (30 days)', number_format($revenue['canceled_30d'] ?? 0))
                ->description('Stripe subscriptions that ended')
                ->descriptionIcon('heroicon-m-arrow-down-circle')
                ->color(($revenue['canceled_30d'] ?? 0) > 0 ? 'danger' : 'gray'),
            Stat::make('Open trials', number_format($trials['open'] ?? 0))
                ->description(number_format($trials['total'] ?? 0).' trials started in total')
                ->descriptionIcon('heroicon-m-clock'),
            Stat::make('Trial conversion', static::percent($trials['conversion'] ?? 0))
                ->description(number_format($trials['paid'] ?? 0).' paid · '.static::money($trials['revenue_cents'] ?? 0).' collected')
                ->descriptionIcon('heroicon-m-sparkles'),
            Stat::make('Manual payments approved', $manualTotals !== '' ? $manualTotals : '0')
                ->description(number_format($manual['pending'] ?? 0).' waiting for review')
                ->descriptionIcon('heroicon-m-building-library')
                ->color(($manual['pending'] ?? 0) > 0 ? 'warning' : 'gray'),
            Stat::make('Stripe subscriptions in trial', number_format($stripe['trialing'] ?? 0))
                ->description(collect($stripe)->except('trialing')
                    ->map(fn ($n, $status) => number_format($n).' '.static::stripeStatusLabel($status))
                    ->implode(' · ') ?: 'No other Stripe subscriptions')
                ->descriptionIcon('heroicon-m-clock')
                ->color('info'),
        ];
    }
}
