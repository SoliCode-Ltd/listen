<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VideoDubResource\Pages;
use App\Filament\Resources\VideoDubResource\Pages\ListVideoDubs;
use App\Filament\Resources\VideoDubResource\Pages\ViewVideoDub;
use App\Filament\Widgets\VideoDubStats;
use App\Models\VideoDub;
use App\Models\Voice;
use App\Services\Dubbing\VideoDubRunner;
use App\Support\VideoDubbing;
use App\Support\VoiceFlag;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
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
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response as AccessResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Every video members sent to "Dub my video": who, what it was dubbed into,
 * what it cost them and us, how long it took, the lines it said, and the
 * videos themselves while they are kept.
 */
class VideoDubResource extends Resource
{
    protected static ?string $model = VideoDub::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-film';

    protected static string|\UnitEnum|null $navigationGroup = 'Video Dubbing';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Dubs';

    protected static ?string $modelLabel = 'video dub';

    protected static ?string $pluralModelLabel = 'video dubs';

    public const STATUSES = [
        VideoDub::QUEUED => 'Waiting',
        VideoDub::PROCESSING => 'Dubbing',
        VideoDub::READY => 'Ready',
        VideoDub::FAILED => 'Failed',
        VideoDub::REMOVED => 'Removed (given back)',
    ];

    public static function getNavigationBadge(): ?string
    {
        $count = rescue(fn () => VideoDub::query()->where('created_at', '>=', now()->startOfDay())->count(), 0, false);

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Dubs today';
    }

    public static function statusColor(?string $state): string
    {
        return match ($state) {
            VideoDub::READY => 'success',
            VideoDub::QUEUED, VideoDub::PROCESSING => 'warning',
            VideoDub::FAILED => 'danger',
            VideoDub::REMOVED => 'gray',
            default => 'gray',
        };
    }

    public static function dialectLabel(?string $code): string
    {
        return VoiceFlag::label((string) $code, false);
    }

    /** The studio's name for the voice (the library card it was picked as), with the speech engine's. */
    public static function voiceLabel(VideoDub $dub): string
    {
        $id = preg_match('/^voice_(\d+)$/', (string) $dub->voice_key, $m) ? (int) $m[1] : null;
        $name = $id ? Voice::query()->whereKey($id)->value('display_name') : null;

        return $name ? $name.' ('.$dub->voice.')' : (string) $dub->voice;
    }

    /** How far a failed dub got: the phrases found, what the text model answered, the lines written. */
    public static function failedSteps(VideoDub $dub): ?string
    {
        $r = (array) $dub->report;
        $steps = array_filter([
            isset($r['speech_map']) ? 'Speech found: '.(int) ($r['speech_map']['phrases'] ?? 0).' phrases ('.($r['speech_map']['method'] ?? '?').')' : null,
            isset($r['transcribe']['finish']) ? 'Text model answered: '.implode(', ', array_map(fn ($f): string => (string) $f ?: 'nothing', (array) $r['transcribe']['finish'])) : null,
            isset($r['segments']) ? 'Lines written: '.(int) $r['segments'] : null,
            isset($r['voicing']) ? 'Voiced: '.(int) ($r['voicing']['takes'] ?? 0).' takes' : null,
        ]);

        return $steps === [] ? null : implode(' · ', $steps);
    }

    public static function usd(?float $value, int $decimals = 4): string
    {
        return $value === null ? '—' : '$'.number_format($value, $decimals);
    }

