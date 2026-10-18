<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApiRequestLogResource\Pages;
use App\Models\ApiRequestLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every developer API request (kept 90 days): who sent it, which endpoint,
 * how it ended and what it cost. Search by the request id a customer quotes.
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
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime('Y-m-d H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('user.email')->label('Member')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('apiKey.name')->label('Key')
                    ->description(fn (ApiRequestLog $record): ?string => $record->apiKey ? '…'.$record->apiKey->last_four : null)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('method')->badge()->color(fn (string $state): string => match ($state) {
                    'POST' => 'primary',
                    'DELETE' => 'danger',
                    default => 'gray',
                }),
                Tables\Columns\TextColumn::make('path')->searchable()->limit(48)->fontFamily('mono'),
                Tables\Columns\TextColumn::make('status')->badge()->sortable()->color(fn (int $state): string => match (true) {
                    $state >= 500 => 'danger',
                    $state >= 400 => 'warning',
                    default => 'success',
                }),
                Tables\Columns\TextColumn::make('error_code')->label('Error')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('credits')->numeric()->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('characters')->label('Chars')->numeric()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('duration_ms')->label('Time')->suffix(' ms')->numeric()->sortable(),
                Tables\Columns\IconColumn::make('replayed')->label('Replay')->boolean()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('history_item_id')->label('Generation')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('request_id')->label('Request id')->searchable()->copyable()->fontFamily('mono')->toggleable(),
                Tables\Columns\TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('user_agent')->label('Client')->limit(40)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('outcome')
                    ->options(['ok' => 'Succeeded', 'client' => 'Refused (4xx)', 'server' => 'Failed (5xx)'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'ok' => $query->where('status', '<', 400),
                        'client' => $query->whereBetween('status', [400, 499]),
                        'server' => $query->where('status', '>=', 500),
                        default => $query,
                    }),
                Tables\Filters\SelectFilter::make('endpoint')
                    ->options(fn (): array => ApiRequestLog::query()->whereNotNull('endpoint')->distinct()->orderBy('endpoint')->pluck('endpoint', 'endpoint')->all()),
                Tables\Filters\SelectFilter::make('error_code')->label('Error')
                    ->options(fn (): array => ApiRequestLog::query()->whereNotNull('error_code')->distinct()->orderBy('error_code')->pluck('error_code', 'error_code')->all()),
                Tables\Filters\Filter::make('today')->label('Today')
                    ->query(fn (Builder $query): Builder => $query->where('created_at', '>=', now()->startOfDay())),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApiRequestLogs::route('/'),
        ];
    }
}
