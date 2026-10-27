<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UsageCounterResource\Pages;
use App\Models\CharGift;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Services\Billing\RenewalGrace;
use App\Services\PlanAccessService;
use App\Support\PlanUsageGuard;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * One row per member per calendar month: what they generated from their
 * monthly allowance in the dashboard and the browser extension.
 * Demo generations and premium pack characters are counted elsewhere.
 */
class UsageCounterResource extends Resource
{
    protected static ?string $model = UsageCounter::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Monthly usage';

    protected static ?string $modelLabel = 'monthly usage';

    protected static ?string $pluralModelLabel = 'Monthly usage';

    /** @var array<string, array{plan:?Plan, allowance:?int, current:bool}> */
    private static array $planCache = [];

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('total_chars', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.email')
                    ->label('Member')
                    ->description(fn (UsageCounter $record): string => (string) ($record->user?->name ?? 'Deleted account'))
                    ->searchable(['email', 'name'])
                    ->url(fn (UsageCounter $record): ?string => $record->user ? UserResource::getUrl('edit', ['record' => $record->user]) : null)
                    ->placeholder('Deleted account'),
                Tables\Columns\TextColumn::make('plan_now')
                    ->label('Plan now')
                    ->badge()
                    ->state(fn (UsageCounter $record): string => self::planNow($record)?->name ?? 'Free')
                    ->color(fn (UsageCounter $record): string => (int) (self::planNow($record)?->price_cents ?? 0) > 0 ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('period_ym')
                    ->label('Month')
                    ->formatStateUsing(fn (UsageCounter $record): string => $record->monthLabel())
                    // A paid plan's credits restart on its own dates, so a month can end early.
                    ->description(fn (UsageCounter $record): string => self::isCurrent($record) ? 'Current period' : 'Ended')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_chars')
                    ->label('Credits used')
                    ->sortable()
                    ->formatStateUsing(fn (UsageCounter $record): string => self::creditsLabel($record))
                    ->color(function (UsageCounter $record): ?string {
                        $allowance = self::allowance($record);
                        if (! $allowance) {
                            return null;
                        }
                        $percent = self::percent($record, $allowance);

                        return $percent >= 90 ? 'danger' : ($percent >= 70 ? 'warning' : null);
                    })
                    ->tooltip('Credits used out of the credits allowed: the plan the member is on now, plus that period\'s gifts.'),
                Tables\Columns\TextColumn::make('total_requests')
                    ->label('Generations')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_seconds')
                    ->label('Audio length')
                    ->formatStateUsing(fn (UsageCounter $record): string => $record->audioLabel()
                        .(self::allowance($record) ? ' of '.number_format(\App\Support\Credits::minutesFor((int) self::allowance($record))).' min' : ''))
                    ->tooltip('The real length of the audio made in the period, out of the voice minutes its credits allow.')
                    ->sortable(),
                Tables\Columns\TextColumn::make('bonus_chars')
                    ->label('Gifted')
                    ->numeric()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => (int) $state > 0 ? '🎁 '.number_format((int) $state) : '—'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last generation')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                // By the month a period started in: calendar months ("2026-10") and
                // periods that follow a plan's own dates ("2026-10-14 08:37") alike.
                Tables\Filters\SelectFilter::make('period_ym')
                    ->label('Month')
                    ->options(fn (): array => UsageCounter::query()
                        ->pluck('period_ym')
                        ->map(fn (string $key): string => substr($key, 0, 7))
                        ->unique()->sortDesc()->take(24)
                        ->mapWithKeys(fn (string $ym): array => [$ym => (new UsageCounter(['period_ym' => $ym]))->monthLabel()])
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('period_ym', 'like', $data['value'].'%')
                        : $query)
                    ->default(now()->format('Y-m')),
                Tables\Filters\SelectFilter::make('plan')
                    ->label('Plan now')
                    ->options(fn (): array => ['paid' => 'Any paid plan', 'free' => 'Free']
                        + Plan::query()->subscriptionPlans()->where('price_cents', '>', 0)->orderBy('price_cents')->pluck('name', 'id')->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if ($value === null || $value === '') {
                            return $query;
                        }
                        $paidUserIds = self::activeSubscriptions()
                            ->whereHas('plan', fn (Builder $plan) => $plan->where('price_cents', '>', 0))
                            ->select('user_id');

                        return match ($value) {
                            'paid' => $query->whereIn('user_id', $paidUserIds),
                            'free' => $query->whereNotIn('user_id', $paidUserIds),
                            default => $query->whereIn('user_id', self::activeSubscriptions()->where('plan_id', (int) $value)->select('user_id')),
                        };
                    }),
                Tables\Filters\Filter::make('gifted')
                    ->label('Received gifted credits')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('bonus_chars', '>', 0)),
                Tables\Filters\Filter::make('active')
                    ->label('Generated at least once')
                    ->toggle()
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->where('total_requests', '>', 0)),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Details'),
            ])
            ->emptyStateHeading('No usage for this selection')
            ->emptyStateDescription('Try another month in the filters, or switch off "Generated at least once".');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Member')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('user.name')->label('Name')->placeholder('Deleted account'),
                    Infolists\Components\TextEntry::make('user.email')
                        ->label('Email')
                        ->copyable()
                        ->url(fn (UsageCounter $record): ?string => $record->user ? UserResource::getUrl('edit', ['record' => $record->user]) : null),
                    Infolists\Components\TextEntry::make('plan_now')
                        ->label('Plan now')
                        ->badge()
                        ->state(fn (UsageCounter $record): string => self::planNow($record)?->name ?? 'Free'),
                ]),
            Infolists\Components\Section::make(fn (UsageCounter $record): string => 'Usage in '.$record->monthLabel())
                ->description('Generations in the dashboard and the browser extension that counted against the monthly allowance. Demo generations and premium pack characters are not included; failed generations are given back.')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('total_chars')
                        ->label('Credits used')
                        ->state(fn (UsageCounter $record): string => self::creditsLabel($record)),
                    Infolists\Components\TextEntry::make('remaining')
                        ->label('Left this month')
                        ->visible(fn (UsageCounter $record): bool => self::isCurrent($record) && self::allowance($record) !== null)
                        ->state(fn (UsageCounter $record): string => number_format(max(0, (int) self::allowance($record) - (int) $record->total_chars))),
                    Infolists\Components\TextEntry::make('total_requests')->label('Generations')->numeric(),
                    Infolists\Components\TextEntry::make('total_seconds')
                        ->label('Audio length')
                        ->state(fn (UsageCounter $record): string => $record->audioLabel()),
                    Infolists\Components\TextEntry::make('bonus_chars')
                        ->label('Gifted credits')
                        ->state(fn (UsageCounter $record): string => number_format((int) $record->bonus_chars)),
                    Infolists\Components\TextEntry::make('updated_at')->label('Last generation')->dateTime()->since(),
                ]),
            Infolists\Components\Section::make('Gifts this month')
                ->visible(fn (UsageCounter $record): bool => (int) $record->bonus_chars > 0)
                ->schema([
                    Infolists\Components\TextEntry::make('gifts')
                        ->hiddenLabel()
                        ->state(fn (UsageCounter $record): HtmlString => self::giftsList($record)),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsageCounters::route('/'),
            'view' => Pages\ViewUsageCounter::route('/{record}'),
        ];
    }

    private static function activeSubscriptions(): Builder
    {
        return Subscription::query()
            ->where('status', 'active')
            ->where(fn (Builder $q) => $q->whereNull('current_period_end')->orWhere('current_period_end', '>=', now())
                // A paid plan kept on through a failed renewal's grace days.
                ->orWhere(fn (Builder $grace) => RenewalGrace::whereKept($grace, now())));
    }

    private static function planNow(UsageCounter $record): ?Plan
    {
        return self::planInfo($record)['plan'];
    }

    /** "125,000 of 500,000 (25%)": the credits used out of the period's allowance. */
    private static function creditsLabel(UsageCounter $record): string
    {
        $used = number_format((int) $record->total_chars);
        $allowance = self::allowance($record);
        if ($allowance === null) {
            return $used;
        }
        if ($allowance <= 0) {
            return $used.' · no monthly allowance';
        }

        return $used.' of '.number_format($allowance).' ('.self::percent($record, $allowance).'%)';
    }

    /**
     * The period's allowance (plan + gifts). The plan a member had in an ended
     * period is not recorded, so it is measured against the plan they are on now.
     */
    private static function allowance(UsageCounter $record): ?int
    {
        return self::planInfo($record)['allowance'];
    }

    /** Whether the row is the member's credit period running now (not one that has ended). */
    private static function isCurrent(UsageCounter $record): bool
    {
        return self::planInfo($record)['current'];
    }

    private static function percent(UsageCounter $record, int $allowance): int
    {
        return $allowance > 0 ? (int) min(999, round(((int) $record->total_chars / $allowance) * 100)) : 0;
    }

    /**
     * The member's plan, the credits the period allows and whether it is running now. An ended
     * period is measured against the plan the member is on now, plus that period's gifts.
     *
     * @return array{plan:?Plan, allowance:?int, current:bool}
     */
    private static function planInfo(UsageCounter $record): array
    {
        // Remembered for this request only (several columns ask for the same row).
        $key = spl_object_id(request()).':'.$record->user_id.':'.$record->period_ym.':'.$record->updated_at?->timestamp;
        if (isset(self::$planCache[$key])) {
            return self::$planCache[$key];
        }
        if (count(self::$planCache) > 500) {
            self::$planCache = [];
        }

        $plans = app(PlanAccessService::class);
        $plan = $record->user_id ? $plans->resolveActivePlanForUserId((int) $record->user_id) : null;
        $allowance = null;
        if ($record->user_id) {
            $quota = $plans->resolveQuotaPlanForUserId((int) $record->user_id, $plans->resolveAccessPlanForUserId((int) $record->user_id));
            $allowance = PlanUsageGuard::charsLimit($quota, $record);
        }

        return self::$planCache[$key] = ['plan' => $plan, 'allowance' => $allowance, 'current' => $record->isCurrentMonth()];
    }

    private static function giftsList(UsageCounter $record): HtmlString
    {
        $gifts = CharGift::query()
            ->where('user_id', $record->user_id)
            ->where('period_ym', $record->period_ym)
            ->with('grantedBy')
            ->latest()
            ->get();

        if ($gifts->isEmpty()) {
            return new HtmlString('—');
        }

        $rows = $gifts->map(fn (CharGift $gift): string => '<li>'
            .e(number_format((int) $gift->chars)).' credits on '.e($gift->created_at?->format('j M Y'))
            .' by '.e($gift->grantedBy?->email ?? 'Listen')
            .($gift->note ? ' — '.e($gift->note) : '')
            .($gift->revoked_at ? ' <strong>(taken back)</strong>' : '')
            .'</li>')->implode('');

        return new HtmlString('<ul style="margin:0;padding-inline-start:1.1rem;line-height:1.8">'.$rows.'</ul>');
    }
}
