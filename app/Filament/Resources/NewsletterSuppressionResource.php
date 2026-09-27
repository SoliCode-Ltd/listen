<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NewsletterSuppressionResource\Pages;
use App\Models\NewsletterSuppression;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class NewsletterSuppressionResource extends Resource
{
    protected static ?string $model = NewsletterSuppression::class;

    protected static ?string $navigationIcon = 'heroicon-o-no-symbol';

    protected static ?string $navigationGroup = 'Newsletter';

    protected static ?string $navigationLabel = 'Unsubscribes';

    protected static ?string $modelLabel = 'unsubscribe';

    protected static ?string $pluralModelLabel = 'Unsubscribes';

    protected static ?string $slug = 'newsletter-unsubscribes';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('email')
                ->email()
                ->required()
                ->maxLength(255)
                ->helperText('This address is skipped by newsletters that skip unsubscribed people.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('unsubscribed_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('unsubscribed_at', 'desc')
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->label('Allow emails again')
                    ->modalHeading('Allow emails again')
                    ->modalDescription('Removes this address from the unsubscribe list. A later newsletter can include it.'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No unsubscribes')
            ->emptyStateDescription('People who use the link in a newsletter show up here. You can also add an address yourself.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNewsletterSuppressions::route('/'),
            'create' => Pages\CreateNewsletterSuppression::route('/create'),
        ];
    }
}
