<?php

namespace App\Filament\Resources\NewsletterResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class DeliveriesRelationManager extends RelationManager
{
    protected static string $relationship = 'deliveries';

    protected static ?string $title = 'Send log';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                Tables\Columns\TextColumn::make('sent_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('locale')
                    ->label('Language')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'ar' => 'Arabic',
                        'both' => 'English and Arabic',
                        default => 'English',
                    }),
                Tables\Columns\IconColumn::make('is_test')
                    ->label('Example')
                    ->boolean(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'sent' ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('error')
                    ->limit(80)
                    ->tooltip(fn ($record): ?string => $record->error)
                    ->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
