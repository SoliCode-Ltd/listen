<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClonedVoiceResource\Pages;
use App\Models\ClonedVoice;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ClonedVoiceResource extends Resource
{
    protected static ?string $model = ClonedVoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-microphone';

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Cloned Voices';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Voice Details')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')->disabled(),
                        Forms\Components\TextInput::make('user.email')
                            ->label('User Email')
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('source_type')->disabled(),
                        Forms\Components\TextInput::make('language_code')->disabled(),
                        Forms\Components\TextInput::make('source_audio_duration_seconds')
                            ->label('Duration (s)')
                            ->disabled(),
                        Forms\Components\Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'validating' => 'Validating',
                                'processing' => 'Processing',
                                'ready' => 'Ready',
                                'failed' => 'Failed',
                            ]),
                        Forms\Components\Textarea::make('failure_reason')->disabled()->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('user.email')
                    ->label('User')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('source_type')
                    ->badge(),
                Tables\Columns\TextColumn::make('language_code')
                    ->label('Lang'),
                Tables\Columns\TextColumn::make('source_audio_duration_seconds')
                    ->label('Duration')
                    ->suffix('s'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'ready' => 'success',
                        'processing', 'validating', 'pending' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('consent_confirmed')
                    ->boolean()
                    ->label('Consent'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'validating' => 'Validating',
                        'processing' => 'Processing',
                        'ready' => 'Ready',
                        'failed' => 'Failed',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClonedVoices::route('/'),
            'edit' => Pages\EditClonedVoice::route('/{record}/edit'),
        ];
    }
}
