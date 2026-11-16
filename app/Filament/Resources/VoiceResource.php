<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VoiceResource\Pages\CreateVoice;
use App\Filament\Resources\VoiceResource\Pages\EditVoice;
use App\Filament\Resources\VoiceResource\Pages\ListVoices;
use App\Filament\Resources\VoiceResource\Pages\ViewVoice;
use App\Models\Voice;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class VoiceResource extends Resource
{
    protected static ?string $model = Voice::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-speaker-wave';

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Voice Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('display_name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('internal_voice_id')
                            ->required()
                            ->maxLength(255),
                        Select::make('language')
                            ->required()
                            ->searchable()
                            ->options([
                                'ar' => 'Arabic',
                                'en' => 'English',
                            ]),
                        Select::make('gender')
                            ->required()
                            ->options([
                                'male' => 'Male',
                                'female' => 'Female',
                            ]),
                        Select::make('tier')
                            ->required()
                            ->options([
                                'standard' => 'Standard (WaveNet)',
                                'hd' => 'HD (Gemini Chirp3)',
                            ]),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),
                Section::make('Provider & Studio')
                    ->description('Voice studio settings for the hybrid TTS engine.')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        Select::make('provider')
                            ->label('TTS Provider')
                            ->options([
                                'google_cloud' => 'Google Cloud TTS',
                                'gemini' => 'Gemini TTS',
                            ])
                            ->default('google_cloud')
                            ->helperText('Which TTS engine this voice belongs to.'),
                        TextInput::make('provider_voice_id')
                            ->label('Provider Voice ID')
                            ->maxLength(255)
                            ->helperText('The voice identifier used by the provider (e.g. Gemini voice name).'),
                        Select::make('dialect')
                            ->label('Arabic Dialect')
                            ->options([
                                '' => 'None / Not Arabic',
                                'ar-XA' => 'MSA (Modern Standard Arabic)',
                                'ar-SA' => 'Saudi Arabic',
                                'ar-AE' => 'Emirati Arabic',
                                'ar-EG' => 'Egyptian Arabic',
                                'ar-LB' => 'Lebanese Arabic',
                                'ar-SY' => 'Syrian Arabic',
                                'ar-JO' => 'Jordanian Arabic',
                                'ar-IQ' => 'Iraqi Arabic',
                                'ar-MA' => 'Moroccan Arabic',
                                'ar-DZ' => 'Algerian Arabic',
                                'ar-TN' => 'Tunisian Arabic',
                                'ar-KW' => 'Kuwaiti Arabic',
                                'ar-QA' => 'Qatari Arabic',
                                'ar-BH' => 'Bahraini Arabic',
                                'ar-OM' => 'Omani Arabic',
                                'ar-YE' => 'Yemeni Arabic',
                                'ar-SD' => 'Sudanese Arabic',
                                'ar-LY' => 'Libyan Arabic',
                                'ar-PS' => 'Palestinian Arabic',
                            ])
                            ->searchable()
                            ->helperText('Specific Arabic dialect for this voice.'),
                        TextInput::make('country')
                            ->label('Country Code')
                            ->maxLength(5)
                            ->placeholder('e.g. SA, US, GB')
                            ->helperText('ISO country code for flag display.'),
                        TagsInput::make('supported_emotions')
                            ->label('Supported Emotions')
                            ->placeholder('Add emotion...')
                            ->suggestions([
                                'neutral', 'warm', 'sad', 'excited', 'formal',
                                'storytelling', 'ad_style', 'news', 'calm', 'angry',
                            ])
                            ->helperText('Emotions this voice can express (Gemini voices only).'),
                        TextInput::make('preview_audio_path')
                            ->label('Preview Audio Path')
                            ->maxLength(500)
                            ->helperText('Path to a sample audio clip for this voice (relative to public/).'),
                        TextInput::make('sort_order')
                            ->label('Sort Order')
                            ->numeric()
                            ->default(0)
                            ->helperText('Lower numbers appear first in the voice picker.'),
                        Toggle::make('is_featured')
                            ->label('Featured Voice')
                            ->helperText('Show this voice in the featured section of the voice picker.')
                            ->default(false),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('display_name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('internal_voice_id')
                    ->label('Voice ID')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('language')
                    ->sortable()
                    ->badge(),
                TextColumn::make('gender')
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'male' => 'info',
                        'female' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('tier')
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'hd' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('provider')
                    ->label('Provider')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'gemini' => 'success',
                        'google_cloud' => 'info',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('dialect')
                    ->label('Dialect')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_featured')
                    ->label('Featured')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('language')
                    ->options(fn () => Voice::query()->distinct()->pluck('language', 'language')->toArray()),
                SelectFilter::make('gender')
                    ->options([
                        'male' => 'Male',
                        'female' => 'Female',
                    ]),
                SelectFilter::make('tier')
                    ->options([
                        'standard' => 'Standard (WaveNet)',
                        'hd' => 'HD (Gemini Chirp3)',
                    ]),
                TernaryFilter::make('is_active')
                    ->label('Active'),
                SelectFilter::make('provider')
                    ->options([
                        'google_cloud' => 'Google Cloud',
                        'gemini' => 'Gemini',
                    ]),
                TernaryFilter::make('is_featured')
                    ->label('Featured'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVoices::route('/'),
            'create' => CreateVoice::route('/create'),
            'view' => ViewVoice::route('/{record}'),
            'edit' => EditVoice::route('/{record}/edit'),
        ];
    }
}
