<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CharPackPurchaseResource\Pages;
use App\Models\CharPackPurchase;
use App\Services\Billing\CharPackService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Every character pack bought, by card or by InstaPay / Vodafone Cash.
 */
class CharPackPurchaseResource extends Resource
{
    protected static ?string $model = CharPackPurchase::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Pack purchases';

    protected static ?string $modelLabel = 'Pack purchase';

    protected static ?int $navigationSort = 6;

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
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('user.email')->label('Member')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('plan.name')->label('Pack')->placeholder('—'),
                Tables\Columns\TextColumn::make('chars')->label('Characters')->numeric(),
                Tables\Columns\TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->formatStateUsing(fn (CharPackPurchase $record): string => number_format($record->amount_minor / 100, 2).' '.$record->currency),
                Tables\Columns\TextColumn::make('method')
                    ->label('Paid by')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'card' => 'Card',
                        'instapay' => 'InstaPay',
                        'vodafone_cash' => 'Vodafone Cash',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        CharPackPurchase::STATUS_PAID => 'success',
                        CharPackPurchase::STATUS_FAILED => 'danger',
                        CharPackPurchase::STATUS_REFUNDED => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('failure_message')->label('Why it failed')->limit(60)->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('paid_at')->dateTime()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('Started')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    CharPackPurchase::STATUS_PAID => 'Paid',
                    CharPackPurchase::STATUS_PENDING => 'Pending',
                    CharPackPurchase::STATUS_FAILED => 'Failed',
                    CharPackPurchase::STATUS_REFUNDED => 'Refunded',
                ]),
                Tables\Filters\SelectFilter::make('method')->label('Paid by')->options([
                    'card' => 'Card',
                    'instapay' => 'InstaPay',
                    'vodafone_cash' => 'Vodafone Cash',
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('takeBack')
                    ->label('Take back characters')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (CharPackPurchase $record): bool => $record->status === CharPackPurchase::STATUS_PAID)
                    ->requiresConfirmation()
                    ->modalDescription('Use this after refunding the payment. The member loses whatever is still unused of this pack; characters already spent stay spent. A card refund made in Stripe does this by itself.')
                    ->action(function (CharPackPurchase $record): void {
                        $taken = app(CharPackService::class)->refund($record);
                        Notification::make()
                            ->title(number_format($taken).' characters taken back')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCharPackPurchases::route('/'),
        ];
    }
}
