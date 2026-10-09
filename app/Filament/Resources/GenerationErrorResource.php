<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GenerationErrorResource\Pages;
use App\Models\GenerationError;
use App\Support\GenerationChoiceLabels;
use Filament\Infolists\Components;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Every failure members hit while generating audio (dashboard, extension,
 * demo, voice cloning) and every time the backup voice engine had to step in.
 */
class GenerationErrorResource extends Resource
{
    protected static ?string $model = GenerationError::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Generation Errors';

    protected static ?string $modelLabel = 'generation error';

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Unresolved errors (not warnings). Never breaks the admin menu, even
     * before the table's migration has run.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = Cache::remember('admin:generation_errors_open', 60, static function (): int {
            try {
                if (! Schema::hasTable('generation_errors')) {
                    return 0;
                }

                return GenerationError::query()
                    ->unresolved()
                    ->where('severity', GenerationError::SEVERITY_ERROR)
                    ->count();
            } catch (\Throwable) {
                return 0;
            }
        });

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Unresolved generation errors';
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Components\Section::make('What happened')
                ->columns(3)
                ->schema([
                    Components\TextEntry::make('severity')
                        ->badge()
                        ->color(fn (string $state): string => self::severityColor($state))
                        ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                    Components\TextEntry::make('source')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => GenerationError::sourceLabels()[$state] ?? $state),
                    Components\TextEntry::make('occurrences')
                        ->label('Times seen'),
                    Components\TextEntry::make('created_at')
                        ->label('First seen')
                        ->dateTime(),
                    Components\TextEntry::make('last_seen_at')
                        ->label('Last seen')
                        ->dateTime(),
                    Components\TextEntry::make('resolved_at')
                        ->label('Resolved')
                        ->dateTime()
                        ->placeholder('Not resolved'),
                    Components\TextEntry::make('message')
                        ->label('Error')
                        ->columnSpanFull(),
                    Components\TextEntry::make('user_message')
                        ->label('What the member was told')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
            Components\Section::make('Member')
                ->columns(3)
                ->schema([
                    Components\TextEntry::make('user.name')
                        ->label('Name')
                        ->placeholder('—'),
                    Components\TextEntry::make('user.email')
                        ->label('Email')
                        ->placeholder('—')
                        ->url(fn (GenerationError $record): ?string => $record->user_id ? UserResource::getUrl('view', ['record' => $record->user_id]) : null),
                    Components\TextEntry::make('subject')
                        ->label('Record')
                        ->state(fn (GenerationError $record): string => self::subjectLabel($record))
                        ->url(fn (GenerationError $record): ?string => self::subjectUrl($record)),
                ]),
            Components\Section::make('Choices')
                ->columns(3)
                ->schema([
                    Components\TextEntry::make('voice_name')
                        ->label('Voice')
                        ->state(fn (GenerationError $record): string => GenerationChoiceLabels::voice($record->voice_name)),
                    Components\TextEntry::make('voice_tier')
                        ->label('Quality')
                        ->state(fn (GenerationError $record): string => GenerationChoiceLabels::tier($record->voice_tier)),
                    Components\TextEntry::make('target_language')
                        ->label('Language')
                        ->state(fn (GenerationError $record): string => GenerationChoiceLabels::language($record->target_language)),
                    Components\TextEntry::make('dialect')
                        ->label('Accent')
                        ->state(fn (GenerationError $record): string => GenerationChoiceLabels::accent($record->dialect, $record->target_language, $record->voice_tier)),
                    Components\TextEntry::make('performance_style')
                        ->label('Performance style')
                        ->state(fn (GenerationError $record): string => GenerationChoiceLabels::performanceStyle($record->context['performance_style'] ?? null)),
                    Components\TextEntry::make('emotion')
                        ->label('Emotion')
                        ->state(fn (GenerationError $record): string => GenerationChoiceLabels::emotion($record->context['emotion'] ?? null)),
                ]),
            Components\Section::make('Technical details')
                ->collapsed()
                ->schema([
                    Components\TextEntry::make('provider')
                        ->label('Voice engine')
                        ->placeholder('—'),
                    Components\TextEntry::make('exception_class')
                        ->label('Exception')
                        ->placeholder('—'),
                    Components\TextEntry::make('context')
                        ->label('Context')
                        ->state(fn (GenerationError $record): string => json_encode($record->context ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}')
                        ->fontFamily('mono')
                        ->extraAttributes(['style' => 'white-space: pre-wrap'])
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->dateTime()
                    ->since()
                    ->sortable(),
                Tables\Columns\TextColumn::make('severity')
                    ->badge()
                    ->color(fn (string $state): string => self::severityColor($state))
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                Tables\Columns\TextColumn::make('source')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => GenerationError::sourceLabels()[$state] ?? $state),
                Tables\Columns\TextColumn::make('user.email')
                    ->label('Member')
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('message')
                    ->label('Error')
                    ->limit(90)
                    ->tooltip(fn (GenerationError $record): string => $record->message)
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('dialect')
                    ->label('Accent')
                    ->state(fn (GenerationError $record): string => $record->dialect ? GenerationChoiceLabels::accent($record->dialect, $record->target_language, $record->voice_tier) : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('provider')
                    ->label('Engine')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('occurrences')
                    ->label('Times')
                    ->sortable(),
                Tables\Columns\IconColumn::make('resolved_at')
                    ->label('Resolved')
                    ->boolean()
                    ->state(fn (GenerationError $record): bool => $record->resolved_at !== null),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('resolved')
                    ->label('Status')
                    ->placeholder('All')
                    ->trueLabel('Resolved')
                    ->falseLabel('Open')
                    ->default(false)
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('resolved_at'),
                        false: fn (Builder $query) => $query->whereNull('resolved_at'),
                        blank: fn (Builder $query) => $query,
                    ),
                Tables\Filters\SelectFilter::make('severity')
                    ->options([
                        GenerationError::SEVERITY_ERROR => 'Error (no audio)',
                        GenerationError::SEVERITY_WARNING => 'Warning (got audio another way)',
                    ]),
                Tables\Filters\SelectFilter::make('source')
                    ->options(GenerationError::sourceLabels()),
                Tables\Filters\SelectFilter::make('period')
                    ->label('When')
                    ->options([
                        '24h' => 'Last 24 hours',
                        '7d' => 'Last 7 days',
                        '30d' => 'Last 30 days',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            '24h' => $query->where('last_seen_at', '>=', now()->subDay()),
                            '7d' => $query->where('last_seen_at', '>=', now()->subDays(7)),
                            '30d' => $query->where('last_seen_at', '>=', now()->subDays(30)),
                            default => $query,
                        };
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('resolve')
                    ->label('Mark resolved')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (GenerationError $record): bool => $record->resolved_at === null)
                    ->action(fn (GenerationError $record) => self::setResolved(new Collection([$record]), true)),
                Tables\Actions\Action::make('reopen')
                    ->label('Reopen')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (GenerationError $record): bool => $record->resolved_at !== null)
                    ->action(fn (GenerationError $record) => self::setResolved(new Collection([$record]), false)),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('resolve')
                        ->label('Mark resolved')
                        ->icon('heroicon-o-check-circle')
                        ->action(fn (Collection $records) => self::setResolved($records, true))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->poll('60s');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGenerationErrors::route('/'),
            'view' => Pages\ViewGenerationError::route('/{record}'),
        ];
    }

    public static function setResolved(Collection $records, bool $resolved): void
    {
        GenerationError::query()
            ->whereKey($records->modelKeys())
            ->update(['resolved_at' => $resolved ? now() : null]);

        Cache::forget('admin:generation_errors_open');
    }

    private static function severityColor(string $severity): string
    {
        return $severity === GenerationError::SEVERITY_ERROR ? 'danger' : 'warning';
    }

    private static function subjectLabel(GenerationError $record): string
    {
        return match ($record->subject_type) {
            'audio_generation' => "Audio generation #{$record->subject_id}",
            'cloned_voice' => "Cloned voice #{$record->subject_id}",
            default => '—',
        };
    }

    private static function subjectUrl(GenerationError $record): ?string
    {
        if (! $record->subject_id) {
            return null;
        }

        return match ($record->subject_type) {
            'audio_generation' => AudioGenerationResource::getUrl('view', ['record' => $record->subject_id]),
            'cloned_voice' => ClonedVoiceResource::getUrl('view', ['record' => $record->subject_id]),
            default => null,
        };
    }
}
