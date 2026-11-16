<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TranscriptionResource\Pages\ListTranscriptions;
use App\Filament\Resources\TranscriptionResource\Pages\ViewTranscription;
use App\Filament\Widgets\TranscriptionStats;
use App\Models\Transcription;
use App\Services\Transcription\TranscriptionFlow;
use App\Support\Transcription\Minutes;
use App\Support\Transcription\TranscriptionPresenter;
use App\Support\Transcription\TranscriptionSettings;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response as AccessResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Storage;

/**
 * Every recording members turned into text (on the page, the API and MCP):
 * who, how long, the minutes it used of their plan (or the free try), what it
 * cost us, how long they waited, why one failed, the text itself and the
 * recording while it is kept. A transcript the member deleted stays here (it
 * still counts in their month), marked as deleted.
 */
class TranscriptionResource extends Resource
{
    protected static ?string $model = Transcription::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|\UnitEnum|null $navigationGroup = 'Speech to text';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Transcripts';

    protected static ?string $modelLabel = 'transcript';

    protected static ?string $pluralModelLabel = 'transcripts';

    public const STATUSES = [
        Transcription::QUEUED => 'Waiting',
        Transcription::PROCESSING => 'Writing out',
        Transcription::READY => 'Ready',
        Transcription::FAILED => 'Failed (given back)',
    ];

