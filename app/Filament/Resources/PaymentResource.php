<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentResource\Pages;
use App\Models\Payment;
use App\Support\Countries;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
        ];
    }
}
