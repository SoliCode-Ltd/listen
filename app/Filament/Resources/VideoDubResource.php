<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VideoDubResource\Pages;
use App\Models\VideoDub;
use App\Models\Voice;
use App\Services\Dubbing\VideoDubRunner;
use App\Support\VideoDubbing;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Every video members sent to "Dub my video": who, what it was dubbed into,
 * what it cost them and us, how long it took, the lines it said, and the
 * videos themselves while they are kept.
 */
class VideoDubResource extends Resource
{
    protected static ?string $model = VideoDub::class;

    protected static ?string $navigationIcon = 'heroicon-o-film';

    protected static ?string $navigationGroup = 'Video Dubbing';

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
        return \App\Support\VoiceFlag::label((string) $code, false);
    }

    /** The studio's name for the voice (the library card it was picked as), with the speech engine's. */
    public static function voiceLabel(VideoDub $dub): string
    {
        $id = preg_match('/^voice_(\d+)$/', (string) $dub->voice_key, $m) ? (int) $m[1] : null;
        $name = $id ? Voice::query()->whereKey($id)->value('display_name') : null;

        return $name ? $name.' ('.$dub->voice.')' : (string) $dub->voice;
    }

    public static function usd(?float $value, int $decimals = 4): string
    {
        return $value === null ? '—' : '$'.number_format($value, $decimals);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $seconds = fn (string $key) => fn (VideoDub $r): ?string => isset($r->report[$key]['seconds']) ? number_format((float) $r->report[$key]['seconds'], 1).' s' : null;

        return $infolist->schema([
            Infolists\Components\Section::make('Dub')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('user.email')->label('Member')->copyable(),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? (string) $state)
                        ->color(fn (?string $state): string => self::statusColor($state)),
                    Infolists\Components\TextEntry::make('is_free')
                        ->label('Paid by')
                        ->badge()
                        ->formatStateUsing(fn (bool $state): string => $state ? 'Free (with the mark)' : 'Plan credits')
                        ->color(fn (bool $state): string => $state ? 'gray' : 'primary'),
                    Infolists\Components\TextEntry::make('created_at')->label('Sent')->dateTime(),
                    Infolists\Components\TextEntry::make('original_name')->label('File')->placeholder('—'),
                    Infolists\Components\TextEntry::make('video_seconds')->label('Video length')->suffix(' s'),
                    Infolists\Components\TextEntry::make('language')->formatStateUsing(fn (?string $state): string => $state === 'en' ? 'English' : 'Arabic'),
                    Infolists\Components\TextEntry::make('dialect')->label('Dialect or accent')->formatStateUsing(fn (?string $state): string => self::dialectLabel($state)),
                    Infolists\Components\TextEntry::make('voice')->state(fn (VideoDub $r): string => self::voiceLabel($r)),
                    Infolists\Components\TextEntry::make('performance_style')->label('Style')->placeholder('—'),
                    Infolists\Components\TextEntry::make('emotion')->placeholder('—'),
                    Infolists\Components\TextEntry::make('report.segments')->label('Lines spoken')->placeholder('—'),
                    Infolists\Components\TextEntry::make('report.fit.sped_up')
                        ->label('Lines sped up to fit')
                        ->state(fn (VideoDub $r): ?string => isset($r->report['fit']) ? $r->report['fit']['sped_up'].' (fastest ×'.$r->report['fit']['max_tempo'].')' : null)
                        ->placeholder('—'),
                ]),
            Infolists\Components\Section::make('Why it failed')
                ->visible(fn (VideoDub $r): bool => $r->status === VideoDub::FAILED)
                ->schema([
                    Infolists\Components\TextEntry::make('error_message')->label('What the member saw')
                        ->state(fn (VideoDub $r): string => $r->errorText())->placeholder('—'),
                ]),
            Infolists\Components\Section::make('Videos')
                ->description(fn (VideoDub $r): string => $r->files_deleted_at
                    ? 'Deleted '.$r->files_deleted_at->diffForHumans().'.'
                    : 'Kept until '.$r->created_at?->copy()->addHours((int) config('listen.dubbing.keep_hours', 48))->toDayDateTimeString().'.')
                ->columns(2)
                ->schema([
                    Infolists\Components\ViewEntry::make('clean_video')
                        ->label('Without the mark')
                        ->view('filament.video-dub-video', ['kind' => 'clean']),
                    Infolists\Components\ViewEntry::make('preview_video')
                        ->label('With the Listen mark')
                        ->view('filament.video-dub-video', ['kind' => 'preview']),
                ]),
            Infolists\Components\Section::make('Money')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('reservation.credits')
                        ->label('Charged to the member')
                        ->state(fn (VideoDub $r): ?string => is_array($r->reservation)
                            ? number_format((int) $r->reservation['credits']).' credits ('.((bool) $r->reservation['billed_to_pack'] ? 'from a pack' : 'from the plan, '.$r->reservation['period']).')'
                            : null)
                        ->placeholder('Nothing'),
                    Infolists\Components\TextEntry::make('cost_total')
                        ->label('Cost to us (estimate)')
                        ->state(fn (VideoDub $r): string => self::usd(VideoDubbing::cost($r->report)['total'] ?? null)),
                    Infolists\Components\TextEntry::make('cost_model')
                        ->label('Writing out and rewriting')
                        ->state(fn (VideoDub $r): string => self::usd(VideoDubbing::cost($r->report)['model'] ?? null)),
                    Infolists\Components\TextEntry::make('cost_voice')
                        ->label('Voicing')
                        ->state(fn (VideoDub $r): string => self::usd(VideoDubbing::cost($r->report)['voice'] ?? null)),
                    Infolists\Components\TextEntry::make('report.transcribe.input_tokens')->label('Model tokens in')->numeric()->placeholder('—'),
                    Infolists\Components\TextEntry::make('report.transcribe.audio_tokens')->label('of which sound')->numeric()->placeholder('—'),
                    Infolists\Components\TextEntry::make('report.transcribe.output_tokens')->label('Model tokens out')->numeric()->placeholder('—'),
                    Infolists\Components\TextEntry::make('report.voicing.voiced_seconds')->label('Seconds voiced')->suffix(' s')->placeholder('—'),
                ]),
            Infolists\Components\Section::make('Time and server')
                ->columns(5)
                ->collapsed()
                ->schema([
                    Infolists\Components\TextEntry::make('t_write')->label('Writing out')->state($seconds('transcribe'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('t_voice')->label('Voicing')->state($seconds('voicing'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('t_mux')->label('Onto the picture')->state($seconds('mux'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('t_preview')->label('Adding the mark')->state($seconds('preview_encode'))->placeholder('—'),
                    Infolists\Components\TextEntry::make('report.total_seconds')->label('Total wait')->suffix(' s')->placeholder('—'),
                    Infolists\Components\TextEntry::make('report.transcribe.model')->label('Text model')->placeholder('—'),
                    Infolists\Components\TextEntry::make('report.input_bytes')->label('Upload size')
                        ->formatStateUsing(fn ($state): string => number_format((int) $state / 1048576, 1).' MB')->placeholder('—'),
                    Infolists\Components\TextEntry::make('report.output_bytes')->label('Dubbed video size')
                        ->formatStateUsing(fn ($state): string => number_format((int) $state / 1048576, 1).' MB')->placeholder('—'),
                ]),
            Infolists\Components\Section::make('What was said, and the dub')
                ->visible(fn (VideoDub $r): bool => ! empty($r->report['lines']))
                ->schema([
                    Infolists\Components\ViewEntry::make('lines')->hiddenLabel()->view('filament.video-dub-lines'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('user.email')
                    ->label('Member')
                    ->searchable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => self::statusColor($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('is_free')
                    ->label('Paid by')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Free' : 'Plan')
                    ->color(fn (bool $state): string => $state ? 'gray' : 'primary'),
                Tables\Columns\TextColumn::make('dialect')
                    ->formatStateUsing(fn (?string $state): string => self::dialectLabel($state))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('voice')
                    ->state(fn (VideoDub $r): string => self::voiceLabel($r))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('video_seconds')
                    ->label('Length')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 0).' s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('reservation')
                    ->label('Credits')
                    ->state(fn (VideoDub $r): ?string => is_array($r->reservation) ? number_format((int) $r->reservation['credits']) : null)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('cost')
                    ->label('Cost to us')
                    ->state(fn (VideoDub $r): string => self::usd(VideoDubbing::cost($r->report)['total'] ?? null)),
                Tables\Columns\TextColumn::make('report.total_seconds')
                    ->label('Wait')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 0).' s')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('files_deleted_at')
                    ->label('Videos kept')
                    ->state(fn (VideoDub $r): bool => $r->files_deleted_at === null && $r->status === VideoDub::READY)
                    ->boolean()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Sent')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(self::STATUSES),
                Tables\Filters\TernaryFilter::make('is_free')
                    ->label('Paid by')
                    ->trueLabel('Free dubs')
                    ->falseLabel('Plan credits'),
                Tables\Filters\SelectFilter::make('language')
                    ->options(['ar' => 'Arabic', 'en' => 'English']),
                Tables\Filters\SelectFilter::make('dialect')
                    ->options(VideoDubbing::dialects(false, 'ar') + VideoDubbing::dialects(false, 'en'))
                    ->searchable(),
                Tables\Filters\SelectFilter::make('period')
                    ->label('Sent')
                    ->options(['today' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'today' => $query->where('created_at', '>=', now()->startOfDay()),
                        '7', '30' => $query->where('created_at', '>=', now()->subDays((int) $data['value'])),
                        default => $query,
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::removeAction(Tables\Actions\Action::make('remove')),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('removeSelected')
                    ->label('Remove and give back')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(self::REMOVE_TEXT)
                    ->deselectRecordsAfterCompletion()
                    ->action(function (\Illuminate\Database\Eloquent\Collection $records): void {
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
     * @template T of \Filament\Actions\Action|\Filament\Tables\Actions\Action
     *
     * @param  T  $action
     * @return T
     */
    public static function removeAction(\Filament\Actions\Action|Tables\Actions\Action $action): \Filament\Actions\Action|Tables\Actions\Action
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

    /** Deletes a dub's videos now (they would go after keep_hours anyway); the row and its figures stay. */
    public static function deleteFiles(VideoDub $dub): void
    {
        Storage::disk('local')->deleteDirectory((string) $dub->folder);
        $dub->update(['files_deleted_at' => now()]);
        Notification::make()->title('Videos deleted')->success()->send();
    }

    /** Fails a dub stuck in progress and gives the member back what it was charged. */
    public static function failStuck(VideoDub $dub): void
    {
        app(VideoDubRunner::class)->fail((int) $dub->id, 'ui.dub.error_generic');
        Notification::make()->title('Marked failed; any credits were given back')->success()->send();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getWidgets(): array
    {
        return [\App\Filament\Widgets\VideoDubStats::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVideoDubs::route('/'),
            'view' => Pages\ViewVideoDub::route('/{record}'),
        ];
    }
}
