<?php

namespace App\Filament\Resources\NewsletterResource\RelationManagers;

use App\Models\Newsletter;
use App\Services\Newsletter\NewsletterSender;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class DeliveriesRelationManager extends RelationManager
{
    protected static string $relationship = 'deliveries';

    protected static ?string $title = 'Send log';

    public function isReadOnly(): bool
    {
        return false;
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
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'sent' => 'Sent',
                        'queued' => 'Queued',
                        'sending' => 'Sending',
                        'failed' => 'Failed',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'sent' => 'success',
                        'queued' => 'warning',
                        'sending' => 'info',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('error')
                    ->limit(80)
                    ->tooltip(fn ($record): ?string => $record->error)
                    ->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->headerActions([
                Tables\Actions\Action::make('clearLog')
                    ->label('Clear log')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Clear the send log')
                    ->modalDescription('Removes every row for this newsletter. If a sent row is removed, that address can be included again the next time this newsletter skips people who already received it.')
                    ->modalSubmitActionLabel('Clear log')
                    ->action(function (): void {
                        $newsletter = $this->getOwnerRecord();
                        $newsletter->deliveries()->delete();
                        if ($newsletter instanceof Newsletter) {
                            app(NewsletterSender::class)->cancelQueued($newsletter);
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->label('Remove')
                    ->modalHeading('Remove this log row')
                    ->modalDescription('If this row was a successful send, that address can be included again the next time this newsletter skips people who already received it.')
                    ->modalSubmitActionLabel('Remove'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Remove selected'),
                ]),
            ])
            ->emptyStateHeading('No sends yet')
            ->emptyStateDescription('Example sends and audience sends are listed here. You can remove rows when the log gets long.');
    }
}
