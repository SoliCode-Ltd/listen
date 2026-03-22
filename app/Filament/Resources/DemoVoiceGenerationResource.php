<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DemoVoiceGenerationResource\Pages;
use App\Models\DemoVoiceGeneration;
use App\Support\AudioUrl;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
class DemoVoiceGenerationResource extends Resource
{
    protected static ?string $model = DemoVoiceGeneration::class;

    protected static ?string $navigationIcon = 'heroicon-o-speaker-wave';

    protected static ?string $navigationGroup = 'Demo';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Voice Generations';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Generation')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('user.name')
                            ->label('User')
                            ->disabled(),
                        Forms\Components\TextInput::make('user.email')
                            ->label('Email')
                            ->disabled(),
                        Forms\Components\TextInput::make('voice_label')
                            ->disabled(),
                        Forms\Components\TextInput::make('voice_name')
                            ->disabled(),
                        Forms\Components\TextInput::make('voice_provider')
                            ->disabled(),
                        Forms\Components\TextInput::make('voice_tier')
                            ->disabled(),
                        Forms\Components\TextInput::make('source_language')
                            ->disabled(),
                        Forms\Components\TextInput::make('target_language')
                            ->disabled(),
                        Forms\Components\Toggle::make('translate')
                            ->disabled(),
                        Forms\Components\TextInput::make('billable_chars')
                            ->numeric()
                            ->disabled(),
                        Forms\Components\TextInput::make('duration_seconds')
                            ->numeric()
                            ->disabled(),
                        Forms\Components\TextInput::make('public_audio_url')
                            ->label('Public Audio URL')
                            ->formatStateUsing(fn (?DemoVoiceGeneration $record): string => self::resolvePublicAudioUrl($record))
                            ->readOnly()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Studio Controls')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('dialect')->disabled(),
                        Forms\Components\TextInput::make('performance_style')->disabled(),
                        Forms\Components\TextInput::make('emotion')->disabled(),
                        Forms\Components\TextInput::make('speed')->numeric()->disabled(),
                        Forms\Components\TextInput::make('pitch')->numeric()->disabled(),
                        Forms\Components\Textarea::make('style_prompt')
                            ->rows(3)
                            ->columnSpanFull()
                            ->disabled(),
                    ]),
                Forms\Components\Section::make('Text')
                    ->schema([
                        Forms\Components\Textarea::make('input_text')
                            ->rows(5)
                            ->columnSpanFull()
                            ->disabled(),
                        Forms\Components\Textarea::make('speech_text')
                            ->rows(5)
                            ->columnSpanFull()
                            ->disabled(),
                    ]),
                Forms\Components\Section::make('Tracking')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('tts_model')->disabled(),
                        Forms\Components\TextInput::make('tts_latency_ms')->numeric()->disabled(),
                        Forms\Components\Toggle::make('tts_fallback_used')->disabled(),
                        Forms\Components\TextInput::make('tts_cost_estimate_usd')->numeric()->disabled(),
                        Forms\Components\TextInput::make('quota_limit_chars')->numeric()->disabled(),
                        Forms\Components\TextInput::make('quota_remaining_chars')->numeric()->disabled(),
                        Forms\Components\Textarea::make('metadata')
                            ->rows(12)
                            ->columnSpanFull()
                            ->formatStateUsing(static function ($state): string {
                                if (is_array($state)) {
                                    return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '';
                                }

                                return is_string($state) ? $state : '';
                            })
                            ->disabled()
                            ->dehydrated(false),
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
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('voice_label')
                    ->label('Voice')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('voice_provider')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'gemini' => 'info',
                        'google_cloud' => 'success',
                        'cache' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('voice_tier')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'hd' => 'warning',
                        'standard' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('target_language')
                    ->badge()
                    ->sortable(),
                Tables\Columns\IconColumn::make('translate')
                    ->boolean(),
                Tables\Columns\TextColumn::make('billable_chars')
                    ->label('Chars')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('duration_seconds')
                    ->label('Duration')
                    ->suffix(' s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('quota_remaining_chars')
                    ->label('Quota Left')
                    ->numeric()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('public_audio_url')
                    ->label('Public URL')
                    ->state(fn (DemoVoiceGeneration $record): string => self::resolvePublicAudioUrl($record))
                    ->copyable()
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('target_language')
                    ->options([
                        'ar' => 'Arabic',
                        'en' => 'English',
                    ]),
                Tables\Filters\SelectFilter::make('voice_tier')
                    ->options([
                        'standard' => 'Standard',
                        'hd' => 'HD',
                    ]),
                Tables\Filters\SelectFilter::make('voice_provider')
                    ->options([
                        'google_cloud' => 'Google Cloud',
                        'gemini' => 'Gemini',
                        'cache' => 'Cache',
                    ]),
                Tables\Filters\TernaryFilter::make('translate')
                    ->label('Translated'),
            ])
            ->actions([
                Tables\Actions\Action::make('audio')
                    ->label('Open Audio')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (DemoVoiceGeneration $record): ?string => $record->audio_path ? AudioUrl::fromPath($record->audio_path) : null)
                    ->openUrlInNewTab()
                    ->visible(fn (DemoVoiceGeneration $record): bool => filled($record->audio_path)),
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDemoVoiceGenerations::route('/'),
            'view' => Pages\ViewDemoVoiceGeneration::route('/{record}'),
        ];
    }

    private static function resolvePublicAudioUrl(?DemoVoiceGeneration $record): string
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