    public static function infolist(Schema $schema): Schema
    {
        $seconds = fn (string $key) => fn (VideoDub $r): ?string => isset($r->report[$key]['seconds']) ? number_format((float) $r->report[$key]['seconds'], 1).' s' : null;

        return $schema->components([
            Section::make('Dub')
                ->columns(4)
                ->schema([
                    TextEntry::make('user.email')->label('Member')->copyable(),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? (string) $state)
                        ->color(fn (?string $state): string => self::statusColor($state)),
                    TextEntry::make('is_free')
                        ->label('Paid by')
                        ->badge()
                        ->formatStateUsing(fn (bool $state): string => $state ? 'Free (with the mark)' : 'Plan credits')
                        ->color(fn (bool $state): string => $state ? 'gray' : 'primary'),
                    TextEntry::make('created_at')->label('Sent')->dateTime(),
                    TextEntry::make('original_name')->label('File')->placeholder('—'),
                    TextEntry::make('video_seconds')->label('Video length')->suffix(' s'),
                    TextEntry::make('language')->formatStateUsing(fn (?string $state): string => $state === 'en' ? 'English' : 'Arabic'),
                    TextEntry::make('dialect')->label('Dialect or accent')->formatStateUsing(fn (?string $state): string => self::dialectLabel($state)),
                    TextEntry::make('voice')->state(fn (VideoDub $r): string => self::voiceLabel($r)),
                    TextEntry::make('performance_style')->label('Style')->placeholder('—'),
                    TextEntry::make('emotion')->placeholder('—'),
                    TextEntry::make('report.segments')->label('Lines spoken')->placeholder('—'),
                    TextEntry::make('report.separation.method')
                        ->label('Old voices taken out by')
                        ->formatStateUsing(fn (?string $state): string => match ($state) {
                            'model' => 'The separation model',
                            'filters' => 'Filters (the model was not set up, busy or failed)',
                            default => '—',
                        })
                        ->placeholder('—'),
                    TextEntry::make('report.fit.sped_up')
                        ->label('Lines sped up to fit')
                        ->state(fn (VideoDub $r): ?string => isset($r->report['fit']) ? $r->report['fit']['sped_up'].' (fastest ×'.$r->report['fit']['max_tempo'].')' : null)
                        ->placeholder('—'),
                ]),
            Section::make('Why it failed')
                ->visible(fn (VideoDub $r): bool => $r->status === VideoDub::FAILED)
                ->schema([
                    TextEntry::make('error_message')->label('What the member saw')
                        ->state(fn (VideoDub $r): string => $r->errorText())->placeholder('—'),
                    TextEntry::make('report.error')->label('What went wrong')->placeholder('—'),
                    TextEntry::make('steps')->label('How far it got')
                        ->state(fn (VideoDub $r): ?string => self::failedSteps($r))->placeholder('—'),
                ]),
            Section::make('Videos')
                ->description(fn (VideoDub $r): string => self::keptText($r))
                ->columns(2)
                ->schema([
                    ViewEntry::make('clean_video')
                        ->label('Without the mark')
                        ->view('filament.video-dub-video', ['kind' => 'clean']),
                    ViewEntry::make('preview_video')
                        ->label('With the Listen mark')
                        ->view('filament.video-dub-video', ['kind' => 'preview']),
                ]),
            Section::make('Money')
                ->columns(4)
                ->schema([
                    TextEntry::make('reservation.credits')
                        ->label('Charged to the member')
                        ->state(fn (VideoDub $r): ?string => is_array($r->reservation)
                            ? number_format((int) $r->reservation['credits']).' credits ('.((bool) $r->reservation['billed_to_pack'] ? 'from a pack' : 'from the plan, '.$r->reservation['period']).')'
                            : null)
                        ->placeholder('Nothing'),
                    TextEntry::make('cost_total')
                        ->label('Cost to us (estimate)')
                        ->state(fn (VideoDub $r): string => self::usd(VideoDubbing::cost($r->report)['total'] ?? null)),
                    TextEntry::make('cost_model')
                        ->label('Writing out and rewriting')
                        ->state(fn (VideoDub $r): string => self::usd(VideoDubbing::cost($r->report)['model'] ?? null)),
                    TextEntry::make('cost_voice')
                        ->label('Voicing')
                        ->state(fn (VideoDub $r): string => self::usd(VideoDubbing::cost($r->report)['voice'] ?? null)),
                    TextEntry::make('report.transcribe.input_tokens')->label('Model tokens in')->numeric()->placeholder('—'),
                    TextEntry::make('report.transcribe.audio_tokens')->label('of which sound')->numeric()->placeholder('—'),
                    TextEntry::make('report.transcribe.output_tokens')->label('Model tokens out')->numeric()->placeholder('—'),
                    TextEntry::make('report.voicing.voiced_seconds')->label('Seconds voiced')->suffix(' s')->placeholder('—'),
                ]),
            Section::make('Time and server')
                ->columns(5)
                ->collapsed()
                ->schema([
                    TextEntry::make('t_write')->label('Writing out')->state($seconds('transcribe'))->placeholder('—'),
                    TextEntry::make('t_voice')->label('Voicing')->state($seconds('voicing'))->placeholder('—'),
                    TextEntry::make('t_mux')->label('Onto the picture')->state($seconds('mux'))->placeholder('—'),
                    TextEntry::make('t_preview')->label('Adding the mark')->state($seconds('preview_encode'))->placeholder('—'),
                    TextEntry::make('report.total_seconds')->label('Total wait')->suffix(' s')->placeholder('—'),
                    TextEntry::make('report.transcribe.model')->label('Text model')->placeholder('—'),
                    TextEntry::make('report.input_bytes')->label('Upload size')
                        ->formatStateUsing(fn ($state): string => number_format((int) $state / 1048576, 1).' MB')->placeholder('—'),
                    TextEntry::make('report.output_bytes')->label('Dubbed video size')
                        ->formatStateUsing(fn ($state): string => number_format((int) $state / 1048576, 1).' MB')->placeholder('—'),
                ]),
            Section::make('What was said, and the dub')
                ->visible(fn (VideoDub $r): bool => ! empty($r->report['lines']))
                ->schema([
                    ViewEntry::make('lines')->hiddenLabel()->view('filament.video-dub-lines'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable()->toggleable(),
                TextColumn::make('user.email')
                    ->label('Member')
                    ->searchable()
                    ->limit(30),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => self::statusColor($state))
                    ->sortable(),
                TextColumn::make('is_free')
                    ->label('Paid by')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Free' : 'Plan')
                    ->color(fn (bool $state): string => $state ? 'gray' : 'primary'),
                TextColumn::make('dialect')
                    ->formatStateUsing(fn (?string $state): string => self::dialectLabel($state))
                    ->toggleable(),
                TextColumn::make('voice')
                    ->state(fn (VideoDub $r): string => self::voiceLabel($r))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('video_seconds')
                    ->label('Length')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 0).' s')
                    ->sortable(),
                TextColumn::make('reservation')
                    ->label('Credits')
                    ->state(fn (VideoDub $r): ?string => is_array($r->reservation) ? number_format((int) $r->reservation['credits']) : null)
                    ->placeholder('—'),
                TextColumn::make('cost')
                    ->label('Cost to us')
                    ->state(fn (VideoDub $r): string => self::usd(VideoDubbing::cost($r->report)['total'] ?? null)),
                TextColumn::make('report.total_seconds')
                    ->label('Wait')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 0).' s')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('files_deleted_at')
                    ->label('Video kept in')
                    ->state(fn (VideoDub $r): string => match (true) {
                        filled($r->drive_file_id) => 'Google Drive',
                        $r->status === VideoDub::READY && $r->files_deleted_at === null => 'Server',
                        $r->status === VideoDub::READY => 'Deleted',
                        default => '—',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Google Drive' => 'success',
                        'Server' => 'info',
                        default => 'gray',
                    })
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Sent')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(self::STATUSES),
                TernaryFilter::make('is_free')
                    ->label('Paid by')
                    ->trueLabel('Free dubs')
                    ->falseLabel('Plan credits'),
                SelectFilter::make('language')
                    ->options(['ar' => 'Arabic', 'en' => 'English']),
                SelectFilter::make('dialect')
                    ->options(VideoDubbing::dialects(false, 'ar') + VideoDubbing::dialects(false, 'en'))
                    ->searchable(),
                SelectFilter::make('period')
                    ->label('Sent')
                    ->options(['today' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'today' => $query->where('created_at', '>=', now()->startOfDay()),
                        '7', '30' => $query->where('created_at', '>=', now()->subDays((int) $data['value'])),
                        default => $query,
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                self::removeAction(Action::make('remove')),
            ])
            ->toolbarActions([
                BulkAction::make('removeSelected')
                    ->label('Remove and give back')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(self::REMOVE_TEXT)
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $count = $records->filter(fn (VideoDub $dub): bool => app(VideoDubRunner::class)->remove((int) $dub->id))->count();
                        Notification::make()->title($count.' dub(s) removed and given back')->success()->send();
                    }),
            ]);
    }

    public const REMOVE_TEXT = 'Deletes the video and gives back what it took, so the member can dub again: their free dub, or the credits and this month\'s video. The dub stays in this list, marked removed. This cannot be undone.';

    /**
     * Remove: the video goes and what it took comes back to the member
     * (VideoDubRunner::remove()), for a row of the list or a dub's page.
     *
     * @template T of Action
     *
     * @param  T  $action
     * @return T
     */
    public static function removeAction(Action $action): Action
    {
        return $action
            ->label('Remove')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (VideoDub $record): bool => ! $record->isWorking() && $record->status !== VideoDub::REMOVED)
            ->requiresConfirmation()
            ->modalHeading('Remove this dub and give it back?')
            ->modalDescription(self::REMOVE_TEXT)
            ->modalSubmitActionLabel('Remove and give back')
            ->action(function (VideoDub $record): void {
                app(VideoDubRunner::class)->remove((int) $record->id)
                    ? Notification::make()->title('Removed: the member can dub again')->success()->send()
                    : Notification::make()->title('Not removed: it is still being dubbed, or was removed already')->warning()->send();
            });
    }

    /** Where the videos are, and until when the member sees them. */
    public static function keptText(VideoDub $dub): string
    {
        $until = VideoDubbing::availableUntil($dub);
        $member = $dub->status !== VideoDub::READY ? '' : ($until === null
            ? ' The member sees it for as long as their plan keeps voices (no limit).'
            : ($until->isFuture() ? ' The member sees it until '.$until->toDayDateTimeString().'.' : ' The member no longer sees it (since '.$until->toDayDateTimeString().').'));

        return match (true) {
            filled($dub->drive_file_id) => 'In Google Drive (Listen Studio) since '.$dub->archived_at?->toDayDateTimeString().', not on the server.'.$member,
            $dub->files_deleted_at !== null => 'Deleted '.$dub->files_deleted_at->diffForHumans().'.',
            default => 'On the server.'.($dub->status === VideoDub::READY && is_array($dub->reservation) ? ' It moves to Google Drive once Drive is connected (Video Dubbing → Settings).' : '').$member,
        };
    }

    /** Deletes a dub's videos now, from the server and Google Drive; the row and its figures stay. */
    public static function deleteFiles(VideoDub $dub): void
    {
        Storage::disk('local')->deleteDirectory((string) $dub->folder);
        if ($dub->drive_file_id) {
            app(VideoDubRunner::class)->deleteFromDrive($dub);
        }
        $dub->update(['files_deleted_at' => now()]);
        Notification::make()->title('Videos deleted')->success()->send();
    }

    /** Fails a dub stuck in progress and gives the member back what it was charged. */
    public static function failStuck(VideoDub $dub): void
    {
        app(VideoDubRunner::class)->fail((int) $dub->id, 'ui.dub.error_generic');
        Notification::make()->title('Marked failed; any credits were given back')->success()->send();
    }

    // Filament 4 checks this (pages, buttons and canCreate() alike): never allowed here.
    public static function getCreateAuthorizationResponse(): AccessResponse
    {
        return AccessResponse::deny();
    }

    public static function getWidgets(): array
    {
        return [VideoDubStats::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVideoDubs::route('/'),
            'view' => ViewVideoDub::route('/{record}'),
        ];
    }
}
