<?php

namespace App\Filament\Resources;

use Illuminate\Auth\Access\Response as AccessResponse;
use Throwable;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\ViewAction;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\GenerationErrorResource\Pages\ListGenerationErrors;
use App\Filament\Resources\GenerationErrorResource\Pages\ViewGenerationError;
use App\Filament\Resources\GenerationErrorResource\Pages;
use App\Models\GenerationError;
use App\Support\GenerationChoiceLabels;
use Filament\Infolists\Components;
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

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string | \UnitEnum | null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Generation Errors';

    protected static ?string $modelLabel = 'generation error';

    // Filament 4 checks this (pages, buttons and canCreate() alike): never allowed here.
    public static function getCreateAuthorizationResponse(): AccessResponse
    {
        return AccessResponse::deny();
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
            } catch (Throwable) {
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

    public static function infolist(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->components([
            Section::make('What happened')
                ->columns(3)
                ->schema([
                    TextEntry::make('severity')
                        ->badge()
                        ->color(fn (string $state): string => self::severityColor($state))
                        ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                    TextEntry::make('source')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => GenerationError::sourceLabels()[$state] ?? $state),
                    TextEntry::make('occurrences')
                        ->label('Times seen'),
                    TextEntry::make('created_at')
                        ->label('First seen')
                        ->dateTime(),
                    TextEntry::make('last_seen_at')
                        ->label('Last seen')
                        ->dateTime(),
                    TextEntry::make('resolved_at')
                        ->label('Resolved')
                        ->dateTime()
                        ->placeholder('Not resolved'),
                    TextEntry::make('message')
                        ->label('Error')
                        ->columnSpanFull(),
                    TextEntry::make('user_message')
                        ->label('What the member was told')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
            Section::make('Member')
                ->columns(3)
                ->schema([
                    TextEntry::make('user.name')
                        ->label('Name')
                        ->placeholder('—'),
                    TextEntry::make('user.email')
                        ->label('Email')
                        ->placeholder('—')
                        ->url(fn (GenerationError $record): ?string => $record->user_id ? UserResource::getUrl('view', ['record' => $record->user_id]) : null),
                    TextEntry::make('subject')
                        ->label('Record')
                        ->state(fn (GenerationError $record): string => self::subjectLabel($record))
                        ->url(fn (GenerationError $record): ?string => self::subjectUrl($record)),
                ]),
            Section::make('Choices')
                ->columns(3)
                ->schema([
                    TextEntry::make('voice_name')
                        ->label('Voice')
                        ->state(fn (GenerationError $record): string => GenerationChoiceLabels::voice($record->voice_name)),
                    TextEntry::make('voice_tier')
                        ->label('Quality')
                        ->state(fn (GenerationError $record): string => GenerationChoiceLabels::tier($record->voice_tier)),
                    TextEntry::make('target_language')
                        ->label('Language')
                        ->state(fn (GenerationError $record): string => GenerationChoiceLabels::language($record->target_language)),
                    TextEntry::make('dialect')
                        ->label('Accent')
                        ->state(fn (GenerationError $record): string => GenerationChoiceLabels::accent($record->dialect, $record->target_language, $record->voice_tier)),
                    TextEntry::make('performance_style')
                        ->label('Performance style')
                        ->state(fn (GenerationError $record): string => GenerationChoiceLabels::performanceStyle($record->context['performance_style'] ?? null)),
                    TextEntry::make('emotion')
                        ->label('Emotion')
                        ->state(fn (GenerationError $record): string => GenerationChoiceLabels::emotion($record->context['emotion'] ?? null)),
                ]),
            Section::make('Technical details')
                ->collapsed()
                ->schema([
                    TextEntry::make('provider')
                        ->label('Voice engine')
                        ->placeholder('—'),
                    TextEntry::make('exception_class')
                        ->label('Exception')
                        ->placeholder('—'),
                    TextEntry::make('context')
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
                TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->dateTime()
                    ->since()
                    ->sortable(),
                TextColumn::make('severity')
                    ->badge()
                    ->color(fn (string $state): string => self::severityColor($state))
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('source')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => GenerationError::sourceLabels()[$state] ?? $state),
                TextColumn::make('user.email')
                    ->label('Member')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('message')
                    ->label('Error')
                    ->limit(90)
                    ->tooltip(fn (GenerationError $record): string => $record->message)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('dialect')
                    ->label('Accent')
                    ->state(fn (GenerationError $record): string => $record->dialect ? GenerationChoiceLabels::accent($record->dialect, $record->target_language, $record->voice_tier) : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('provider')
                    ->label('Engine')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('occurrences')
                    ->label('Times')
                    ->sortable(),
                IconColumn::make('resolved_at')
                    ->label('Resolved')
                    ->boolean()
                    ->state(fn (GenerationError $record): bool => $record->resolved_at !== null),
            ])
            ->filters([
                TernaryFilter::make('resolved')
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
                SelectFilter::make('severity')
                    ->options([
                        GenerationError::SEVERITY_ERROR => 'Error (no audio)',
                        GenerationError::SEVERITY_WARNING => 'Warning (got audio another way)',
                    ]),
                SelectFilter::make('source')
                    ->options(GenerationError::sourceLabels()),
                SelectFilter::make('period')
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
            ->recordActions([
                ViewAction::make(),
                Action::make('resolve')
                    ->label('Mark resolved')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (GenerationError $record): bool => $record->resolved_at === null)
                    ->action(fn (GenerationError $record) => self::setResolved(new Collection([$record]), true)),
                Action::make('reopen')
                    ->label('Reopen')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (GenerationError $record): bool => $record->resolved_at !== null)
                    ->action(fn (GenerationError $record) => self::setResolved(new Collection([$record]), false)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('resolve')
                        ->label('Mark resolved')
                        ->icon('heroicon-o-check-circle')
                        ->action(fn (Collection $records) => self::setResolved($records, true))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->poll('60s');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGenerationErrors::route('/'),
            'view' => ViewGenerationError::route('/{record}'),
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
