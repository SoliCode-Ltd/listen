<?php

namespace App\Filament\Widgets;

use App\Models\Payment;
use App\Services\Analytics\BusinessStatsService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;

/**
 * How members paid in the dashboard's period: card, Apple Pay, Google Pay,
 * InstaPay, Vodafone Cash (Billing → Payments). Admins' own payments are left out.
 */
class BusinessPaymentMethods extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = -28;

    protected static ?string $pollingInterval = null;

    protected function getHeading(): ?string
    {
        return 'How members pay (last '.$this->months().' months)';
    }

    /** Full rows where the cards allow it: never one card alone on a row of four. */
    protected function getColumns(): int
    {
        $cards = count($this->getCachedStats());

        return match (true) {
            $cards <= 4 => max(1, $cards),
            $cards <= 6 => 3,
            default => 4,
        };
    }

    protected function getStats(): array
    {
        $payments = rescue(fn () => Payment::query()
            ->where('paid_at', '>=', now()->subMonthsNoOverflow($this->months()))
            ->whereDoesntHave('user', fn ($user) => $user->where('is_admin', true))
            ->get(['method', 'amount_minor', 'refunded_minor', 'currency']), collect(), false);

        if ($payments->isEmpty()) {
            return [
                Stat::make('Payments', '0')
                    ->description('None in this period yet. Billing → Payments → "Fetch past payments" adds the older ones.')
                    ->descriptionIcon('heroicon-m-banknotes'),
            ];
        }

        $total = $payments->count();
        $stats = [
            Stat::make('Payments', number_format($total))
                ->description(self::amounts($payments).' after refunds')
                ->descriptionIcon('heroicon-m-banknotes'),
        ];

        foreach ($payments->groupBy('method')->sortByDesc(fn (Collection $group) => $group->count()) as $method => $group) {
            $stats[] = Stat::make(Payment::methodLabel((string) $method), number_format($group->count()))
                ->description(number_format($group->count() / $total * 100, 0).'% of payments · '.self::amounts($group))
                ->color(Payment::methodColor((string) $method) === 'gray' ? null : Payment::methodColor((string) $method));
        }

        return $stats;
    }

    private function months(): int
    {
        return BusinessStatsService::normalizeMonths($this->filters['months'] ?? null);
    }

    /** "USD 37.40 · EGP 995.00": never added up across currencies. */
    private static function amounts(Collection $payments): string
    {
        return $payments->groupBy('currency')
            ->map(fn (Collection $group, string $currency) => $currency.' '.number_format($group->sum(fn (Payment $p) => $p->netMinor()) / 100, 2))
            ->sortKeys()
            ->implode(' · ');
    }
}
