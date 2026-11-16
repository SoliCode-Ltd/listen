<?php

namespace App\Filament\Resources;

use Illuminate\Auth\Access\Response as AccessResponse;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\Action;
use App\Filament\Resources\CharPackPurchaseResource\Pages\ListCharPackPurchases;
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

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string | \UnitEnum | null $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Pack purchases';

    protected static ?string $modelLabel = 'Pack purchase';

    protected static ?int $navigationSort = 6;

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
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('user.email')->label('Member')->searchable()->copyable(),
                TextColumn::make('plan.name')->label('Pack')->placeholder('—'),
                TextColumn::make('chars')->label('Characters')->numeric(),
                TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->formatStateUsing(fn (CharPackPurchase $record): string => number_format($record->amount_minor / 100, 2).' '.$record->currency),
                TextColumn::make('method')
                    ->label('Paid by')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'card' => 'Card',
                        'instapay' => 'InstaPay',
                        'vodafone_cash' => 'Vodafone Cash',
                        default => $state,
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        CharPackPurchase::STATUS_PAID => 'success',
                        CharPackPurchase::STATUS_FAILED => 'danger',
                        CharPackPurchase::STATUS_REFUNDED => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('failure_message')->label('Why it failed')->limit(60)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('paid_at')->dateTime()->sortable()->placeholder('—'),
                TextColumn::make('created_at')->label('Started')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    CharPackPurchase::STATUS_PAID => 'Paid',
                    CharPackPurchase::STATUS_PENDING => 'Pending',
                    CharPackPurchase::STATUS_FAILED => 'Failed',
                    CharPackPurchase::STATUS_REFUNDED => 'Refunded',
                ]),
                SelectFilter::make('method')->label('Paid by')->options([
                    'card' => 'Card',
                    'instapay' => 'InstaPay',
                    'vodafone_cash' => 'Vodafone Cash',
                ]),
            ])
            ->recordActions([
                Action::make('takeBack')
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
            'index' => ListCharPackPurchases::route('/'),
        ];
    }
}
