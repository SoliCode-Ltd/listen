<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\AudioGenerationResource\Pages\ListAudioGenerations;
use App\Filament\Resources\AudioGenerationResource\Pages\CreateAudioGeneration;
use App\Filament\Resources\AudioGenerationResource\Pages\ViewAudioGeneration;
use App\Filament\Resources\AudioGenerationResource\Pages\EditAudioGeneration;
use App\Filament\Resources\AudioGenerationResource\Pages;
use App\Models\AudioGeneration;
use App\Models\GenerationError;
use App\Models\TtsLog;
use App\Models\User;
use App\Services\ContentSafety\TextContentCheck;
use App\Support\AudioDuration;
use App\Support\AudioUrl;
use App\Support\GenerationChoiceLabels;
use App\Support\GenerationSource;
use App\Support\HiddenAudio;
use App\Support\VoiceTier;
use Filament\Actions\BulkAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Tables;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class AudioGenerationResource extends Resource
{
    protected static ?string $model = AudioGeneration::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-musical-note';

    protected static string | \UnitEnum | null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Audio Generations';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Generation Info')
                    ->columns(2)
                    ->schema([
                        TextInput::make('user.email')
                            ->label('User Email')
                            ->disabled()
                            ->dehydrated(false),
                        Placeholder::make('source_label')
                            ->label('Source')
                            ->content(fn (?AudioGeneration $record): string => self::sourceSummary($record))
                            ->visibleOn(['view', 'edit']),
                        TextInput::make('text_item_id')
                            ->numeric(),
                        TextInput::make('voice_name')
                            ->maxLength(255)
                            ->helperText(fn (?AudioGeneration $record): ?string => $record ? implode(' | ', GenerationChoiceLabels::spokenVoices($record)) : null),
                        Select::make('voice_tier')
                            ->label('Quality')
                            ->options(self::tierOptions()),
                        TextInput::make('source_language')
                            ->maxLength(10),
                        TextInput::make('target_language')
                            ->maxLength(10),
                        Toggle::make('translate'),
                        Select::make('status')
                            ->options(self::statusOptions()),
                        TextInput::make('duration_seconds')
                            ->label('Length (seconds)')
                            ->numeric()
                            ->helperText(fn (?AudioGeneration $record): ?string => (int) $record?->duration_seconds > 0
                                ? AudioDuration::label((int) $record->duration_seconds).' · '.number_format((int) $record->duration_seconds / 60, 1).' min'
                                : null),
                        TextInput::make('audio_path')
                            ->maxLength(255),
                        TextInput::make('public_audio_url')
                            ->label('Public Audio URL')
                            ->formatStateUsing(fn (?AudioGeneration $record): string => self::resolvePublicAudioUrl($record))
                            ->readOnly()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ]),
                Section::make('Studio Choices')
                    ->description(fn (?AudioGeneration $record): string => VoiceTier::normalize((string) $record?->voice_tier) === VoiceTier::STANDARD
                        ? 'A Standard voice: no accent, style, emotion, speed or pitch is applied.'
                        : 'What the audio was made with, named as the site offers it today.')
                    ->columns(2)
                    ->visibleOn(['view', 'edit'])
                    ->schema([
                        Placeholder::make('accent_label')
                            ->label('Accent')
                            ->content(fn (?AudioGeneration $record): string => $record ? GenerationChoiceLabels::spokenAccent($record) : '—')
                            ->columnSpanFull(),
                        Placeholder::make('performance_style_label')
                            ->label('Performance style')
                            ->content(fn (?AudioGeneration $record): string => GenerationChoiceLabels::performanceStyle($record?->performance_style)),
                        Placeholder::make('emotion_label')
                            ->label('Emotion')
                            ->content(fn (?AudioGeneration $record): string => GenerationChoiceLabels::emotion($record?->emotion)),
                        Placeholder::make('speed_label')
                            ->label('Speed')
                            ->content(fn (?AudioGeneration $record): string => $record?->speed !== null ? $record->speed.'x' : '—'),
                        Placeholder::make('pitch_label')
                            ->label('Pitch')
                            ->content(fn (?AudioGeneration $record): string => $record?->pitch !== null ? (string) $record->pitch : '—'),
                        Placeholder::make('style_prompt_label')
                            ->label('Custom direction')
                            ->content(fn (?AudioGeneration $record): string => filled($record?->style_prompt) ? (string) $record->style_prompt : '—')
                            ->columnSpanFull(),
                        Placeholder::make('environment_label')
                            ->label('Sound environment')
                            ->content(fn (?AudioGeneration $record): string => GenerationChoiceLabels::environment((string) (($record?->settings_snapshot ?? [])['audio_effect'] ?? '')))
                            ->columnSpanFull(),
                        Placeholder::make('dialogue_label')
                            ->label('Dialogue speakers')
                            ->content(fn (?AudioGeneration $record): HtmlString => self::dialogueSummary($record))
                            ->visible(fn (?AudioGeneration $record): bool => (bool) $record?->is_dialogue)
                            ->columnSpanFull(),
                    ]),
                Section::make('Delivery')
                    ->columns(3)
                    ->visibleOn(['view', 'edit'])
                    ->schema([
                        Placeholder::make('tts_provider_label')
                            ->label('Voice engine')
                            ->content(fn (?AudioGeneration $record): string => $record?->tts_provider ?: '—'),
                        Placeholder::make('tts_model_label')
                            ->label('Model')
                            ->content(fn (?AudioGeneration $record): string => $record?->tts_model ?: '—'),
                        Placeholder::make('tts_fallback_label')
                            ->label('Backup engine used')
                            ->content(fn (?AudioGeneration $record): string => $record?->tts_fallback_used ? 'Yes' : 'No'),
                        Placeholder::make('billable_chars_label')
                            ->label('Characters')
                            ->content(fn (?AudioGeneration $record): string => $record?->billable_chars !== null ? number_format((int) $record->billable_chars) : '—'),
                        Placeholder::make('tts_latency_label')
                            ->label('Time taken')
                            ->content(fn (?AudioGeneration $record): string => $record?->tts_latency_ms !== null ? number_format((int) $record->tts_latency_ms).' ms' : '—'),
                        Placeholder::make('tts_cost_label')
                            ->label('Estimated cost')
                            ->content(fn (?AudioGeneration $record): string => $record?->tts_cost_estimate_usd !== null ? '$'.number_format((float) $record->tts_cost_estimate_usd, 4) : '—'),
                    ]),
                Section::make('Hidden from the member')
                    ->description('The member no longer sees or plays this voice, anywhere. Its text and its file are kept here.')
                    ->columns(3)
                    ->visible(fn (?AudioGeneration $record): bool => HiddenAudio::isHidden($record))
                    ->schema([
                        Placeholder::make('hidden_at_label')
                            ->label('Hidden on')
                            ->content(fn (?AudioGeneration $record): string => $record?->hidden_at?->format('j M Y, H:i') ?? '—'),
                        Placeholder::make('hidden_by_label')
                            ->label('Hidden by')
                            ->content(fn (?AudioGeneration $record): string => self::hiddenBy($record)),
                        Placeholder::make('hidden_note_label')
                            ->label('Note')
                            ->content(fn (?AudioGeneration $record): string => filled($record?->hidden_note) ? (string) $record->hidden_note : '—'),
                    ]),
                Section::make('Content')
                    ->schema([
                        Textarea::make('input_text')
                            ->rows(6)
                            ->columnSpanFull(),
                        Textarea::make('error_message')
                            ->label('What the member was told')
                            ->rows(2)
                            ->columnSpanFull(),
                        Placeholder::make('technical_error')
                            ->label('Technical error')
                            ->content(fn (?AudioGeneration $record): string => self::technicalError($record))
                            ->visible(fn (?AudioGeneration $record): bool => $record?->status === 'failed')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // One plain SUM over the filtered rows. (Filament's column summary
            // copies every row, text included, into a temporary table first,
            // which made this page very slow.)
            ->description(fn (HasTable $livewire): string => 'Total audio length for these filters: '
                .self::totalLengthLabel((int) $livewire->getFilteredTableQuery()->toBase()->reorder()->sum('duration_seconds')))
            // Where each one was asked for, and with which key, without a query per row.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['textItem:id,source_type', 'apiKey:id,name,oauth_client_id']))
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('user.email')
                    ->label('User')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('source')
                    ->label('Source')
                    ->state(fn (AudioGeneration $record): string => GenerationSource::label($record->textItem?->source_type))
                    ->badge()
                    ->color(fn (AudioGeneration $record): string => GenerationSource::color($record->textItem?->source_type))
                    ->description(fn (AudioGeneration $record): ?string => self::keyLabel($record))
                    ->toggleable(),
                TextColumn::make('voice_name')
                    ->label('Voice')
                    ->state(fn (AudioGeneration $record): array => GenerationChoiceLabels::spokenVoices($record))
                    ->listWithLineBreaks()
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('voice_tier')
                    ->label('Quality')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::tierOptions()[VoiceTier::normalize($state)] ?? (string) $state)
                    ->color(fn (?string $state): string => VoiceTier::normalize($state) === VoiceTier::HD ? 'warning' : 'gray')
                    ->sortable(),
                TextColumn::make('dialect')
                    ->label('Accent')
                    ->state(fn (AudioGeneration $record): string => GenerationChoiceLabels::spokenAccent($record))
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('settings')
                    ->label('Settings')
                    ->state(fn (AudioGeneration $record): array => GenerationChoiceLabels::settings($record))
                    ->listWithLineBreaks()
                    ->placeholder('Default')
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::statusOptions()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'done' => 'success',
                        'processing' => 'info',
                        'queued' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('hidden_at')
                    ->label('Member sees it')
                    ->state(fn (AudioGeneration $record): string => HiddenAudio::isHidden($record) ? 'Hidden' : 'Yes')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Hidden' ? 'danger' : 'gray')
                    ->tooltip(fn (AudioGeneration $record): ?string => HiddenAudio::isHidden($record)
                        ? trim('Hidden '.$record->hidden_at?->format('j M Y, H:i').' by '.self::hiddenBy($record).'. '.(string) $record->hidden_note)
                        : null)
                    ->visible(fn (): bool => HiddenAudio::ready()),
                TextColumn::make('content_check')
                    ->label('Text check')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::contentCheckOptions()[$state] ?? '—')
                    ->color(fn (?string $state): string => match ($state) {
                        TextContentCheck::REFUSED => 'danger',
                        TextContentCheck::UNCHECKED => 'warning',
                        TextContentCheck::ALLOWED => 'success',
                        default => 'gray',
                    })
                    ->placeholder('—')
                    ->visible(fn (): bool => HiddenAudio::ready())
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('duration_seconds')
                    ->label('Length')
                    ->formatStateUsing(fn ($state): string => AudioDuration::label($state === null ? null : (int) $state))
                    ->placeholder('—')
                    ->tooltip(fn (AudioGeneration $record): ?string => (int) $record->duration_seconds > 0
                        ? number_format((int) $record->duration_seconds).' seconds ('.number_format((int) $record->duration_seconds / 60, 1).' min)'
                        : null)
                    ->sortable(),
                IconColumn::make('translate')
                    ->boolean(),
                TextColumn::make('public_audio_url')
                    ->label('Public URL')
                    ->state(fn (AudioGeneration $record): string => HiddenAudio::isHidden($record) ? 'Hidden: no public address' : self::resolvePublicAudioUrl($record))
                    ->url(fn (AudioGeneration $record): ?string => self::audioUrl($record), true)
                    ->copyable()
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->label('Source')
                    ->options(GenerationSource::labels())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereHas('textItem', fn (Builder $text): Builder => $text->where('source_type', $data['value']))),
                SelectFilter::make('status')
                    ->options(self::statusOptions()),
                SelectFilter::make('voice_tier')
                    ->label('Quality')
                    ->options(self::tierOptions()),
                SelectFilter::make('dialect')
                    ->label('Accent')
                    ->options(fn (): array => GenerationChoiceLabels::accentOptions())
                    ->searchable(),
                TernaryFilter::make('translate')
                    ->label('Translated'),
                TernaryFilter::make('hidden_at')
                    ->label('Hidden from member')
                    ->nullable()
                    ->trueLabel('Hidden')
                    ->falseLabel('Not hidden')
                    ->visible(fn (): bool => HiddenAudio::ready()),
                SelectFilter::make('content_check')
                    ->label('Text check')
                    ->options(self::contentCheckOptions())
                    ->visible(fn (): bool => HiddenAudio::ready()),
            ])
            ->recordActions([
                Action::make('audio')
                    ->label('Open Audio')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    // A hidden voice's file is off the public disk: admins play it from its own address.
                    ->url(fn (AudioGeneration $record): ?string => self::audioUrl($record))
                    ->openUrlInNewTab()
                    ->visible(fn (AudioGeneration $record): bool => filled($record->audio_path)),
                self::hideAction(),
                self::showAction(),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('hideFromMembers')
                        ->label('Hide from members')
                        ->icon('heroicon-o-eye-slash')
                        ->color('danger')
                        ->visible(fn (): bool => HiddenAudio::ready())
                        ->requiresConfirmation()
                        ->modalHeading('Hide these voices from their members?')
                        ->modalDescription(self::HIDE_EXPLAINED)
                        ->modalSubmitActionLabel('Hide them')
                        ->schema(self::hideFormSchema())
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data): void {
                            $hidden = $records->filter(fn (AudioGeneration $record): bool => HiddenAudio::hide($record, Auth::id(), $data['note'] ?? null))->count();
                            Notification::make()->success()
                                ->title($hidden === 1 ? '1 voice hidden from its member' : $hidden.' voices hidden from their members')
                                ->send();
                        }),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private const HIDE_EXPLAINED = 'The member will no longer see or play it: not in their history, the app, the extension or the developer API, and its link stops working. The text and the audio file stay here in the admin. Credits are not given back, and you can show it again at any time.';

    /** "Hide from member": on the list's rows and on the voice's own page. */
    public static function hideAction(): Action
    {
        return Action::make('hideFromMember')
            ->label('Hide from member')
            ->icon('heroicon-o-eye-slash')
            ->color('danger')
            ->visible(fn (AudioGeneration $record): bool => HiddenAudio::ready() && ! HiddenAudio::isHidden($record))
            ->requiresConfirmation()
            ->modalHeading('Hide this voice from the member?')
            ->modalDescription(self::HIDE_EXPLAINED)
            ->modalSubmitActionLabel('Hide it')
            ->schema(self::hideFormSchema())
            ->action(function (AudioGeneration $record, array $data): void {
                HiddenAudio::hide($record, Auth::id(), $data['note'] ?? null);
                Notification::make()->success()->title('Hidden from the member')->send();
            });
    }

    public static function showAction(): Action
    {
        return Action::make('showToMember')
            ->label('Show to member again')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->visible(fn (AudioGeneration $record): bool => HiddenAudio::isHidden($record))
            ->requiresConfirmation()
            ->modalHeading('Show this voice to the member again?')
            ->modalDescription('It goes back into their history and its link works again.')
            ->modalSubmitActionLabel('Show it')
            ->action(function (AudioGeneration $record): void {
                HiddenAudio::show($record);
                Notification::make()->success()->title('The member can see it again')->send();
            });
    }

    /** @return array<int, Component> */
    private static function hideFormSchema(): array
    {
        return [
            Textarea::make('note')
                ->label('Why (only admins see this)')
                ->placeholder('For example: sexual content')
                ->rows(2)
                ->maxLength(255),
        ];
    }

    /** The audio's address for an admin: a hidden voice's own, otherwise the public one. */
    private static function audioUrl(AudioGeneration $record): ?string
    {
        if (blank($record->audio_path)) {
            return null;
        }

        return HiddenAudio::isHidden($record)
            ? route('admin.hidden-audio', ['generation' => $record->id])
            : AudioUrl::fromPath($record->audio_path);
    }

    /** "admin@example.com", or "the content check" for one hidden on a later reading of its text. */
    private static function hiddenBy(?AudioGeneration $record): string
    {
        if (! HiddenAudio::isHidden($record)) {
            return '—';
        }
        if ($record->hidden_by === null) {
            return 'the content check';
        }

        return (string) (User::query()->whereKey((int) $record->hidden_by)->value('email') ?? 'admin '.$record->hidden_by);
    }

    /**
     * What the reading of the text found before the voice was made (TextContentCheck).
     *
     * @return array<string, string>
     */
    public static function contentCheckOptions(): array
    {
        return [
            TextContentCheck::ALLOWED => 'Allowed',
            TextContentCheck::REFUSED => 'Refused',
            TextContentCheck::UNCHECKED => 'Not read yet',
        ];
    }

    /**
     * "12 min 30 s" or "3 h 05 min" for the total under the Length column.
     */
    public static function totalLengthLabel(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0 min';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60).' min '.($seconds % 60).' s';
        }

        return intdiv($seconds, 3600).' h '.sprintf('%02d', intdiv($seconds % 3600, 60)).' min';
    }

    /**
     * The statuses GenerateAudioJob and the controllers set.
     *
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            'queued' => 'Queued',
            'processing' => 'Processing',
            'done' => 'Done',
            'failed' => 'Failed',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function tierOptions(): array
    {
        return [
            VoiceTier::STANDARD => 'Standard',
            VoiceTier::HD => 'Premium (HD)',
        ];
    }

    /** "AI app (MCP), signed in: Claude": where it was asked for, and with which key or app. */
    private static function sourceSummary(?AudioGeneration $record): string
    {
        if (! $record) {
            return '—';
        }

        $key = self::keyLabel($record);

        return GenerationSource::label($record->textItem?->source_type).($key !== null ? ', '.lcfirst($key) : '');
    }

    /** "Key: Server key", or "Signed in: Claude" for an app connected with "Sign in with Listen". */
    private static function keyLabel(AudioGeneration $record): ?string
    {
        $key = $record->apiKey;
        if ($key === null) {
            return null;
        }

        return ($key->isConnectedApp() ? 'Signed in: ' : 'Key: ').$key->name;
    }

    private static function dialogueSummary(?AudioGeneration $record): HtmlString
    {
        $speakers = $record ? GenerationChoiceLabels::speakers($record) : [];
        if ($speakers === []) {
            return new HtmlString('—');
        }

        return new HtmlString(implode('<br>', array_map(
            fn (array $s): string => e($s['name']).' — voice: '.e($s['voice']).' — accent: '.e($s['accent']),
            $speakers,
        )));
    }

    /**
     * Members only see a generic message; the real reason is in the logs.
     */
    private static function technicalError(?AudioGeneration $record): string
    {
        if (! $record) {
            return '—';
        }

        $recorded = rescue(fn () => GenerationError::query()
            ->where('subject_type', 'audio_generation')
            ->where('subject_id', $record->id)
            ->latest('id')
            ->value('message'), null, false);

        $reason = $recorded ?? TtsLog::query()
            ->where('audio_generation_id', $record->id)
            ->where('status', 'failed')
            ->latest('id')
            ->value('failure_reason');

        return filled($reason) ? (string) $reason : 'No technical detail was recorded.';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAudioGenerations::route('/'),
            'create' => CreateAudioGeneration::route('/create'),
            'view' => ViewAudioGeneration::route('/{record}'),
            'edit' => EditAudioGeneration::route('/{record}/edit'),
        ];
    }

    private static function resolvePublicAudioUrl(?AudioGeneration $record): string
    {
        if (! $record || ! filled($record->audio_path)) {
            return 'N/A';
        }

        $relativePath = 'storage/' . ltrim($record->audio_path, '/');
        $request = request();

        if ($request) {
            return $request->getSchemeAndHttpHost() . '/' . ltrim($relativePath, '/');
        }

        return url($relativePath);
    }
}
