<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AudioGenerationResource\Pages;
use App\Models\AudioGeneration;
use App\Support\AudioUrl;
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
                        Forms\Components\TextInput::make('user_id')
                            ->numeric(),
                        Forms\Components\TextInput::make('text_item_id')
                            ->numeric(),
                        Forms\Components\TextInput::make('voice_name')
                            ->maxLength(255),
                        Forms\Components\Select::make('voice_tier')
                            ->options([
                                'standard' => 'Standard',
                                'premium' => 'Premium',
                                'studio' => 'Studio',
                            ]),
                        Forms\Components\TextInput::make('source_language')
                            ->maxLength(10),
                        Forms\Components\TextInput::make('target_language')
                            ->maxLength(10),
                        Forms\Components\Toggle::make('translate'),
                        Forms\Components\Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'processing' => 'Processing',
                                'completed' => 'Completed',
                                'failed' => 'Failed',
                            ]),
                        Forms\Components\TextInput::make('duration_seconds')
                            ->numeric(),
                        Forms\Components\TextInput::make('audio_path')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('public_audio_url')
                            ->label('Public Audio URL')
                            ->formatStateUsing(fn (?AudioGeneration $record): string => self::resolvePublicAudioUrl($record))
                            ->readOnly()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Content')
                    ->schema([
                        Forms\Components\Textarea::make('input_text')
                            ->rows(6)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('error_message')
                            ->rows(3)
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
                Tables\Columns\TextColumn::make('user_id')
                    ->label('User ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('voice_name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('voice_tier')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'premium' => 'warning',
                        'studio' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'completed' => 'success',
                        'processing' => 'info',
                        'pending' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('duration_seconds')
                    ->label('Duration (s)')
                    ->numeric()
                    ->sortable(),
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
                    ->options([
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ]),
                Tables\Filters\SelectFilter::make('voice_tier')
                    ->options([
                        'standard' => 'Standard',
                        'premium' => 'Premium',
                        'studio' => 'Studio',
                    ]),
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
