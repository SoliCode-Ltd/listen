<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApiKeyResource\Pages;
use App\Models\ApiKey;
use App\Models\ApiKeyUsage;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every developer API key members have made, with this month's use. Keys
 * are made by members (Dashboard → Developer API); an admin can revoke one.
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
        $period = now()->format('Y-m');

        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user:id,name,email'))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('user.email')->label('Member')->searchable()->copyable()->placeholder('—'),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('last_four')
                    ->label('Key')
                    ->formatStateUsing(fn (ApiKey $record): string => $record->maskedKey())
                    ->fontFamily('mono'),
                Tables\Columns\TextColumn::make('scopes')
                    ->label('Permissions')
                    ->formatStateUsing(fn (ApiKey $record): string => $record->scopes === null ? 'All' : implode(', ', $record->scopes))
                    ->placeholder('All')
                    ->wrap(),
                Tables\Columns\TextColumn::make('month_credits')
                    ->label('Credits this month')
                    ->state(fn (ApiKey $record): int => $record->creditsUsed($period))
                    ->formatStateUsing(fn (int $state, ApiKey $record): string => number_format($state).($record->monthly_credit_limit ? ' / '.number_format($record->monthly_credit_limit) : '')),
                Tables\Columns\TextColumn::make('month_requests')
                    ->label('Requests this month')
                    ->state(fn (ApiKey $record): int => $record->requestsMade($period))
                    ->numeric(),
                Tables\Columns\TextColumn::make('last_used_at')->label('Last used')->since()->sortable()->placeholder('Never'),
                Tables\Columns\TextColumn::make('last_used_ip')->label('Last IP')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->label('Created')->dateTime()->sortable(),
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
            ])
            ->actions([
                Tables\Actions\Action::make('revoke')
                    ->label('Revoke')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (ApiKey $record): bool => ! $record->isRevoked())
                    ->requiresConfirmation()
                    ->modalDescription('The key stops working at once. The member can make a new one if their plan includes the API.')
                    ->action(function (ApiKey $record): void {
                        $record->revoke();
                        Notification::make()->title('Key revoked')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApiKeys::route('/'),
        ];
    }

    /** Total credits all keys used this month, for a quick look in the navigation. */
    public static function getNavigationBadge(): ?string
    {
        try {
            $credits = (int) ApiKeyUsage::query()->where('period_ym', now()->format('Y-m'))->sum('credits');
        } catch (\Throwable) {
            // Before the migration has run.
            return null;
        }

        return $credits > 0 ? number_format($credits) : null;
    }
}
