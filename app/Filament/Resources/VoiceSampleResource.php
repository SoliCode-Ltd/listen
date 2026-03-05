<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VoiceSampleResource\Pages;
use App\Models\VoiceSample;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class VoiceSampleResource extends Resource
{
    protected static ?string $model = VoiceSample::class;

    protected static ?string $navigationIcon = 'heroicon-o-play-circle';

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Voice Samples';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Sample Details')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Display title (English).'),
                        Forms\Components\TextInput::make('title_ar')
                            ->label('Title (Arabic)')
                            ->maxLength(255)
                            ->helperText('Display title for Arabic locale.'),
                        Forms\Components\TextInput::make('description')
                            ->maxLength(500)
                            ->helperText('Short English description shown on card.'),
                        Forms\Components\TextInput::make('description_ar')
                            ->label('Description (Arabic)')
                            ->maxLength(500)
                            ->helperText('Short Arabic description shown on card.'),
                        Forms\Components\TextInput::make('icon')
                            ->maxLength(100)
                            ->helperText('Flag emoji or icon (e.g. 🇸🇦).'),
                        Forms\Components\TextInput::make('country')
                            ->maxLength(10)
                            ->helperText('2-letter country code (e.g. SA, EG, US).'),
                        Forms\Components\Textarea::make('sample_text')
                            ->label('Sample Text')
                            ->required()
                            ->rows(4)
                            ->columnSpanFull()
                            ->helperText('The text that was spoken in this sample.'),
                        Forms\Components\Textarea::make('sample_text_ar')
                            ->label('Sample Text (Arabic)')
                            ->rows(4)
                            ->columnSpanFull()
                            ->helperText('Arabic version of the sample text. Falls back to sample_text if empty.'),
                        Forms\Components\TextInput::make('audio_path')
                            ->label('Audio File Path')
                            ->required()
                            ->maxLength(500)
                            ->helperText('Path to audio file relative to public/ (e.g. audio/samples/greeting.mp3).'),
                    ]),
                Forms\Components\Section::make('Classification')
                    ->columns(3)
                    ->schema([
                        Forms\Components\Select::make('language')
                            ->options([
                                'ar' => 'Arabic',
                                'en' => 'English',
                                'fr' => 'French',
                                'de' => 'German',
                                'es' => 'Spanish',
                                'ja' => 'Japanese',
                                'ko' => 'Korean',
                                'zh' => 'Chinese',
                            ])
                            ->default('ar')
                            ->required(),
                        Forms\Components\Select::make('dialect')
                            ->options([
                                '' => 'None',
                                'ar-XA' => 'MSA',
                                'ar-SA' => 'Saudi',
                                'ar-EG' => 'Egyptian',
                                'ar-LB' => 'Lebanese',
                                'ar-AE' => 'Emirati',
                                'ar-SY' => 'Syrian',
                                'ar-JO' => 'Jordanian',
                                'ar-IQ' => 'Iraqi',
                                'ar-MA' => 'Moroccan',
                                'ar-KW' => 'Kuwaiti',
                                'ar-TN' => 'Tunisian',
                                'en-US' => 'American',
                                'en-GB' => 'British',
                                'en-AU' => 'Australian',
                                'en-IN' => 'Indian',
                            ])
                            ->searchable(),
                        Forms\Components\Select::make('emotion')
                            ->options([
                                'neutral' => 'Neutral',
                                'warm' => 'Warm',
                                'sad' => 'Sad',
                                'excited' => 'Excited',
                                'formal' => 'Formal',
                                'storytelling' => 'Storytelling',
                                'ad_style' => 'Ad Style',
                                'news' => 'News',
                                'calm' => 'Calm',
                                'angry' => 'Angry',
                            ])
                            ->default('neutral'),
                        Forms\Components\Select::make('category')
                            ->options([
                                'hero' => 'Hero Section',
                                'demo' => 'Demo Page',
                                'feature' => 'Feature Showcase',
                                'testimonial' => 'Testimonial',
                                'comparison' => 'Quality Comparison',
                            ])
                            ->default('hero')
                            ->required()
                            ->helperText('Where this sample is displayed.'),
                        Forms\Components\TextInput::make('voice_name')
                            ->label('Voice Name')
                            ->maxLength(255)
                            ->helperText('Name of the TTS voice used.'),
                        Forms\Components\TextInput::make('provider')
                            ->maxLength(50)
                            ->helperText('e.g. google_cloud or gemini'),
                        Forms\Components\Select::make('gender')
                            ->options([
                                'male' => 'Male',
                                'female' => 'Female',
                            ])
                            ->default('male'),
                    ]),
                Forms\Components\Section::make('Display')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        Forms\Components\Toggle::make('is_featured')
                            ->label('Featured')
                            ->default(false)
                            ->helperText('Featured samples appear prominently in the hero section.'),
                        Forms\Components\TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->helperText('Lower = appears first.'),
                        Forms\Components\KeyValue::make('metadata')
                            ->label('Metadata (JSON)')
                            ->addActionLabel('Add Field')
                            ->helperText('Extra data: duration, quality tier, etc.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('title')->searchable()->sortable()->limit(40),
                Tables\Columns\TextColumn::make('language')->badge()->sortable(),
                Tables\Columns\TextColumn::make('dialect')->badge()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('emotion')->badge()->color('info')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('category')->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'hero' => 'success',
                        'demo' => 'info',
                        'feature' => 'warning',
                        'comparison' => 'primary',
                        default => 'gray',
                    })->sortable(),
                Tables\Columns\TextColumn::make('provider')->badge()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_featured')->label('Featured')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean()->sortable(),
                Tables\Columns\TextColumn::make('sort_order')->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->options([
                        'hero' => 'Hero Section',
                        'demo' => 'Demo Page',
                        'feature' => 'Feature Showcase',
                        'comparison' => 'Quality Comparison',
                    ]),
                Tables\Filters\SelectFilter::make('language')
                    ->options(fn () => VoiceSample::query()->distinct()->pluck('language', 'language')->toArray()),
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
                Tables\Filters\TernaryFilter::make('is_featured')->label('Featured'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort_order', 'asc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVoiceSamples::route('/'),
            'create' => Pages\CreateVoiceSample::route('/create'),
            'edit' => Pages\EditVoiceSample::route('/{record}/edit'),
        ];
    }
}
