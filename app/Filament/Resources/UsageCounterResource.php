<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UsageCounterResource\Pages;
use App\Models\CharGift;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
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

    /** @var array<string, array{plan:?Plan, allowance:?int}> */
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
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_chars')
                    ->label('Characters used')
                    ->sortable()
                    ->formatStateUsing(function (UsageCounter $record): string {
                        $allowance = self::allowance($record);
                        if ($allowance === null) {
                            return number_format((int) $record->total_chars);
                        }
                        if ($allowance <= 0) {
                            return number_format((int) $record->total_chars).' · no monthly allowance now';
                        }

                        return number_format((int) $record->total_chars).' of '.number_format($allowance)
                            .($allowance > 0 ? ' ('.self::percent($record, $allowance).'%)' : '');
                    })
                    ->color(function (UsageCounter $record): ?string {
                        $allowance = self::allowance($record);
                        if (! $allowance) {
                            return null;
                        }
                        $percent = self::percent($record, $allowance);

                        return $percent >= 90 ? 'danger' : ($percent >= 70 ? 'warning' : null);
                    })
                    ->tooltip('This month shows the allowance (plan + gifts). Earlier months show what was used.'),
                Tables\Columns\TextColumn::make('total_requests')
                    ->label('Generations')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_seconds')
                    ->label('Audio length')
                    ->formatStateUsing(fn (UsageCounter $record): string => $record->audioLabel())
                    ->tooltip('The real length of the audio made this month, added up from every generation.')
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
                Tables\Filters\SelectFilter::make('period_ym')
                    ->label('Month')
                    ->options(fn (): array => UsageCounter::query()
                        ->select('period_ym')->distinct()->orderByDesc('period_ym')->limit(24)->pluck('period_ym')
                        ->mapWithKeys(fn (string $ym): array => [$ym => (new UsageCounter(['period_ym' => $ym]))->monthLabel()])
                        ->all())
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
                    ->label('Received gifted characters')
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
                        ->label('Characters used')
                        ->state(function (UsageCounter $record): string {
                            $allowance = self::allowance($record);

                            return $allowance === null
                                ? number_format((int) $record->total_chars)
                                : number_format((int) $record->total_chars).' of '.number_format($allowance).' ('.self::percent($record, $allowance).'%)';
                        }),
                    Infolists\Components\TextEntry::make('remaining')
                        ->label('Left this month')
                        ->visible(fn (UsageCounter $record): bool => self::allowance($record) !== null)
                        ->state(fn (UsageCounter $record): string => number_format(max(0, (int) self::allowance($record) - (int) $record->total_chars))),
                    Infolists\Components\TextEntry::make('total_requests')->label('Generations')->numeric(),
                    Infolists\Components\TextEntry::make('total_seconds')
                        ->label('Audio length')
                        ->state(fn (UsageCounter $record): string => $record->audioLabel()),
                    Infolists\Components\TextEntry::make('bonus_chars')
                        ->label('Gifted characters')
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
            ->where(fn (Builder $q) => $q->whereNull('current_period_end')->orWhere('current_period_end', '>=', now()));
    }

    private static function planNow(UsageCounter $record): ?Plan
    {
        return self::planInfo($record)['plan'];
    }

    /**
     * The month's allowance (plan + gifts), for the current month only: the
     * plan a member had in past months is not recorded.
     */
    private static function allowance(UsageCounter $record): ?int
    {
        return self::planInfo($record)['allowance'];
    }

    private static function percent(UsageCounter $record, int $allowance): int
    {
        return $allowance > 0 ? (int) min(999, round(((int) $record->total_chars / $allowance) * 100)) : 0;
    }

    /**
     * @return array{plan:?Plan, allowance:?int}
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
        if ($record->isCurrentMonth() && $record->user_id) {
            $quota = $plans->resolveQuotaPlanForUserId((int) $record->user_id, $plans->resolveAccessPlanForUserId((int) $record->user_id));
            $allowance = PlanUsageGuard::charsLimit($quota, $record);
        }

        return self::$planCache[$key] = ['plan' => $plan, 'allowance' => $allowance];
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
            .e(number_format((int) $gift->chars)).' characters on '.e($gift->created_at?->format('j M Y'))
            .' by '.e($gift->grantedBy?->email ?? 'Listen')
            .($gift->note ? ' — '.e($gift->note) : '')
            .($gift->revoked_at ? ' <strong>(taken back)</strong>' : '')
            .'</li>')->implode('');

        return new HtmlString('<ul style="margin:0;padding-inline-start:1.1rem;line-height:1.8">'.$rows.'</ul>');
    }
}
