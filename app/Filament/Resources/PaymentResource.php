<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentResource\Pages;
use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Filament\Resources\PaymentResource\Pages\ViewPayment;
use App\Models\Payment;
use App\Support\Countries;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response as AccessResponse;
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

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 3;

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
            ->defaultSort('paid_at', 'desc')
            ->columns([
                TextColumn::make('paid_at')->label('Paid')->dateTime()->sortable(),
                TextColumn::make('user.name')->label('Member')->searchable()->placeholder('—')
                    ->description(fn (Payment $record): string => (string) ($record->user?->email ?? '')),
                TextColumn::make('user.country_code')->label('Country')
                    ->formatStateUsing(fn (?string $state) => Countries::label($state))->placeholder('—')->toggleable(),
                TextColumn::make('method')->label('Paid with')->badge()
                    ->formatStateUsing(fn (?string $state): string => Payment::methodLabel($state))
                    ->color(fn (?string $state): string => Payment::methodColor($state))
                    ->description(fn (Payment $record): string => (string) $record->cardLabel()),
                TextColumn::make('amount_minor')->label('Amount')->sortable()
                    ->formatStateUsing(fn (Payment $record): string => number_format($record->amount_minor / 100, 2).' '.$record->currency)
                    ->description(fn (Payment $record): string => match (true) {
                        $record->refunded_minor <= 0 => '',
                        $record->refunded_minor >= $record->amount_minor => 'Refunded',
                        default => number_format($record->refunded_minor / 100, 2).' refunded',
                    }),
                TextColumn::make('description')->label('For')->placeholder('—')->limit(50)->toggleable(),
                TextColumn::make('card_expiry')->label('Card expires')
                    ->state(fn (Payment $record): ?string => self::expiry($record))->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('card_funding')->label('Funding')
                    ->formatStateUsing(fn (?string $state): string => Str::headline((string) $state))->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->filters([
                SelectFilter::make('method')->label('Paid with')->options(Payment::METHODS),
                Filter::make('paid_at')
                    ->form([
                        DatePicker::make('from')->label('Paid from'),
                        DatePicker::make('until')->label('Paid until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('paid_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('paid_at', '<=', $date))),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Payment')
                ->columns(3)
                ->schema([
                    TextEntry::make('paid_at')->label('Paid')->dateTime(),
                    TextEntry::make('user.name')->label('Member')->placeholder('—'),
                    TextEntry::make('user.email')->label('Email')->copyable()->placeholder('—'),
                    TextEntry::make('user_id')->label('Member id')->placeholder('—'),
                    TextEntry::make('method')->label('Paid with')->badge()
                        ->formatStateUsing(fn (?string $state): string => Payment::methodLabel($state))
                        ->color(fn (?string $state): string => Payment::methodColor($state)),
                    TextEntry::make('amount_minor')->label('Amount')
                        ->formatStateUsing(fn (Payment $record): string => number_format($record->amount_minor / 100, 2).' '.$record->currency),
                    TextEntry::make('refunded_minor')->label('Refunded')
                        ->formatStateUsing(fn (Payment $record): string => $record->refunded_minor > 0 ? number_format($record->refunded_minor / 100, 2).' '.$record->currency : 'Nothing'),
                    TextEntry::make('description')->label('For')->placeholder('—'),
                    TextEntry::make('reference')->label('Reference')->copyable()->fontFamily('mono'),
                ]),
            Section::make('Card')
                ->description('The full card number and CVC are never stored. Stripe keeps those.')
                ->visible(fn (Payment $record): bool => (bool) ($record->card_brand || $record->card_last4))
                ->columns(3)
                ->schema([
                    TextEntry::make('card_brand')->label('Brand')->formatStateUsing(fn (?string $state): string => Str::headline((string) $state)),
                    TextEntry::make('card_last4')->label('Last 4'),
                    TextEntry::make('card_expiry')->label('Expires')
                        ->state(fn (Payment $record): ?string => self::expiry($record))->placeholder('—'),
                    TextEntry::make('card_funding')->label('Funding')
                        ->formatStateUsing(fn (?string $state): string => Str::headline((string) $state))->placeholder('—'),
                    TextEntry::make('card_country')->label('Issued in')
                        ->formatStateUsing(fn (?string $state) => Countries::label($state))->placeholder('—'),
                    TextEntry::make('card_cvc_check')->label('CVC check')
                        ->formatStateUsing(fn (?string $state): string => Str::headline((string) $state))->placeholder('Not checked'),
                    TextEntry::make('card_fingerprint')->label('Fingerprint')
                        ->helperText('The same card always has the same fingerprint, on any account.')
                        ->copyable()->fontFamily('mono')->placeholder('—'),
                    TextEntry::make('stripe_customer_id')->label('Stripe customer')->copyable()->fontFamily('mono')->placeholder('—'),
                    TextEntry::make('stripe_payment_method_id')->label('Stripe payment method')->copyable()->fontFamily('mono')->placeholder('—'),
                ]),
            Section::make('Card on file')
                ->description('This card is kept for the member\'s renewals and trials.')
                ->visible(fn (Payment $record): bool => $record->savedCard !== null)
                ->columns(3)
                ->schema([
                    TextEntry::make('savedCard.purpose')->label('Saved for')->badge()
                        ->formatStateUsing(fn (?string $state): string => Str::headline((string) $state)),
                    IconEntry::make('savedCard.is_default')->label('Default card')->boolean(),
                    IconEntry::make('savedCard.off_session_ready')->label('Can be charged without the member (off-session)')->boolean(),
                    TextEntry::make('savedCard.setup_intent_id')->label('Setup intent')->copyable()->fontFamily('mono')->placeholder('—'),
                    TextEntry::make('savedCard.created_at')->label('Saved')->dateTime(),
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
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }
}
