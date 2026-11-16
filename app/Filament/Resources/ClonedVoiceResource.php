<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClonedVoiceResource\Pages;
use App\Filament\Resources\ClonedVoiceResource\Pages\ListClonedVoices;
use App\Filament\Resources\ClonedVoiceResource\Pages\ViewClonedVoice;
use App\Jobs\ProcessVoiceCloningJob;
use App\Models\ClonedVoice;
use App\Services\VoiceCloningService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response as AccessResponse;
use Illuminate\Database\Eloquent\Collection;

class ClonedVoiceResource extends Resource
{
    protected static ?string $model = ClonedVoice::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-microphone';

    protected static string|\UnitEnum|null $navigationGroup = 'Voice Cloning';

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

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Voice')
                ->columns(3)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('user.email')
                        ->label('Owner')
                        ->copyable(),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? (string) $state)
                        ->color(fn (?string $state): string => self::statusColor($state)),
                    TextEntry::make('language_code')->label('Voice language'),
                    TextEntry::make('metadata.consent_language')
                        ->label('Consent language')
                        ->placeholder('Same as voice'),
                    TextEntry::make('source_type')
                        ->label('Source')
                        ->formatStateUsing(fn (?string $state): string => $state === 'recording' ? 'Recorded in browser' : 'Uploaded file'),
                    TextEntry::make('source_audio_duration_seconds')
                        ->label('Sample length')
                        ->suffix(' s'),
                    TextEntry::make('metadata.consent_duration_seconds')
                        ->label('Consent length')
                        ->suffix(' s')
                        ->placeholder('—'),
                    TextEntry::make('provider_voice_key')
                        ->label('Listen voice id')
                        ->copyable()
                        ->fontFamily('mono')
                        ->placeholder('Not created yet'),
                    TextEntry::make('created_at')->dateTime(),
                    TextEntry::make('updated_at')->label('Last change')->since(),
                    TextEntry::make('metadata.model')->label('Model')->placeholder('—'),
                ]),
            Section::make('Recordings')
                ->columns(2)
                ->schema([
                    ViewEntry::make('sample_audio')
                        ->label('Voice sample')
                        ->view('filament.cloned-voice-audio', ['kind' => 'sample']),
                    ViewEntry::make('consent_audio')
                        ->label('Consent sentence')
                        ->view('filament.cloned-voice-audio', ['kind' => 'consent']),
                    TextEntry::make('metadata.consent_script')
                        ->label('Sentence the user was asked to read')
                        ->columnSpanFull()
                        ->placeholder('—'),
                ]),
            Section::make('Why it failed')
                ->visible(fn (ClonedVoice $record): bool => $record->isFailed())
                ->schema([
                    TextEntry::make('metadata.failure_code')
                        ->label('Cause')
                        ->badge()
                        ->color('danger')
                        ->formatStateUsing(fn (?string $state): string => self::FAILURE_CODES[$state] ?? (string) $state),
                    TextEntry::make('public_message')
                        ->label('What the user sees')
                        ->state(fn (ClonedVoice $record): string => VoiceCloningService::publicFailureMessage($record)),
                    TextEntry::make('technical_detail')
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
                TextColumn::make('id')->sortable()->toggleable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (ClonedVoice $record): string => (string) $record->user?->email),
                TextColumn::make('user.email')
                    ->label('Owner')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => self::statusColor($state))
                    ->sortable(),
                TextColumn::make('metadata.failure_code')
                    ->label('Cause')
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn (?string $state): string => self::FAILURE_CODES[$state] ?? (string) $state)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('language_code')
                    ->label('Language')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('source_audio_duration_seconds')
                    ->label('Sample')
                    ->suffix(' s')
                    ->toggleable(),
                TextColumn::make('provider_voice_key')
                    ->label('Listen voice id')
                    ->fontFamily('mono')
                    ->copyable()
                    ->limit(22)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(self::STATUSES),
                SelectFilter::make('language_code')
                    ->label('Language')
                    ->options([
                        'ar-XA' => 'Arabic',
                        'en-US' => 'English (US)',
                        'en-GB' => 'English (UK)',
                        'en-AU' => 'English (AU)',
                    ]),
                SelectFilter::make('user')
                    ->relationship('user', 'email')
                    ->searchable()
                    ->preload()
                    ->label('Owner'),
            ])
            ->recordActions([
                ViewAction::make(),
                self::resendAction(),
                self::purgeAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('purge')
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

    public static function resendAction(): Action
    {
        return Action::make('resend')
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

    public static function purgeAction(): Action
    {
        return Action::make('purge')
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

    // Filament 4 checks this (pages, buttons and canCreate() alike): never allowed here.
    public static function getCreateAuthorizationResponse(): AccessResponse
    {
        return AccessResponse::deny();
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClonedVoices::route('/'),
            'view' => ViewClonedVoice::route('/{record}'),
        ];
    }
}