    public const SOURCES = [
        'upload' => 'Uploaded file',
        'record' => 'Recorded on the page',
        'url' => 'Link',
        'api' => 'API',
        'mcp' => 'MCP',
        'demo' => 'Demo',
    ];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = rescue(fn () => Transcription::withTrashed()->where('created_at', '>=', now()->startOfDay())->count(), 0, false);

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Transcripts today';
    }

    public static function statusColor(?string $state): string
    {
        return match ($state) {
            Transcription::READY => 'success',
            Transcription::QUEUED, Transcription::PROCESSING => 'warning',
            Transcription::FAILED => 'danger',
            default => 'gray',
        };
    }

    public static function length(float|int|null $seconds): string
    {
        return $seconds === null ? '—' : Minutes::format((int) round((float) $seconds), 'en');
    }

    public static function usd(?float $value, int $decimals = 4): string
    {
        return $value === null ? '—' : '$'.number_format($value, $decimals);
    }

    /** From sending it to the text being ready (or failing). */
    public static function wait(Transcription $t): ?string
    {
        return $t->finished_at && $t->created_at ? number_format(max(0, $t->created_at->diffInRealSeconds($t->finished_at)), 0).' s' : null;
    }

    /** The language it was written out in: as detected, or as the member chose it. */
    public static function languageLabel(Transcription $t): string
    {
        $code = (string) ($t->language ?: '');
        $asked = (string) $t->language_requested;
        if ($code === '') {
            return $asked === 'auto' ? '—' : TranscriptionPresenter::languageName($asked, false);
        }

        return TranscriptionPresenter::languageName($code, false).($asked === 'auto' ? ' (detected)' : '');
    }

    public static function infolist(Schema $schema): Schema
    {
        $usage = fn (string $key) => fn (Transcription $t): ?string => isset($t->usage[$key]) ? number_format((int) $t->usage[$key]) : null;

        return $schema->components([
            Section::make('Transcript')
                ->columns(4)
                ->schema([
                    TextEntry::make('user.email')->label('Member')->copyable()->placeholder('Account deleted'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? (string) $state)
                        ->color(fn (?string $state): string => self::statusColor($state)),
                    TextEntry::make('source')->label('From')->formatStateUsing(fn (?string $state): string => self::SOURCES[$state] ?? (string) $state),
                    TextEntry::make('created_at')->label('Sent')->dateTime(),
                    TextEntry::make('title')->columnSpan(2)->extraAttributes(['dir' => 'auto']),
                    TextEntry::make('seconds')->label('Length')->formatStateUsing(fn ($state): string => self::length($state)),
                    TextEntry::make('language')->label('Language')->state(fn (Transcription $t): string => self::languageLabel($t)),
                    TextEntry::make('speakers')->label('Speakers')->state(fn (Transcription $t): ?int => $t->status === Transcription::READY ? $t->speakerCount() : null)->placeholder('—'),
                    TextEntry::make('parts')->label('Parts heard')->state(fn (Transcription $t): string => $t->parts_done.' of '.$t->parts),
                    TextEntry::make('deleted_at')->label('Deleted by the member')->dateTime()->placeholder('No'),
                    TextEntry::make('source_url')->label('Link')->limit(60)->url(fn (Transcription $t): ?string => $t->source_url)->openUrlInNewTab()->placeholder('—'),
                ]),
            Section::make('Why it failed')
                ->visible(fn (Transcription $t): bool => $t->status === Transcription::FAILED)
                ->columns(3)
                ->schema([
                    TextEntry::make('error')->label('What the member saw')->columnSpan(2)->extraAttributes(['dir' => 'auto'])->placeholder('—'),
                    TextEntry::make('error_code')->label('Reason')->placeholder('—'),
                ]),
            Section::make('Minutes and money')
                ->columns(4)
                ->schema([
                    TextEntry::make('is_free')
                        ->label('Paid by')
                        ->badge()
                        ->formatStateUsing(fn (bool $state): string => $state ? 'The free try' : 'Plan minutes')
                        ->color(fn (bool $state): string => $state ? 'gray' : 'primary'),
                    TextEntry::make('used')->label('Minutes used')->state(fn (Transcription $t): string => $t->usedSeconds() > 0 ? self::length($t->usedSeconds()) : 'None'),
                    TextEntry::make('period_ym')->label('Counted in the month of')->placeholder('—'),
                    TextEntry::make('cost_usd')->label('Cost to us')->state(fn (Transcription $t): string => self::usd((float) $t->cost_usd)),
                    TextEntry::make('requests')->label('Requests to the model')->numeric(),
                    TextEntry::make('tokens_in')->label('Tokens in')->state($usage('input_tokens'))->placeholder('—'),
                    TextEntry::make('tokens_audio')->label('of which sound')->state($usage('audio_tokens'))->placeholder('—'),
                    TextEntry::make('tokens_out')->label('Tokens out')->state($usage('output_tokens'))->placeholder('—'),
                    TextEntry::make('model')->label('Model')->placeholder('—'),
                    TextEntry::make('apiKey')->label('API key')->state(fn (Transcription $t): ?string => $t->api_key_id ? '#'.$t->api_key_id : null)->placeholder('—'),
                ]),
            Section::make('Time')
                ->columns(4)
                ->schema([
                    TextEntry::make('started_at')->label('Started')->dateTime()->placeholder('—'),
                    TextEntry::make('finished_at')->label('Finished')->dateTime()->placeholder('—'),
                    TextEntry::make('wait')->label('Total wait')->state(fn (Transcription $t): ?string => self::wait($t))->placeholder('—'),
                    TextEntry::make('priority')->label('Queue')->formatStateUsing(fn (bool $state): string => $state ? 'Priority' : 'Standard'),
                ]),
            Section::make('Recording')
                ->description(fn (Transcription $t): string => match (true) {
                    $t->audio_deleted_at !== null => 'Deleted '.$t->audio_deleted_at->diffForHumans().'. The text stays.',
                    default => 'Kept on the server for '.TranscriptionSettings::keepAudioDays().' days, then deleted (the text stays).',
                })
                ->schema([
                    ViewEntry::make('audio')->hiddenLabel()->view('filament.transcription-audio'),
                ]),
            Section::make('What was said')
                ->visible(fn (Transcription $t): bool => ! empty($t->paragraphs))
                ->schema([
                    ViewEntry::make('paragraphs')->hiddenLabel()->view('filament.transcription-text'),
                ]),
            Section::make('Notes')
                ->description('How the work went, step by step (times placed where heard, lengths, retries).')
                ->collapsed()
                ->visible(fn (Transcription $t): bool => ! empty($t->notes))
                ->schema([
                    TextEntry::make('notes')->hiddenLabel()->listWithLineBreaks()->bulleted(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable()->toggleable(),
                TextColumn::make('user.email')->label('Member')->searchable()->limit(30)->placeholder('Account deleted'),
                TextColumn::make('title')->searchable()->limit(28)->extraAttributes(['dir' => 'auto'])->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => self::statusColor($state))
                    ->sortable(),
                TextColumn::make('source')->label('From')->formatStateUsing(fn (?string $state): string => self::SOURCES[$state] ?? (string) $state)->toggleable(),
                TextColumn::make('language')->state(fn (Transcription $t): string => self::languageLabel($t))->toggleable(),
                TextColumn::make('seconds')->label('Length')->formatStateUsing(fn ($state): string => self::length($state))->sortable(),
                TextColumn::make('used')->label('Used')->state(fn (Transcription $t): string => $t->is_free ? 'Free try' : ($t->usedSeconds() > 0 ? self::length($t->usedSeconds()) : '—')),
                TextColumn::make('cost_usd')->label('Cost to us')->formatStateUsing(fn ($state): string => self::usd((float) $state))->sortable(),
                TextColumn::make('wait')->label('Wait')->state(fn (Transcription $t): ?string => self::wait($t))->placeholder('—')->toggleable(),
                TextColumn::make('deleted_at')->label('Member deleted it')->since()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Sent')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(self::STATUSES),
                TernaryFilter::make('is_free')->label('Paid by')->trueLabel('The free try')->falseLabel('Plan minutes'),
                SelectFilter::make('source')->label('From')->options(self::SOURCES),
                SelectFilter::make('period')
                    ->label('Sent')
                    ->options(['today' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'today' => $query->where('created_at', '>=', now()->startOfDay()),
                        '7', '30' => $query->where('created_at', '>=', now()->subDays((int) $data['value'])),
                        default => $query,
                    }),
                TrashedFilter::make()->label('Deleted by the member'),
            ])
            ->recordActions([
                ViewAction::make(),
                self::stopAction(Action::make('stop')),
            ]);
    }

    /**
     * Stop a transcript that is stuck, and give the member back the minutes held for it
     * (TranscriptionFlow::fail(), the same as the stuck check does).
     *
     * @template T of Action
     *
     * @param  T  $action
     * @return T
     */
    public static function stopAction(Action $action): Action
    {
        return $action
            ->label('Stop and give back')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (Transcription $record): bool => $record->isWorking())
            ->requiresConfirmation()
            ->modalHeading('Stop this transcript?')
            ->modalDescription('For one that has been stuck a long time. It is marked failed and the member gets back the minutes it held. Its work so far is not kept.')
            ->modalSubmitActionLabel('Stop and give back')
            ->action(function (Transcription $record): void {
                app(TranscriptionFlow::class)->fail($record, 'admin', __('transcribe.failed.stuck'))
                    ? Notification::make()->title('Stopped: the minutes were given back')->success()->send()
                    : Notification::make()->title('Not stopped: it had already finished')->warning()->send();
            });
    }

    /** Deletes the recording now (the text and the figures stay), as the keep-audio days would. */
    public static function deleteAudio(Transcription $t): void
    {
        Storage::disk(TranscriptionFlow::DISK)->deleteDirectory(TranscriptionFlow::folder($t));
        $t->forceFill(['audio_path' => null, 'audio_deleted_at' => now()])->saveQuietly();
        Notification::make()->title('Recording deleted; the text stays')->success()->send();
    }

    // Filament 4 checks this (pages, buttons and canCreate() alike): never allowed here.
    public static function getCreateAuthorizationResponse(): AccessResponse
    {
        return AccessResponse::deny();
    }

    public static function getWidgets(): array
    {
        return [TranscriptionStats::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTranscriptions::route('/'),
            'view' => ViewTranscription::route('/{record}'),
        ];
    }
}
