<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApiRequestLogResource\Pages;
use App\Mcp\Servers\ListenServer;
use App\Models\ApiRequestLog;
use App\Support\Api\ApiKeyStatus;
use App\Support\PlanUsageGuard;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;

/**
 * Every developer API request (kept 90 days): who sent it, which endpoint, how it ended and
 * what it cost. Search by the request id a customer quotes. Each request opens on its own page
 * with everything kept about it; each key's requests are also on the key's page. The list pages
 * without counting the whole log first, so it opens fast however long the log grows.
 */
class ApiRequestLogResource extends Resource
{
    protected static ?string $model = ApiRequestLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'API requests';

    protected static ?string $modelLabel = 'API request';

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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user:id,email', 'apiKey:id,name,last_four']))
            ->recordUrl(fn (ApiRequestLog $record): string => self::getUrl('view', ['record' => $record]))
            ->columns(self::tableColumns())
            ->filters(self::tableFilters());
    }

    /** @return array<int, Tables\Columns\Column> the requests table, also on each key's page (without its Key column there) */
    public static function tableColumns(bool $withKey = true): array
    {
        return array_values(array_filter([
            Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime('Y-m-d H:i:s')->sortable(),
            $withKey ? Tables\Columns\TextColumn::make('user.email')->label('Member')->searchable()->placeholder('—') : null,
            $withKey ? Tables\Columns\TextColumn::make('apiKey.name')->label('Key')
                ->description(fn (ApiRequestLog $record): ?string => $record->apiKey ? '…'.$record->apiKey->last_four : null)
                ->url(fn (ApiRequestLog $record): ?string => $record->api_key_id ? ApiKeyResource::getUrl('view', ['record' => $record->api_key_id]) : null)
                ->placeholder('—') : null,
            Tables\Columns\TextColumn::make('method')->badge()->color(fn (string $state): string => match ($state) {
                'POST' => 'primary',
                'DELETE' => 'danger',
                default => 'gray',
            }),
            Tables\Columns\TextColumn::make('path')->searchable()->limit(48)->fontFamily('mono')
                ->description(fn (ApiRequestLog $record): ?string => $record->endpoint),
            Tables\Columns\TextColumn::make('status')->badge()->sortable()->color(fn (int $state): string => self::statusColor($state)),
            Tables\Columns\TextColumn::make('error_code')->label('Error')->placeholder('—')->searchable()
                ->color(fn (?string $state): ?string => in_array($state, [ApiKeyStatus::DAILY, ApiKeyStatus::KEY_LIMIT], true) ? 'danger' : null),
            Tables\Columns\TextColumn::make('credits')->numeric()->placeholder('—')->sortable(),
            Tables\Columns\TextColumn::make('characters')->label('Chars')->numeric()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('duration_ms')->label('Time')->suffix(' ms')->numeric()->sortable(),
            Tables\Columns\IconColumn::make('replayed')->label('Replay')->boolean()->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('history_item_id')->label('Generation')->placeholder('—')
                ->url(fn (ApiRequestLog $record): ?string => self::generationUrl($record))->toggleable(),
            Tables\Columns\TextColumn::make('request_id')->label('Request id')->searchable()->copyable()->fontFamily('mono')->toggleable(),
            Tables\Columns\TextColumn::make('ip')->label('IP')->searchable()->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('user_agent')->label('Client')->limit(40)->toggleable(isToggledHiddenByDefault: true),
        ]));
    }

    /** @return array<int, Tables\Filters\BaseFilter> */
    public static function tableFilters(): array
    {
        return [
            Tables\Filters\SelectFilter::make('outcome')
                ->options(['ok' => 'Succeeded', 'client' => 'Refused (4xx)', 'server' => 'Failed (5xx)'])
                ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'ok' => $query->where('status', '<', 400),
                    'client' => $query->whereBetween('status', [400, 499]),
                    'server' => $query->where('status', '>=', 500),
                    default => $query,
                }),
            Tables\Filters\Filter::make('limits')->label('Stopped by a limit (daily or the key\'s own)')
                ->query(fn (Builder $query): Builder => $query->whereIn('error_code', [ApiKeyStatus::DAILY, ApiKeyStatus::KEY_LIMIT])),
            Tables\Filters\SelectFilter::make('endpoint')
                // From the API's routes, not a DISTINCT over the whole log (which grows large).
                ->options(fn (): array => collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
                    ->map(fn (Route $route): ?string => $route->getName())
                    ->filter(fn (?string $name): bool => is_string($name) && str_starts_with($name, 'v1.'))
                    // And the MCP server's tools (each call is logged as mcp.<tool>).
                    ->merge(\Illuminate\Support\Facades\Route::has('mcp')
                        ? array_map(fn (string $tool): string => 'mcp.'.$tool, ListenServer::toolNames()) : [])
                    ->unique()->sort()->mapWithKeys(fn (string $name): array => [$name => $name])->all()),
            Tables\Filters\SelectFilter::make('error_code')->label('Error')
                ->options(fn (): array => ApiRequestLog::query()->whereNotNull('error_code')->distinct()->orderBy('error_code')->pluck('error_code', 'error_code')->all()),
            Tables\Filters\Filter::make('today')->label('Today (from midnight Cairo time)')
                ->query(fn (Builder $query): Builder => $query->where('created_at', '>=', PlanUsageGuard::startOfMemberDay())),
        ];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Request')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('created_at')->label('When')->dateTime('Y-m-d H:i:s')
                        ->helperText(fn (ApiRequestLog $record): ?string => $record->created_at?->diffForHumans()),
                    Infolists\Components\TextEntry::make('method')->badge(),
                    Infolists\Components\TextEntry::make('path')->fontFamily('mono')->columnSpan(2),
                    Infolists\Components\TextEntry::make('endpoint')->placeholder('—'),
                    Infolists\Components\TextEntry::make('status')->badge()->color(fn (int $state): string => self::statusColor($state)),
                    Infolists\Components\TextEntry::make('error_code')->label('Error')->placeholder('None')
                        ->helperText(fn (ApiRequestLog $record): ?string => self::errorNote($record->error_code)),
                    Infolists\Components\TextEntry::make('duration_ms')->label('Time')->suffix(' ms')->numeric(),
                    Infolists\Components\TextEntry::make('credits')->numeric()->placeholder('None charged'),
                    Infolists\Components\TextEntry::make('characters')->label('Characters')->numeric()->placeholder('—'),
                    Infolists\Components\IconEntry::make('replayed')->label('Answered from a repeat (same idempotency key)')->boolean(),
                    Infolists\Components\TextEntry::make('history_item_id')->label('Generation it made')->placeholder('None')
                        ->url(fn (ApiRequestLog $record): ?string => self::generationUrl($record)),
                ]),
            Infolists\Components\Section::make('Who sent it')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('user.email')->label('Member')->copyable()->placeholder('—')
                        ->url(fn (ApiRequestLog $record): ?string => $record->user_id ? UserResource::getUrl('view', ['record' => $record->user_id]) : null),
                    Infolists\Components\TextEntry::make('apiKey.name')->label('Key')->placeholder('—')
                        ->helperText(fn (ApiRequestLog $record): ?string => $record->apiKey?->maskedKey())
                        ->url(fn (ApiRequestLog $record): ?string => $record->api_key_id ? ApiKeyResource::getUrl('view', ['record' => $record->api_key_id]) : null),
                    Infolists\Components\TextEntry::make('ip')->label('IP')->copyable()->placeholder('—'),
                    Infolists\Components\TextEntry::make('request_id')->label('Request id')->copyable()->fontFamily('mono'),
                    Infolists\Components\TextEntry::make('user_agent')->label('Client')->placeholder('—')->columnSpanFull(),
                    Infolists\Components\TextEntry::make('id')->label('Database id'),
                ]),
        ]);
    }

    public static function statusColor(int $status): string
    {
        return match (true) {
            $status >= 500 => 'danger',
            $status >= 400 => 'warning',
            default => 'success',
        };
    }

    public static function errorNote(?string $code): ?string
    {
        return match ($code) {
            ApiKeyStatus::DAILY => 'The member had made their plan\'s generations for the day (studio and API together).',
            ApiKeyStatus::KEY_LIMIT => 'This key had used its own monthly credit limit.',
            default => null,
        };
    }

    public static function generationUrl(ApiRequestLog $record): ?string
    {
        return $record->history_item_id ? rescue(fn (): string => AudioGenerationResource::getUrl('view', ['record' => $record->history_item_id]), null, false) : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApiRequestLogs::route('/'),
            'view' => Pages\ViewApiRequestLog::route('/{record}'),
        ];
    }
}
