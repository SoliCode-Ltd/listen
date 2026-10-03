<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClonedVoiceResource\Pages;
use App\Jobs\ProcessVoiceCloningJob;
use App\Models\ClonedVoice;
use App\Services\VoiceCloningService;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ClonedVoiceResource extends Resource
{
    protected static ?string $model = ClonedVoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-microphone';

    protected static ?string $navigationGroup = 'Voice Cloning';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Cloned Voices';

    protected static ?string $modelLabel = 'cloned voice';

    protected static ?string $pluralModelLabel = 'cloned voices';

    protected static ?string $recordTitleAttribute = 'name';

    public const STATUSES = [
        'pending' => 'Queued',
        'validating' => 'Checking',
        'processing' => 'Building',
        'ready' => 'Ready',
        'failed' => 'Failed',
    ];

    public const FAILURE_CODES = [
        'consent_phrase' => 'Consent sentence not recognised',
        'consent' => 'Consent did not match',
        'duration' => 'Sample length',
        'audio' => 'Audio unusable',
        'region' => 'Country not supported',
        'key' => 'API key',
        'quota' => 'Quota',
        'network' => 'Network',
        'server' => 'Listen internal error',
        'provider' => 'Other Listen error',
    ];

    public static function getNavigationBadge(): ?string
    {
        $count = ClonedVoice::query()->where('status', 'ready')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Ready voices';
    }

    public static function statusColor(?string $state): string
    {
        return match ($state) {
            'ready' => 'success',
            'processing', 'validating', 'pending' => 'warning',
            'failed' => 'danger',
            default => 'gray',
        };
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Voice')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('name'),
                    Infolists\Components\TextEntry::make('user.email')
                        ->label('Owner')
                        ->copyable(),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? (string) $state)
                        ->color(fn (?string $state): string => self::statusColor($state)),
                    Infolists\Components\TextEntry::make('language_code')->label('Voice language'),
                    Infolists\Components\TextEntry::make('metadata.consent_language')
                        ->label('Consent language')
                        ->placeholder('Same as voice'),
                    Infolists\Components\TextEntry::make('source_type')
                        ->label('Source')
                        ->formatStateUsing(fn (?string $state): string => $state === 'recording' ? 'Recorded in browser' : 'Uploaded file'),
                    Infolists\Components\TextEntry::make('source_audio_duration_seconds')
                        ->label('Sample length')
                        ->suffix(' s'),
                    Infolists\Components\TextEntry::make('metadata.consent_duration_seconds')
                        ->label('Consent length')
                        ->suffix(' s')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('provider_voice_key')
                        ->label('Listen voice id')
                        ->copyable()
                        ->fontFamily('mono')
                        ->placeholder('Not created yet'),
                    Infolists\Components\TextEntry::make('created_at')->dateTime(),
                    Infolists\Components\TextEntry::make('updated_at')->label('Last change')->since(),
                    Infolists\Components\TextEntry::make('metadata.model')->label('Model')->placeholder('—'),
                ]),
            Infolists\Components\Section::make('Recordings')
                ->columns(2)
                ->schema([
                    Infolists\Components\ViewEntry::make('sample_audio')
                        ->label('Voice sample')
                        ->view('filament.cloned-voice-audio', ['kind' => 'sample']),
                    Infolists\Components\ViewEntry::make('consent_audio')
                        ->label('Consent sentence')
                        ->view('filament.cloned-voice-audio', ['kind' => 'consent']),
                    Infolists\Components\TextEntry::make('metadata.consent_script')
                        ->label('Sentence the user was asked to read')
                        ->columnSpanFull()
                        ->placeholder('—'),
                ]),
            Infolists\Components\Section::make('Why it failed')
                ->visible(fn (ClonedVoice $record): bool => $record->isFailed())
                ->schema([
                    Infolists\Components\TextEntry::make('metadata.failure_code')
                        ->label('Cause')
                        ->badge()
                        ->color('danger')
                        ->formatStateUsing(fn (?string $state): string => self::FAILURE_CODES[$state] ?? (string) $state),
                    Infolists\Components\TextEntry::make('public_message')
                        ->label('What the user sees')
                        ->state(fn (ClonedVoice $record): string => VoiceCloningService::publicFailureMessage($record)),
                    Infolists\Components\TextEntry::make('technical_detail')
                        ->label('Listen reply')
                        ->state(fn (ClonedVoice $record): string => VoiceCloningService::technicalFailureDetail($record))
                        ->formatStateUsing(fn (?string $state): string => nl2br(e((string) $state)))
                        ->html()
                        ->fontFamily('mono')
                        ->size('xs')
                        ->copyable()
                        ->copyableState(fn (ClonedVoice $record): string => VoiceCloningService::technicalFailureDetail($record))
                        ->extraAttributes(['style' => 'word-break: break-word;']),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (ClonedVoice $record): string => (string) $record->user?->email),
                Tables\Columns\TextColumn::make('user.email')
                    ->label('Owner')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => self::statusColor($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('metadata.failure_code')
                    ->label('Cause')
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn (?string $state): string => self::FAILURE_CODES[$state] ?? (string) $state)
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('language_code')
                    ->label('Language')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('source_audio_duration_seconds')
                    ->label('Sample')
                    ->suffix(' s')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('provider_voice_key')
                    ->label('Listen voice id')
                    ->fontFamily('mono')
                    ->copyable()
                    ->limit(22)
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(self::STATUSES),
                Tables\Filters\SelectFilter::make('language_code')
                    ->label('Language')
                    ->options([
                        'ar-XA' => 'Arabic',
                        'en-US' => 'English (US)',
                        'en-GB' => 'English (UK)',
                        'en-AU' => 'English (AU)',
                    ]),
                Tables\Filters\SelectFilter::make('user')
                    ->relationship('user', 'email')
                    ->searchable()
                    ->preload()
                    ->label('Owner'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::resendAction(),
                self::purgeAction(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('purge')
                        ->label('Delete everywhere')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('Deletes the Listen voice and both recordings. This cannot be undone.')
                        ->action(function (Collection $records): void {
                            $service = app(VoiceCloningService::class);
                            $records->each(fn (ClonedVoice $voice) => $service->purge($voice));
                            Notification::make()->title('Cloned voices deleted')->success()->send();
                        }),
                ]),
            ])
            ->emptyStateHeading('No cloned voices yet')
            ->emptyStateDescription('Voices created in the dashboard studio appear here.');
    }

    public static function resendAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('resend')
            ->label('Resend to Listen')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (ClonedVoice $record): bool => VoiceCloningService::canRetry($record))
            ->requiresConfirmation()
            ->modalDescription('Sends the saved recordings to Listen again.')
            ->action(function (ClonedVoice $record): void {
                self::resend($record);
            });
    }

    public static function purgeAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('purge')
            ->label('Delete')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Deletes the Listen voice and both recordings. This cannot be undone.')
            ->action(function (ClonedVoice $record): void {
                app(VoiceCloningService::class)->purge($record);
                Notification::make()->title('Cloned voice deleted')->success()->send();
            });
    }

    public static function resend(ClonedVoice $record): void
    {
        $record->update([
            'status' => 'pending',
            'failure_reason' => null,
            'metadata' => array_merge($record->metadata ?? [], [
                'failure_code' => null,
                'retried_at' => now()->toIso8601String(),
            ]),
        ]);

        (new ProcessVoiceCloningJob($record->id))->handle(app(VoiceCloningService::class));
        $record->refresh();

        $record->isReady()
            ? Notification::make()->title('Voice is ready')->body((string) $record->provider_voice_key)->success()->send()
            : Notification::make()
                ->title('Listen still rejected it')
                ->body(VoiceCloningService::publicFailureMessage($record))
                ->danger()
                ->send();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClonedVoices::route('/'),
            'view' => Pages\ViewClonedVoice::route('/{record}'),
        ];
    }
}
