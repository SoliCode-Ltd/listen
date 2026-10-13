<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AudioGenerationResource\Pages;
use App\Models\AudioGeneration;
use App\Models\GenerationError;
use App\Models\TtsLog;
use App\Support\AudioDuration;
use App\Support\AudioUrl;
use App\Support\GenerationChoiceLabels;
use App\Support\VoiceTier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AudioGenerationResource extends Resource
{
    protected static ?string $model = AudioGeneration::class;

    protected static ?string $navigationIcon = 'heroicon-o-musical-note';

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Audio Generations';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Generation Info')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('user.email')
                            ->label('User Email')
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('text_item_id')
                            ->numeric(),
                        Forms\Components\TextInput::make('voice_name')
                            ->maxLength(255)
                            ->helperText(fn (?AudioGeneration $record): ?string => $record ? GenerationChoiceLabels::voice($record->voice_name) : null),
                        Forms\Components\Select::make('voice_tier')
                            ->label('Quality')
                            ->options(self::tierOptions()),
                        Forms\Components\TextInput::make('source_language')
                            ->maxLength(10),
                        Forms\Components\TextInput::make('target_language')
                            ->maxLength(10),
                        Forms\Components\Toggle::make('translate'),
                        Forms\Components\Select::make('status')
                            ->options(self::statusOptions()),
                        Forms\Components\TextInput::make('duration_seconds')
                            ->label('Length (seconds)')
                            ->numeric()
                            ->helperText(fn (?AudioGeneration $record): ?string => (int) $record?->duration_seconds > 0
                                ? AudioDuration::label((int) $record->duration_seconds).' · '.number_format((int) $record->duration_seconds / 60, 1).' min'
                                : null),
                        Forms\Components\TextInput::make('audio_path')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('public_audio_url')
                            ->label('Public Audio URL')
                            ->formatStateUsing(fn (?AudioGeneration $record): string => self::resolvePublicAudioUrl($record))
                            ->readOnly()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Studio Choices')
                    ->description('What the member picked, named as the site offers it today.')
                    ->columns(2)
                    ->visibleOn(['view', 'edit'])
                    ->schema([
                        Forms\Components\Placeholder::make('accent_label')
                            ->label('Accent')
                            ->content(fn (?AudioGeneration $record): string => GenerationChoiceLabels::accent($record?->dialect, $record?->target_language, $record?->voice_tier))
                            ->columnSpanFull(),
                        Forms\Components\Placeholder::make('performance_style_label')
                            ->label('Performance style')
                            ->content(fn (?AudioGeneration $record): string => GenerationChoiceLabels::performanceStyle($record?->performance_style)),
                        Forms\Components\Placeholder::make('emotion_label')
                            ->label('Emotion')
                            ->content(fn (?AudioGeneration $record): string => GenerationChoiceLabels::emotion($record?->emotion)),
                        Forms\Components\Placeholder::make('speed_label')
                            ->label('Speed')
                            ->content(fn (?AudioGeneration $record): string => $record?->speed !== null ? $record->speed.'x' : '—'),
                        Forms\Components\Placeholder::make('pitch_label')
                            ->label('Pitch')
                            ->content(fn (?AudioGeneration $record): string => $record?->pitch !== null ? (string) $record->pitch : '—'),
                        Forms\Components\Placeholder::make('style_prompt_label')
                            ->label('Custom direction')
                            ->content(fn (?AudioGeneration $record): string => filled($record?->style_prompt) ? (string) $record->style_prompt : '—')
                            ->columnSpanFull(),
                        Forms\Components\Placeholder::make('dialogue_label')
                            ->label('Dialogue speakers')
                            ->content(fn (?AudioGeneration $record): string => self::dialogueSummary($record))
                            ->visible(fn (?AudioGeneration $record): bool => (bool) $record?->is_dialogue)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Delivery')
                    ->columns(3)
                    ->visibleOn(['view', 'edit'])
                    ->schema([
                        Forms\Components\Placeholder::make('tts_provider_label')
                            ->label('Voice engine')
                            ->content(fn (?AudioGeneration $record): string => $record?->tts_provider ?: '—'),
                        Forms\Components\Placeholder::make('tts_model_label')
                            ->label('Model')
                            ->content(fn (?AudioGeneration $record): string => $record?->tts_model ?: '—'),
                        Forms\Components\Placeholder::make('tts_fallback_label')
                            ->label('Backup engine used')
                            ->content(fn (?AudioGeneration $record): string => $record?->tts_fallback_used ? 'Yes' : 'No'),
                        Forms\Components\Placeholder::make('billable_chars_label')
                            ->label('Characters')
                            ->content(fn (?AudioGeneration $record): string => $record?->billable_chars !== null ? number_format((int) $record->billable_chars) : '—'),
                        Forms\Components\Placeholder::make('tts_latency_label')
                            ->label('Time taken')
                            ->content(fn (?AudioGeneration $record): string => $record?->tts_latency_ms !== null ? number_format((int) $record->tts_latency_ms).' ms' : '—'),
                        Forms\Components\Placeholder::make('tts_cost_label')
                            ->label('Estimated cost')
                            ->content(fn (?AudioGeneration $record): string => $record?->tts_cost_estimate_usd !== null ? '$'.number_format((float) $record->tts_cost_estimate_usd, 4) : '—'),
                    ]),
                Forms\Components\Section::make('Content')
                    ->schema([
                        Forms\Components\Textarea::make('input_text')
                            ->rows(6)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('error_message')
                            ->label('What the member was told')
                            ->rows(2)
                            ->columnSpanFull(),
                        Forms\Components\Placeholder::make('technical_error')
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
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.email')
                    ->label('User')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('voice_name')
                    ->label('Voice')
                    ->formatStateUsing(fn (?string $state): string => GenerationChoiceLabels::voice($state))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('voice_tier')
                    ->label('Quality')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::tierOptions()[VoiceTier::normalize($state)] ?? (string) $state)
                    ->color(fn (?string $state): string => VoiceTier::normalize($state) === VoiceTier::HD ? 'warning' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('dialect')
                    ->label('Accent')
                    ->state(fn (AudioGeneration $record): string => $record->dialect
                        ? GenerationChoiceLabels::accent($record->dialect, $record->target_language, $record->voice_tier)
                        : '—')
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
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
                Tables\Columns\TextColumn::make('duration_seconds')
                    ->label('Length')
                    ->formatStateUsing(fn ($state): string => AudioDuration::label($state === null ? null : (int) $state))
                    ->placeholder('—')
                    ->tooltip(fn (AudioGeneration $record): ?string => (int) $record->duration_seconds > 0
                        ? number_format((int) $record->duration_seconds).' seconds ('.number_format((int) $record->duration_seconds / 60, 1).' min)'
                        : null)
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()
                        ->label('Total')
                        ->formatStateUsing(fn ($state): string => self::totalLengthLabel((int) $state))),
                Tables\Columns\IconColumn::make('translate')
                    ->boolean(),
                Tables\Columns\TextColumn::make('public_audio_url')
                    ->label('Public URL')
                    ->state(fn (AudioGeneration $record): string => self::resolvePublicAudioUrl($record))
                    ->url(fn (AudioGeneration $record): ?string => $record->audio_path ? AudioUrl::fromPath($record->audio_path) : null, true)
                    ->copyable()
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(self::statusOptions()),
                Tables\Filters\SelectFilter::make('voice_tier')
                    ->label('Quality')
                    ->options(self::tierOptions()),
                Tables\Filters\SelectFilter::make('dialect')
                    ->label('Accent')
                    ->options(fn (): array => GenerationChoiceLabels::accentOptions())
                    ->searchable(),
                Tables\Filters\TernaryFilter::make('translate')
                    ->label('Translated'),
            ])
            ->actions([
                Tables\Actions\Action::make('audio')
                    ->label('Open Audio')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (AudioGeneration $record): ?string => $record->audio_path ? AudioUrl::fromPath($record->audio_path) : null)
                    ->openUrlInNewTab()
                    ->visible(fn (AudioGeneration $record): bool => filled($record->audio_path)),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
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

    private static function dialogueSummary(?AudioGeneration $record): string
    {
        $speakers = is_array($record?->dialogue_metadata) ? ($record->dialogue_metadata['speakers'] ?? []) : [];
        if (! is_array($speakers) || $speakers === []) {
            return '—';
        }

        $lines = [];
        foreach ($speakers as $speaker) {
            if (! is_array($speaker)) {
                continue;
            }
            $name = trim((string) ($speaker['name'] ?? $speaker['speaker'] ?? ''));
            $voice = GenerationChoiceLabels::voice((string) ($speaker['voice_name'] ?? $speaker['voice'] ?? ''));
            $lines[] = ($name !== '' ? $name.': ' : '').$voice;
        }

        return $lines !== [] ? implode(' | ', $lines) : '—';
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
            'index' => Pages\ListAudioGenerations::route('/'),
            'create' => Pages\CreateAudioGeneration::route('/create'),
            'view' => Pages\ViewAudioGeneration::route('/{record}'),
            'edit' => Pages\EditAudioGeneration::route('/{record}/edit'),
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
