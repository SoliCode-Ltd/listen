<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DesignedVoiceResource\Pages;
use App\Models\DesignedVoice;
use App\Services\VoiceDesignService;
use App\Support\AudioUrl;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * Voice Design → Designed voices: every voice members kept, and the takes
 * still waiting to be kept. A kept voice can be put on the Voice Design card,
 * where everyone hears it around the orb ("On the card").
 */
class DesignedVoiceResource extends Resource
{
    protected static ?string $model = DesignedVoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'Voice Design';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Designed voices';

    protected static ?string $modelLabel = 'designed voice';

    protected static ?string $pluralModelLabel = 'designed voices';

    public static function getNavigationBadge(): ?string
    {
        $count = DesignedVoice::query()->saved()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Kept voices';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Voice')
                    ->searchable()
                    ->weight('bold')
                    ->placeholder(fn (DesignedVoice $record): string => 'Take '.$record->take)
                    ->description(fn (DesignedVoice $record): string => (string) $record->user?->email),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === DesignedVoice::SAVED ? 'Kept' : 'Waiting to be kept')
                    ->color(fn (?string $state): string => $state === DesignedVoice::SAVED ? 'success' : 'gray')
                    ->sortable(),
                Tables\Columns\ViewColumn::make('preview_audio_path')
                    ->label('Take')
                    ->view('filament.designed-voice-audio'),
                Tables\Columns\TextColumn::make('description')
                    ->limit(70)
                    ->tooltip(fn (DesignedVoice $record): string => (string) $record->description)
                    ->searchable()
                    ->wrap()
                    ->extraAttributes(['style' => 'min-width: 18rem']),
                Tables\Columns\TextColumn::make('language')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => $state === 'en' ? 'English' : 'Arabic'),
                Tables\Columns\ToggleColumn::make('is_showcase')
                    ->label('On the card')
                    ->tooltip('Played around the orb on the Voice Design card, for everyone.')
                    ->disabled(fn (DesignedVoice $record): bool => ! $record->isSaved()),
                Tables\Columns\TextColumn::make('preview_text')
                    ->label('Line')
                    ->limit(50)
                    ->placeholder('Its own sample')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('key_name')
                    ->label('Key')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_used_at')
                    ->label('Last spoke')
                    ->since()
                    ->placeholder('Not yet')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Made')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([DesignedVoice::SAVED => 'Kept', DesignedVoice::DRAFT => 'Waiting to be kept'])
                    ->default(DesignedVoice::SAVED),
                Tables\Filters\TernaryFilter::make('is_showcase')->label('On the card'),
                Tables\Filters\SelectFilter::make('language')->options(['ar' => 'Arabic', 'en' => 'English']),
                Tables\Filters\SelectFilter::make('user')
                    ->relationship('user', 'email')
                    ->searchable()
                    ->label('Owner'),
            ])
            ->actions([
                Tables\Actions\Action::make('listen')
                    ->label('Open take')
                    ->icon('heroicon-o-play')
                    ->url(fn (DesignedVoice $record): ?string => AudioUrl::fromPath($record->preview_audio_path), shouldOpenInNewTab: true)
                    ->visible(fn (DesignedVoice $record): bool => filled($record->preview_audio_path)),
                Tables\Actions\Action::make('purge')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Deletes the voice from the speech service and its take. Audio the member already made with it stays in their history.')
                    ->action(function (DesignedVoice $record): void {
                        app(VoiceDesignService::class)->purge($record, $record->isSaved());
                        Notification::make()->title('Designed voice deleted')->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('purge')
                        ->label('Delete everywhere')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $service = app(VoiceDesignService::class);
                            $records->each(fn (DesignedVoice $voice) => $service->purge($voice, $voice->isSaved()));
                            Notification::make()->title('Designed voices deleted')->success()->send();
                        }),
                ]),
            ])
            ->emptyStateHeading('No designed voices yet')
            ->emptyStateDescription('Voices members design and keep in the dashboard appear here.');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDesignedVoices::route('/'),
        ];
    }
}
