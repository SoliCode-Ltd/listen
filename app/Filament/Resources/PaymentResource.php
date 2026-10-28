<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentResource\Pages;
use App\Models\Payment;
use App\Support\Countries;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Every payment members made, and how: card, Apple Pay, Google Pay, InstaPay or
 * Vodafone Cash (App\Services\Billing\PaymentLedger).
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 3;

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
            ->defaultSort('paid_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('paid_at')->label('Paid')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Member')->searchable()->placeholder('—')
                    ->description(fn (Payment $record): string => (string) ($record->user?->email ?? '')),
                Tables\Columns\TextColumn::make('user.country_code')->label('Country')
                    ->formatStateUsing(fn (?string $state) => Countries::label($state))->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('method')->label('Paid with')->badge()
                    ->formatStateUsing(fn (?string $state): string => Payment::methodLabel($state))
                    ->color(fn (?string $state): string => Payment::methodColor($state))
                    ->description(fn (Payment $record): string => (string) $record->cardLabel()),
                Tables\Columns\TextColumn::make('amount_minor')->label('Amount')->sortable()
                    ->formatStateUsing(fn (Payment $record): string => number_format($record->amount_minor / 100, 2).' '.$record->currency)
                    ->description(fn (Payment $record): string => match (true) {
                        $record->refunded_minor <= 0 => '',
                        $record->refunded_minor >= $record->amount_minor => 'Refunded',
                        default => number_format($record->refunded_minor / 100, 2).' refunded',
                    }),
                Tables\Columns\TextColumn::make('description')->label('For')->placeholder('—')->limit(50)->toggleable(),
                Tables\Columns\TextColumn::make('card_expiry')->label('Card expires')
                    ->state(fn (Payment $record): ?string => self::expiry($record))->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('card_funding')->label('Funding')
                    ->formatStateUsing(fn (?string $state): string => Str::headline((string) $state))->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('method')->label('Paid with')->options(Payment::METHODS),
                Tables\Filters\Filter::make('paid_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Paid from'),
                        Forms\Components\DatePicker::make('until')->label('Paid until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('paid_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('paid_at', '<=', $date))),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Payment')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('paid_at')->label('Paid')->dateTime(),
                    Infolists\Components\TextEntry::make('user.name')->label('Member')->placeholder('—'),
                    Infolists\Components\TextEntry::make('user.email')->label('Email')->copyable()->placeholder('—'),
                    Infolists\Components\TextEntry::make('user_id')->label('Member id')->placeholder('—'),
                    Infolists\Components\TextEntry::make('method')->label('Paid with')->badge()
                        ->formatStateUsing(fn (?string $state): string => Payment::methodLabel($state))
                        ->color(fn (?string $state): string => Payment::methodColor($state)),
                    Infolists\Components\TextEntry::make('amount_minor')->label('Amount')
                        ->formatStateUsing(fn (Payment $record): string => number_format($record->amount_minor / 100, 2).' '.$record->currency),
                    Infolists\Components\TextEntry::make('refunded_minor')->label('Refunded')
                        ->formatStateUsing(fn (Payment $record): string => $record->refunded_minor > 0 ? number_format($record->refunded_minor / 100, 2).' '.$record->currency : 'Nothing'),
                    Infolists\Components\TextEntry::make('description')->label('For')->placeholder('—'),
                    Infolists\Components\TextEntry::make('reference')->label('Reference')->copyable()->fontFamily('mono'),
                ]),
            Infolists\Components\Section::make('Card')
                ->description('The full card number and CVC are never stored. Stripe keeps those.')
                ->visible(fn (Payment $record): bool => (bool) ($record->card_brand || $record->card_last4))
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('card_brand')->label('Brand')->formatStateUsing(fn (?string $state): string => Str::headline((string) $state)),
                    Infolists\Components\TextEntry::make('card_last4')->label('Last 4'),
                    Infolists\Components\TextEntry::make('card_expiry')->label('Expires')
                        ->state(fn (Payment $record): ?string => self::expiry($record))->placeholder('—'),
                    Infolists\Components\TextEntry::make('card_funding')->label('Funding')
                        ->formatStateUsing(fn (?string $state): string => Str::headline((string) $state))->placeholder('—'),
                    Infolists\Components\TextEntry::make('card_country')->label('Issued in')
                        ->formatStateUsing(fn (?string $state) => Countries::label($state))->placeholder('—'),
                    Infolists\Components\TextEntry::make('card_cvc_check')->label('CVC check')
                        ->formatStateUsing(fn (?string $state): string => Str::headline((string) $state))->placeholder('Not checked'),
                    Infolists\Components\TextEntry::make('card_fingerprint')->label('Fingerprint')
                        ->helperText('The same card always has the same fingerprint, on any account.')
                        ->copyable()->fontFamily('mono')->placeholder('—'),
                    Infolists\Components\TextEntry::make('stripe_customer_id')->label('Stripe customer')->copyable()->fontFamily('mono')->placeholder('—'),
                    Infolists\Components\TextEntry::make('stripe_payment_method_id')->label('Stripe payment method')->copyable()->fontFamily('mono')->placeholder('—'),
                ]),
            Infolists\Components\Section::make('Card on file')
                ->description('This card is kept for the member\'s renewals and trials.')
                ->visible(fn (Payment $record): bool => $record->savedCard !== null)
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('savedCard.purpose')->label('Saved for')->badge()
                        ->formatStateUsing(fn (?string $state): string => Str::headline((string) $state)),
                    Infolists\Components\IconEntry::make('savedCard.is_default')->label('Default card')->boolean(),
                    Infolists\Components\IconEntry::make('savedCard.off_session_ready')->label('Can be charged without the member (off-session)')->boolean(),
                    Infolists\Components\TextEntry::make('savedCard.setup_intent_id')->label('Setup intent')->copyable()->fontFamily('mono')->placeholder('—'),
                    Infolists\Components\TextEntry::make('savedCard.created_at')->label('Saved')->dateTime(),
                ]),
        ]);
    }

    private static function expiry(Payment $record): ?string
    {
        return $record->card_exp_month && $record->card_exp_year
            ? str_pad((string) $record->card_exp_month, 2, '0', STR_PAD_LEFT).' / '.$record->card_exp_year
            : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
            'view' => Pages\ViewPayment::route('/{record}'),
        ];
    }
}
