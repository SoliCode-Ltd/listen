<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApiKeyResource\Pages;
use App\Filament\Resources\ApiKeyResource\RelationManagers;
use App\Models\ApiKey;
use App\Models\ApiKeyUsage;
use App\Models\ApiRequestLog;
use App\Support\Api\ApiKeyStatus;
use App\Support\PlanUsageGuard;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every developer API key members have made: its credits this period against its own limit (a
 * key may have none: then the member's plan credits apply), the member's generations today
 * against their plan's daily limit, and whether it was stopped by either. Each key opens on its
 * own page with all its requests and every period's use. An admin can revoke a key.
 */
class ApiKeyResource extends Resource
{
    protected static ?string $model = ApiKey::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'API keys';

    protected static ?string $modelLabel = 'API key';

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

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user:id,name,email'))
            ->recordUrl(fn (ApiKey $record): string => self::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('user.email')->label('Member')->searchable()->copyable()
                    ->description(fn (ApiKey $record): ?string => $record->user?->name)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('name')->searchable()->weight(FontWeight::SemiBold)
                    ->description(fn (ApiKey $record): string => $record->maskedKey()),
                Tables\Columns\TextColumn::make('scopes')
                    ->label('Permissions')
                    ->formatStateUsing(fn (ApiKey $record): string => $record->scopes === null ? 'All' : implode(', ', $record->scopes))
                    ->placeholder('All')
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('month_credits')
                    // The member's current credit period (their plan's own month).
                    ->label('Credits this period')
                    ->state(fn (ApiKey $record): string => ApiKeyStatus::creditsLine($record))
                    ->description(fn (ApiKey $record): string => ApiKeyStatus::creditsNote($record))
                    ->color(fn (ApiKey $record): ?string => ApiKeyStatus::hitKeyLimit($record) ? 'danger' : null)
                    ->wrap(),
                Tables\Columns\TextColumn::make('month_requests')
                    ->label('Requests this period')
                    ->state(fn (ApiKey $record): int => ApiKeyStatus::of($record)['requests'])
                    ->numeric(),
                Tables\Columns\TextColumn::make('today')
                    ->label('Today (member)')
                    ->state(fn (ApiKey $record): string => ApiKeyStatus::todayLine($record))
                    ->description(fn (ApiKey $record): string => ApiKeyStatus::todayNote($record))
                    ->tooltip('The daily limit counts the member\'s generations in the studio and the API together; the day starts at midnight Cairo time.')
                    ->wrap(),
                Tables\Columns\TextColumn::make('limits')
                    ->label('Limits hit')
                    ->state(fn (ApiKey $record): array => array_values(array_filter([
                        ApiKeyStatus::hitDailyToday($record) ? 'Daily limit today' : null,
                        ApiKeyStatus::hitKeyLimit($record) ? 'Key limit this period' : null,
                    ])))
                    ->badge()
                    ->color('danger')
                    ->placeholder('None'),
                Tables\Columns\TextColumn::make('last_used_at')->label('Last used')->since()->dateTimeTooltip()->sortable()->placeholder('Never'),
                Tables\Columns\TextColumn::make('last_used_ip')->label('Last IP')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->label('Created')->dateTime()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('revoked_at')
                    ->label('Status')
                    ->badge()
                    ->state(fn (ApiKey $record): string => $record->isRevoked() ? 'Revoked' : 'Active')
                    ->color(fn (string $state): string => $state === 'Active' ? 'success' : 'gray'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('revoked_at')
                    ->label('Status')
                    ->nullable()
                    ->trueLabel('Revoked')
                    ->falseLabel('Active')
                    ->default(false),
                Tables\Filters\Filter::make('daily_today')
                    ->label('Stopped by the daily limit today')
                    ->query(fn (Builder $query): Builder => $query->whereIn('api_keys.id', ApiRequestLog::query()->select('api_key_id')
                        ->where('error_code', ApiKeyStatus::DAILY)->where('created_at', '>=', PlanUsageGuard::startOfMemberDay()))),
                Tables\Filters\Filter::make('key_limit')
                    ->label('Stopped by its own limit (last 31 days)')
                    ->query(fn (Builder $query): Builder => $query->whereIn('api_keys.id', ApiRequestLog::query()->select('api_key_id')
                        ->where('error_code', ApiKeyStatus::KEY_LIMIT)->where('created_at', '>=', now()->subDays(31)))),
                Tables\Filters\Filter::make('used_today')
                    ->label('Used today')
                    ->query(fn (Builder $query): Builder => $query->where('last_used_at', '>=', PlanUsageGuard::startOfMemberDay())),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->iconButton(),
                self::revokeAction(),
            ]);
    }

    public static function revokeAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('revoke')
            ->label('Revoke')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (ApiKey $record): bool => ! $record->isRevoked())
            ->requiresConfirmation()
            ->modalDescription('The key stops working at once. The member can make a new one if their plan includes the API.')
            ->action(function (ApiKey $record): void {
                $record->revoke();
                Notification::make()->title('Key revoked')->success()->send();
            });
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Key')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('name')->weight(FontWeight::SemiBold),
                    Infolists\Components\TextEntry::make('key')->label('Key')->state(fn (ApiKey $record): string => $record->maskedKey())->fontFamily('mono'),
                    Infolists\Components\TextEntry::make('user.email')->label('Member')->copyable()
                        ->url(fn (ApiKey $record): ?string => $record->user_id ? UserResource::getUrl('view', ['record' => $record->user_id]) : null)
                        ->helperText(fn (ApiKey $record): ?string => $record->user?->name)
                        ->placeholder('Account deleted'),
                    Infolists\Components\TextEntry::make('status')
                        ->state(fn (ApiKey $record): string => $record->isRevoked() ? 'Revoked '.$record->revoked_at?->toDayDateTimeString() : 'Active')
                        ->badge()->color(fn (ApiKey $record): string => $record->isRevoked() ? 'gray' : 'success'),
                    Infolists\Components\TextEntry::make('scopes')->label('Permissions')
                        ->state(fn (ApiKey $record): string => $record->scopes === null ? 'All' : implode(', ', $record->scopes))
                        ->columnSpan(2),
                    Infolists\Components\TextEntry::make('kind')->label('Made as')
                        ->state(fn (ApiKey $record): string => $record->isConnectedApp() ? 'A connected app (sign-in)' : 'A key the member made'),
                    Infolists\Components\TextEntry::make('id')->label('Database id'),
                    Infolists\Components\TextEntry::make('created_at')->label('Created')->dateTime(),
                    Infolists\Components\TextEntry::make('last_used_at')->label('Last used')->dateTime()->placeholder('Never'),
                    Infolists\Components\TextEntry::make('last_used_ip')->label('Last IP')->placeholder('—'),
                ]),
            Infolists\Components\Section::make('Limits')
                ->description('Credits this period against the key\'s own limit, and the member\'s day against their plan\'s daily limit.')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('credits_period')->label('Credits this period')
                        ->state(fn (ApiKey $record): string => ApiKeyStatus::creditsLine($record))
                        ->helperText(fn (ApiKey $record): string => ApiKeyStatus::creditsNote($record))
                        ->color(fn (ApiKey $record): ?string => ApiKeyStatus::hitKeyLimit($record) ? 'danger' : null),
                    Infolists\Components\TextEntry::make('requests_period')->label('Requests this period')
                        ->state(fn (ApiKey $record): string => number_format(ApiKeyStatus::of($record)['requests']))
                        ->helperText(fn (ApiKey $record): string => ApiKeyStatus::of($record)['period']['starts_at']->toFormattedDateString().' to '.ApiKeyStatus::of($record)['period']['ends_at']->toFormattedDateString()),
                    Infolists\Components\TextEntry::make('today_line')->label('The member today')
                        ->state(fn (ApiKey $record): string => ApiKeyStatus::todayLine($record))
                        ->helperText(fn (ApiKey $record): string => ApiKeyStatus::todayNote($record))
                        ->color(fn (ApiKey $record): ?string => ApiKeyStatus::hitDailyToday($record) ? 'danger' : null),
                    Infolists\Components\TextEntry::make('refusals')->label('Refused for a limit')
                        ->state(fn (ApiKey $record): string => number_format(ApiKeyStatus::of($record)['refused_today']).' today (daily limit) · '
                            .number_format(ApiKeyStatus::of($record)['key_refused']).' this period (key limit)')
                        ->helperText(fn (ApiKey $record): ?string => ($at = ApiKeyStatus::of($record)['last_refused_at']) ? 'Last refused '.\Illuminate\Support\Carbon::parse($at)->diffForHumans() : 'Never refused for a limit'),
                ]),
            Infolists\Components\Section::make('Use by period')
                ->description('Every credit period this key was used in, newest first.')
                ->collapsible()
                ->schema([
                    Infolists\Components\RepeatableEntry::make('periods')->hiddenLabel()
                        ->state(fn (ApiKey $record): array => ApiKeyUsage::query()->where('api_key_id', $record->id)->orderByDesc('id')->limit(36)
                            ->get(['period_ym', 'requests', 'credits'])->map(fn (ApiKeyUsage $u): array => [
                                'period' => $u->period_ym, 'requests' => number_format((int) $u->requests), 'credits' => number_format((int) $u->credits),
                            ])->all())
                        ->columns(3)
                        ->schema([
                            Infolists\Components\TextEntry::make('period')->label('Period'),
                            Infolists\Components\TextEntry::make('requests')->label('Requests'),
                            Infolists\Components\TextEntry::make('credits')->label('Credits'),
                        ])
                        ->placeholder('Not used yet.'),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\RequestsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApiKeys::route('/'),
            'view' => Pages\ViewApiKey::route('/{record}'),
        ];
    }

    /** How many keys work now (made by members or connected apps), not revoked. */
    public static function getNavigationBadge(): ?string
    {
        try {
            $count = ApiKey::query()->whereNull('revoked_at')->count();
        } catch (\Throwable) {
            // Before the migration has run.
            return null;
        }

        return $count > 0 ? number_format($count) : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'API keys that work now';
    }
}
