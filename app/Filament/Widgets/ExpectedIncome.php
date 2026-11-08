<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesBusinessStats;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * The income to expect each month from card subscriptions that will renew:
 * active, not set to cancel, at what each really charges (after discounts),
 * yearly plans at one twelfth, after Stripe's fees. Read from Stripe; it
 * refreshes on its own when Stripe reports a change, and every few minutes.
 */
class ExpectedIncome extends BaseWidget
{
    use UsesBusinessStats;

    protected static ?int $sort = -31;

    protected static ?string $pollingInterval = '120s';

    /** The stats as usual, and the popup listing who cancelled (cancelledMembers()). */
    protected static string $view = 'filament.widgets.expected-income';

    public const CANCELLED_MODAL = 'cancelled-members';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getHeading(): ?string
    {
        return 'Expected monthly income';
    }

    protected function getDescription(): ?string
    {
        $revenue = $this->stats()['revenue'] ?? [];
        $recurring = $revenue['recurring'] ?? null;
        if (! $recurring) {
            return 'Stripe could not be read just now, so this is not shown. It is tried again in two minutes.';
        }

        return 'Card subscriptions from Stripe, at what each really charges after discounts. Updated '
            .Carbon::parse($recurring['generated_at'])->diffForHumans().'.';
    }

    protected function getStats(): array
    {
        $recurring = $this->stats()['revenue']['recurring'] ?? null;
        if (! $recurring) {
            return [
                Stat::make('Expected monthly income (after fees)', '—')
                    ->description('Waiting for Stripe')
                    ->descriptionIcon('heroicon-m-clock')
                    ->color('gray'),
            ];
        }

        $kinds = $recurring['kinds'];
        $expected = $recurring['expected'];
        $rate = $recurring['fees']['rate'];
        $due = $recurring['due_30d'];

        return [
            Stat::make('Expected monthly income (after fees)', static::amounts($expected['net_cents']))
                ->description(sprintf(
                    '%s renewing %s · %s before %s',
                    number_format($expected['count']),
                    $expected['count'] === 1 ? 'subscription' : 'subscriptions',
                    static::amounts($expected['gross_cents']),
                    $rate === null ? 'card fees (none charged yet)' : 'card fees of '.static::percent($rate),
                ))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),
            Stat::make('Due in the next 30 days (after fees)', static::amounts($due['net_cents']))
                ->description(number_format($due['count']).' renewals due · yearly plans only in their renewal month')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('info'),
            Stat::make('Cancelled, still in paid time', $kinds['cancelling']['count'] > 0
                ? new HtmlString(e(number_format($kinds['cancelling']['count'])).' <span class="ms-2 align-middle text-sm font-medium text-primary-600 underline underline-offset-4 dark:text-primary-400">See who</span>')
                : '0')
                ->description(static::amounts($kinds['cancelling']['monthly_cents']).' a month stops when their paid time ends')
                ->descriptionIcon('heroicon-m-arrow-down-circle')
                ->color($kinds['cancelling']['count'] > 0 ? 'warning' : 'gray')
                ->extraAttributes($kinds['cancelling']['count'] > 0 ? [
                    'role' => 'button',
                    'tabindex' => '0',
                    'aria-label' => 'See the members who cancelled',
                    'class' => 'cursor-pointer transition hover:ring-2 hover:ring-primary-500/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500',
                    'x-on:click' => "\$dispatch('open-modal', { id: '".self::CANCELLED_MODAL."' })",
                    'x-on:keydown.enter.prevent' => "\$dispatch('open-modal', { id: '".self::CANCELLED_MODAL."' })",
                ] : []),
            Stat::make('Not counted yet', sprintf('%s in trial · %s overdue', number_format($kinds['trialing']['count']), number_format($kinds['past_due']['count'])))
                ->description(sprintf(
                    '%s a month if the trials pay · %s a month unpaid',
                    static::amounts($kinds['trialing']['monthly_cents']),
                    static::amounts($kinds['past_due']['monthly_cents']),
                ))
                ->descriptionIcon('heroicon-m-clock')
                ->color($kinds['past_due']['count'] > 0 ? 'danger' : 'info'),
        ];
    }

    /**
     * The members whose card subscription is cancelled but still in its paid time,
     * soonest to end first: who they are, their plan, when they cancelled, when it
     * stops and what it was worth a month.
     *
     * @return list<array{user_id:int|null, name:string, email:string, plan:string, cycle:string, canceled_at:?Carbon, ends_at:?Carbon, monthly:string}>
     */
    public function cancelledMembers(): array
    {
        $rows = $this->stats()['revenue']['recurring']['cancelling'] ?? [];
        $users = User::query()
            ->whereIn('stripe_id', array_filter(array_column($rows, 'customer')))
            ->get(['id', 'name', 'email', 'stripe_id'])
            ->keyBy('stripe_id');

        return array_map(function (array $row) use ($users): array {
            $user = $users->get($row['customer']);
            $at = fn (int $ts): ?Carbon => $ts > 0 ? Carbon::createFromTimestamp($ts) : null;

            return [
                'user_id' => $user?->id,
                'name' => (string) ($user?->name ?? 'Not found on the site'),
                'email' => (string) ($user?->email ?? $row['customer']),
                'plan' => (string) $row['plan'],
                'cycle' => (string) $row['cycle'],
                'canceled_at' => $at((int) $row['canceled_at']),
                'ends_at' => $at((int) $row['ends_at']),
                'monthly' => static::amounts([$row['currency'] => (int) $row['monthly_cents']]),
            ];
        }, $rows);
    }

    /**
     * Amounts per currency, US dollars first: "$123.45", or "$10.00 + €5.00".
     *
     * @param  array<string, int>  $cents
     */
    private static function amounts(array $cents): string
    {
        if ($cents === []) {
            return static::money(0);
        }
        uksort($cents, fn ($a, $b) => [$a !== 'usd', $a] <=> [$b !== 'usd', $b]);

        return collect($cents)->map(fn (int $amount, string $currency) => $currency === 'usd'
            ? static::money($amount)
            : strtoupper($currency).' '.number_format($amount / 100, 2))->implode(' + ');
    }
}
