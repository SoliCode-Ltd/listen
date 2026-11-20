<?php

namespace App\Filament\Resources;

use Illuminate\Auth\Access\Response as AccessResponse;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\Action;
use App\Filament\Resources\CharPackPurchaseResource\Pages\ListCharPackPurchases;
use App\Filament\Resources\CharPackPurchaseResource\Pages;
use App\Models\CharPackPurchase;
use App\Services\Billing\CharPackCardPayment;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Every one-time purchase, by card or by InstaPay / Vodafone Cash: the character
 * pack, and extra credits for a paid plan (App\Services\Billing\ExtraCredits),
 * each with the credit period its credits went to.
 */
class CharPackPurchaseResource extends Resource
{
    protected static ?string $model = CharPackPurchase::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string | \UnitEnum | null $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'One-time purchases';

    protected static ?string $modelLabel = 'One-time purchase';

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
                TextColumn::make('kind')
                    ->label('What')
                    ->badge()
                    ->color(fn (?string $state): string => $state === CharPackPurchase::KIND_EXTRA_CREDITS ? 'info' : 'gray')
                    ->formatStateUsing(fn (?string $state, CharPackPurchase $record): string => $record->isExtraCredits()
                        ? 'Extra credits · '.$record->minutes().' min'
                        : 'Pack'),
                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->tooltip(fn (CharPackPurchase $record): string => $record->isExtraCredits() ? 'The plan the member was on when they bought them.' : 'The pack bought.')
                    ->placeholder('—'),
                TextColumn::make('chars')->label('Credits')->numeric(),
                TextColumn::make('period_ym')
                    ->label('Added to period')
                    ->formatStateUsing(fn (?string $state): string => $state ? (new \App\Models\UsageCounter(['period_ym' => $state]))->monthLabel() : '—')
                    ->placeholder('—')
                    ->toggleable(),
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
                SelectFilter::make('kind')->label('What')->options([
                    CharPackPurchase::KIND_EXTRA_CREDITS => 'Extra credits',
                    CharPackPurchase::KIND_PACK => 'Pack',
                ]),
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
                    ->label(fn (CharPackPurchase $record): string => $record->isExtraCredits() ? 'Take back credits' : 'Take back characters')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (CharPackPurchase $record): bool => $record->status === CharPackPurchase::STATUS_PAID)
                    ->requiresConfirmation()
                    ->modalDescription(fn (CharPackPurchase $record): string => $record->isExtraCredits()
                        ? 'Use this after refunding the payment. The member loses what is still left of their extra credits, up to this purchase; credits already used stay used. A card refund made in Stripe does this by itself.'
                        : 'Use this after refunding the payment. The member loses whatever is still unused of this pack; characters already spent stay spent. A card refund made in Stripe does this by itself.')
                    ->action(function (CharPackPurchase $record): void {
                        $taken = app(CharPackCardPayment::class)->refund($record);
                        Notification::make()
                            ->title(number_format($taken).($record->isExtraCredits() ? ' credits taken back' : ' characters taken back'))
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
