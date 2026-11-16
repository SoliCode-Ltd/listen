<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApiKeyResource\Pages;
use App\Filament\Resources\ApiKeyResource\Pages\ListApiKeys;
use App\Filament\Resources\ApiKeyResource\Pages\ViewApiKey;
use App\Filament\Resources\ApiKeyResource\RelationManagers\RequestsRelationManager;
use App\Models\ApiKey;
use App\Models\ApiKeyUsage;
use App\Models\ApiRequestLog;
use App\Support\Api\ApiKeyStatus;
use App\Support\PlanUsageGuard;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response as AccessResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Every developer API key members have made: its credits this period against its own limit (a
 * key may have none: then the member's plan credits apply), the member's generations today
 * against their plan's daily limit, and whether it was stopped by either. Each key opens on its
 * own page with all its requests and every period's use. An admin can revoke a key.
 */
class ApiKeyResource extends Resource
{
    protected static ?string $model = ApiKey::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'API keys';

    protected static ?string $modelLabel = 'API key';

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

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user:id,name,email'))
            ->recordUrl(fn (ApiKey $record): string => self::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('user.email')->label('Member')->searchable()->copyable()
                    ->description(fn (ApiKey $record): ?string => $record->user?->name)
                    ->placeholder('—'),
                TextColumn::make('name')->searchable()->weight(FontWeight::SemiBold)
                    ->description(fn (ApiKey $record): string => $record->maskedKey()),
                TextColumn::make('scopes')
                    ->label('Permissions')
                    // One line, as a whole: Filament 5 formats a list item by item, so a format of the
                    // whole list was shown once per permission.
                    ->state(fn (ApiKey $record): string => $record->scopes === null ? 'All' : implode(', ', $record->scopes))
                    ->placeholder('All')
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('month_credits')
                    // The member's current credit period (their plan's own month).
                    ->label('Credits this period')
                    ->state(fn (ApiKey $record): string => ApiKeyStatus::creditsLine($record))
                    ->description(fn (ApiKey $record): string => ApiKeyStatus::creditsNote($record))
                    ->color(fn (ApiKey $record): ?string => ApiKeyStatus::hitKeyLimit($record) ? 'danger' : null)
                    ->wrap(),
                TextColumn::make('month_requests')
                    ->label('Requests this period')
                    ->state(fn (ApiKey $record): int => ApiKeyStatus::of($record)['requests'])
                    ->numeric(),
                TextColumn::make('today')
                    ->label('Today (member)')
                    ->state(fn (ApiKey $record): string => ApiKeyStatus::todayLine($record))
                    ->description(fn (ApiKey $record): string => ApiKeyStatus::todayNote($record))
                    ->tooltip('The daily limit counts the member\'s generations in the studio and the API together; the day starts at midnight Cairo time.')
                    ->wrap(),
                TextColumn::make('limits')
                    ->label('Limits hit')
                    ->state(fn (ApiKey $record): array => array_values(array_filter([
                        ApiKeyStatus::hitDailyToday($record) ? 'Daily limit today' : null,
                        ApiKeyStatus::hitKeyLimit($record) ? 'Key limit this period' : null,
                    ])))
                    ->badge()
                    ->color('danger')
                    ->placeholder('None'),
                TextColumn::make('last_used_at')->label('Last used')->since()->dateTimeTooltip()->sortable()->placeholder('Never'),
                TextColumn::make('last_used_ip')->label('Last IP')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Created')->dateTime()->sortable()->toggleable(),
                TextColumn::make('revoked_at')
                    ->label('Status')
                    ->badge()
                    ->state(fn (ApiKey $record): string => $record->isRevoked() ? 'Revoked' : 'Active')
                    ->color(fn (string $state): string => $state === 'Active' ? 'success' : 'gray'),
            ])
            ->filters([
                TernaryFilter::make('revoked_at')
                    ->label('Status')
                    ->nullable()
                    ->trueLabel('Revoked')
                    ->falseLabel('Active')
                    ->default(false),
                Filter::make('daily_today')
                    ->label('Stopped by the daily limit today')
                    ->query(fn (Builder $query): Builder => $query->whereIn('api_keys.id', ApiRequestLog::query()->select('api_key_id')
                        ->where('error_code', ApiKeyStatus::DAILY)->where('created_at', '>=', PlanUsageGuard::startOfMemberDay()))),
                Filter::make('key_limit')
                    ->label('Stopped by its own limit (last 31 days)')
                    ->query(fn (Builder $query): Builder => $query->whereIn('api_keys.id', ApiRequestLog::query()->select('api_key_id')
                        ->where('error_code', ApiKeyStatus::KEY_LIMIT)->where('created_at', '>=', now()->subDays(31)))),
                Filter::make('used_today')
                    ->label('Used today')
                    ->query(fn (Builder $query): Builder => $query->where('last_used_at', '>=', PlanUsageGuard::startOfMemberDay())),
            ])
            ->recordActions([
                ViewAction::make()->iconButton(),
                self::revokeAction(),
            ]);
    }

    public static function revokeAction(): Action
    {
        return Action::make('revoke')
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

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Key')
                ->columns(4)
                ->schema([
                    TextEntry::make('name')->weight(FontWeight::SemiBold),
                    TextEntry::make('key')->label('Key')->state(fn (ApiKey $record): string => $record->maskedKey())->fontFamily('mono'),
                    TextEntry::make('user.email')->label('Member')->copyable()
                        ->url(fn (ApiKey $record): ?string => $record->user_id ? UserResource::getUrl('view', ['record' => $record->user_id]) : null)
                        ->helperText(fn (ApiKey $record): ?string => $record->user?->name)
                        ->placeholder('Account deleted'),
                    TextEntry::make('status')
                        ->state(fn (ApiKey $record): string => $record->isRevoked() ? 'Revoked '.$record->revoked_at?->toDayDateTimeString() : 'Active')
                        ->badge()->color(fn (ApiKey $record): string => $record->isRevoked() ? 'gray' : 'success'),
                    TextEntry::make('scopes')->label('Permissions')
                        ->state(fn (ApiKey $record): string => $record->scopes === null ? 'All' : implode(', ', $record->scopes))
                        ->columnSpan(2),
                    TextEntry::make('kind')->label('Made as')
                        ->state(fn (ApiKey $record): string => $record->isConnectedApp() ? 'A connected app (sign-in)' : 'A key the member made'),
                    TextEntry::make('id')->label('Database id'),
                    TextEntry::make('created_at')->label('Created')->dateTime(),
                    TextEntry::make('last_used_at')->label('Last used')->dateTime()->placeholder('Never'),
                    TextEntry::make('last_used_ip')->label('Last IP')->placeholder('—'),
                ]),
            Section::make('Limits')
                ->description('Credits this period against the key\'s own limit, and the member\'s day against their plan\'s daily limit.')
                ->columns(4)
                ->schema([
                    TextEntry::make('credits_period')->label('Credits this period')
                        ->state(fn (ApiKey $record): string => ApiKeyStatus::creditsLine($record))
                        ->helperText(fn (ApiKey $record): string => ApiKeyStatus::creditsNote($record))
                        ->color(fn (ApiKey $record): ?string => ApiKeyStatus::hitKeyLimit($record) ? 'danger' : null),
                    TextEntry::make('requests_period')->label('Requests this period')
                        ->state(fn (ApiKey $record): string => number_format(ApiKeyStatus::of($record)['requests']))
                        ->helperText(fn (ApiKey $record): string => ApiKeyStatus::of($record)['period']['starts_at']->toFormattedDateString().' to '.ApiKeyStatus::of($record)['period']['ends_at']->toFormattedDateString()),
                    TextEntry::make('today_line')->label('The member today')
                        ->state(fn (ApiKey $record): string => ApiKeyStatus::todayLine($record))
                        ->helperText(fn (ApiKey $record): string => ApiKeyStatus::todayNote($record))
                        ->color(fn (ApiKey $record): ?string => ApiKeyStatus::hitDailyToday($record) ? 'danger' : null),
                    TextEntry::make('refusals')->label('Refused for a limit')
                        ->state(fn (ApiKey $record): string => number_format(ApiKeyStatus::of($record)['refused_today']).' today (daily limit) · '
                            .number_format(ApiKeyStatus::of($record)['key_refused']).' this period (key limit)')
                        ->helperText(fn (ApiKey $record): ?string => ($at = ApiKeyStatus::of($record)['last_refused_at']) ? 'Last refused '.Carbon::parse($at)->diffForHumans() : 'Never refused for a limit'),
                ]),
            Section::make('Use by period')
                ->description('Every credit period this key was used in, newest first.')
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('periods')->hiddenLabel()
                        ->state(fn (ApiKey $record): array => ApiKeyUsage::query()->where('api_key_id', $record->id)->orderByDesc('id')->limit(36)
                            ->get(['period_ym', 'requests', 'credits'])->map(fn (ApiKeyUsage $u): array => [
                                'period' => $u->period_ym, 'requests' => number_format((int) $u->requests), 'credits' => number_format((int) $u->credits),
                            ])->all())
                        ->columns(3)
                        ->schema([
                            TextEntry::make('period')->label('Period'),
                            TextEntry::make('requests')->label('Requests'),
                            TextEntry::make('credits')->label('Credits'),
                        ])
                        ->placeholder('Not used yet.'),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [RequestsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApiKeys::route('/'),
            'view' => ViewApiKey::route('/{record}'),
        ];
    }

    /** How many keys work now (made by members or connected apps), not revoked. */
    public static function getNavigationBadge(): ?string
    {
        try {
            $count = ApiKey::query()->whereNull('revoked_at')->count();
        } catch (Throwable) {
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
