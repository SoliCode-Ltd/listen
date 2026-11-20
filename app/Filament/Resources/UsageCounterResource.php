<?php

namespace App\Filament\Resources;

use Illuminate\Auth\Access\Response as AccessResponse;
use Filament\Tables\Columns\TextColumn;
use App\Support\Credits;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Actions\ViewAction;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use App\Filament\Resources\UsageCounterResource\Pages\ListUsageCounters;
use App\Filament\Resources\UsageCounterResource\Pages\ViewUsageCounter;
use App\Filament\Resources\UsageCounterResource\Pages;
use App\Models\CharGift;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Services\Billing\ExtraCredits;
use App\Models\User;
use App\Services\Analytics\UsageInsights;
use App\Services\Billing\RenewalGrace;
use App\Services\PlanAccessService;
use App\Support\PlanUsageGuard;
use Filament\Forms;
use Filament\Infolists;
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

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string | \UnitEnum | null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Monthly usage';

    protected static ?string $modelLabel = 'monthly usage';

    protected static ?string $pluralModelLabel = 'Monthly usage';

    /** @var array<string, array{plan:?Plan, allowance:?int, current:bool}> */
    private static array $planCache = [];

    // Filament 4 checks this (pages, buttons and canCreate() alike): never allowed here.
    public static function getCreateAuthorizationResponse(): AccessResponse
    {
        return AccessResponse::deny();
    }

    // Filament 4 checks this (pages, buttons and canEdit() alike): never allowed here.
    public static function getEditAuthorizationResponse(Model $record): AccessResponse
    {
        return AccessResponse::deny();
    }

    // Filament 4 checks this (pages, buttons and canDelete() alike): never allowed here.
    public static function getDeleteAuthorizationResponse(Model $record): AccessResponse
    {
        return AccessResponse::deny();
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
                TextColumn::make('user.email')
                    ->label('Member')
                    ->description(fn (UsageCounter $record): string => (string) ($record->user?->name ?? 'Deleted account'))
                    ->searchable(['email', 'name'])
                    ->url(fn (UsageCounter $record): ?string => $record->user ? UserResource::getUrl('edit', ['record' => $record->user]) : null)
                    ->placeholder('Deleted account'),
                TextColumn::make('plan_now')
                    ->label('Plan now')
                    ->badge()
                    ->state(fn (UsageCounter $record): string => self::planNow($record)?->name ?? 'Free')
                    ->color(fn (UsageCounter $record): string => (int) (self::planNow($record)?->price_cents ?? 0) > 0 ? 'success' : 'gray'),
                TextColumn::make('period_ym')
                    ->label('Month')
                    ->formatStateUsing(fn (UsageCounter $record): string => $record->monthLabel())
                    // A paid plan's credits restart on its own dates, so a month can end early.
                    ->description(fn (UsageCounter $record): string => self::isCurrent($record) ? 'Current period' : 'Ended')
                    ->sortable(),
                TextColumn::make('total_chars')
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
                TextColumn::make('total_requests')
                    ->label('Generations')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_seconds')
                    ->label('Audio length')
                    ->formatStateUsing(fn (UsageCounter $record): string => $record->audioLabel()
                        .(self::allowance($record) ? ' of '.number_format(Credits::minutesFor((int) self::allowance($record))).' min' : ''))
                    ->tooltip('The real length of the audio made in the period, out of the voice minutes its credits allow.')
                    ->sortable(),
                TextColumn::make('bonus_chars')
                    ->label('Gifted')
                    ->numeric()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => (int) $state > 0 ? '🎁 '.number_format((int) $state) : '—'),
                // Bought or carried into the period (App\Services\Billing\ExtraCredits), and what became of them.
                TextColumn::make('extra_chars')
                    ->label('Extra credits')
                    ->numeric()
                    ->sortable()
                    ->formatStateUsing(fn ($state, UsageCounter $record): string => self::extraLabel($record))
                    ->tooltip('Extra credits in this period: bought, or carried over from the last one. Used after the plan\'s credits and the gifts.')
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Last generation')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                // By the month a period started in: calendar months ("2026-10") and
                // periods that follow a plan's own dates ("2026-10-14 08:37") alike.
                SelectFilter::make('period_ym')
                    ->label('Month')
                    // The months themselves from the database, not every member's row.
                    ->options(fn (): array => UsageCounter::query()
                        ->selectRaw('substr(period_ym, 1, 7) as ym')
                        ->distinct()
                        ->pluck('ym')
                        ->map(fn (string $key): string => substr($key, 0, 7))
                        ->unique()->sortDesc()->take(24)
                        ->mapWithKeys(fn (string $ym): array => [$ym => (new UsageCounter(['period_ym' => $ym]))->monthLabel()])
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('period_ym', 'like', $data['value'].'%')
                        : $query)
                    ->default(now()->format('Y-m')),
                SelectFilter::make('plan')
                    ->label('Plan now')
                    ->options(fn (): array => ['paid' => 'Any paid plan', 'free' => 'Free']
                        + Plan::query()->subscriptionPlans()->where('price_cents', '>', 0)->orderBy('price_cents')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => self::onPlan($query, $data['value'] ?? null)),
                Filter::make('gifted')
                    ->label('Received gifted credits')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('bonus_chars', '>', 0)),
                Filter::make('members_only')
                    ->label('Leave out admin accounts')
                    ->toggle()
                    ->default()
                    ->query(fn (Builder $query): Builder => self::withoutAdmins($query)),
                Filter::make('active')
                    ->label('Generated at least once')
                    ->toggle()
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->where('total_requests', '>', 0)),
            ])
            ->recordActions([
                ViewAction::make()->label('Details'),
            ])
            ->emptyStateHeading('No usage for this selection')
            ->emptyStateDescription('Try another month in the filters, or switch off "Generated at least once".');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Member')
                ->columns(3)
                ->schema([
                    TextEntry::make('user.name')->label('Name')->placeholder('Deleted account'),
                    TextEntry::make('user.email')
                        ->label('Email')
                        ->copyable()
                        ->url(fn (UsageCounter $record): ?string => $record->user ? UserResource::getUrl('edit', ['record' => $record->user]) : null),
                    TextEntry::make('plan_now')
                        ->label('Plan now')
                        ->badge()
                        ->state(fn (UsageCounter $record): string => self::planNow($record)?->name ?? 'Free'),
                ]),
            Section::make(fn (UsageCounter $record): string => 'Usage in '.$record->monthLabel())
                ->description('Generations in the dashboard and the browser extension that counted against the monthly allowance. Demo generations and premium pack characters are not included; failed generations are given back.')
                ->columns(3)
                ->schema([
                    TextEntry::make('total_chars')
                        ->label('Credits used')
                        ->state(fn (UsageCounter $record): string => self::creditsLabel($record)),
                    TextEntry::make('remaining')
                        ->label('Left this month')
                        ->visible(fn (UsageCounter $record): bool => self::isCurrent($record) && self::allowance($record) !== null)
                        ->state(fn (UsageCounter $record): string => number_format(max(0, (int) self::allowance($record) - (int) $record->total_chars))),
                    TextEntry::make('total_requests')->label('Generations')->numeric(),
                    TextEntry::make('total_seconds')
                        ->label('Audio length')
                        ->state(fn (UsageCounter $record): string => $record->audioLabel()),
                    TextEntry::make('bonus_chars')
                        ->label('Gifted credits')
                        ->state(fn (UsageCounter $record): string => number_format((int) $record->bonus_chars)),
                    TextEntry::make('extra_chars')
                        ->label('Extra credits')
                        ->visible(fn (UsageCounter $record): bool => (int) $record->extra_chars > 0)
                        ->state(fn (UsageCounter $record): string => self::extraLabel($record)),
                    TextEntry::make('updated_at')->label('Last generation')->dateTime()->since(),
                ]),
            Section::make('What they made')
                ->description('Every generation charged to this period, pack characters included: how often, what they chose and where.')
                ->visible(fn (UsageCounter $record): bool => $record->user_id !== null)
                ->schema([
                    ViewEntry::make('insights')
                        ->hiddenLabel()
                        ->view('filament.infolists.usage-insights')
                        ->state(function (UsageCounter $record): array {
                            $generations = UsageInsights::period((int) $record->user_id, (string) $record->period_ym);
                            $insights = app(UsageInsights::class);

                            return ['summary' => $insights->summary($generations), 'breakdowns' => $insights->breakdowns($generations)];
                        }),
                ]),
            Section::make('Gifts this month')
                ->visible(fn (UsageCounter $record): bool => (int) $record->bonus_chars > 0)
                ->schema([
                    TextEntry::make('gifts')
                        ->hiddenLabel()
                        ->state(fn (UsageCounter $record): HtmlString => self::giftsList($record)),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsageCounters::route('/'),
            'view' => ViewUsageCounter::route('/{record}'),
        ];
    }

    /**
     * Rows of members on a plan now: 'paid' (any paid plan), 'free', or a plan's id.
     * Shared with the Monthly usage widgets, so they count what the table shows.
     */
    public static function onPlan(Builder $query, ?string $value, string $column = 'user_id'): Builder
    {
        if ($value === null || $value === '') {
            return $query;
        }

        return match ($value) {
            'paid' => $query->whereIn($column, self::payingMemberIds()),
            'free' => $query->whereNotIn($column, self::payingMemberIds()),
            default => $query->whereIn($column, self::activeSubscriptions()->where('plan_id', (int) $value)->select('user_id')),
        };
    }

    /** Members on a paid plan now, as a subquery of user ids. */
    public static function payingMemberIds(): Builder
    {
        return self::activeSubscriptions()
            ->whereHas('plan', fn (Builder $plan) => $plan->where('price_cents', '>', 0))
            ->select('user_id');
    }

    /** Leaves out admin accounts, so the team's own testing doesn't count as members' use. */
    public static function withoutAdmins(Builder $query, string $column = 'user_id'): Builder
    {
        return $query->whereNotIn($column, User::query()->where('is_admin', true)->select('id'));
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

    /** "27,000 · 20,000 left", "27,000 · moved on", "27,000 · ended unused 5,000". */
    private static function extraLabel(UsageCounter $record): string
    {
        $extra = (int) $record->extra_chars;
        if ($extra <= 0) {
            return '—';
        }
        $left = $extra - ExtraCredits::usedOf($record, (int) $record->extra_base_chars);

        return number_format($extra).' · '.match ((string) $record->extra_outcome) {
            ExtraCredits::OUTCOME_CARRIED => 'moved on',
            ExtraCredits::OUTCOME_ENDED => 'ended, '.number_format(max(0, $left)).' unused',
            ExtraCredits::OUTCOME_USED => 'all used',
            default => number_format(max(0, $left)).' left',
        };
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
