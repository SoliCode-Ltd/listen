<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\NewsletterSuppressionResource\Pages\ListNewsletterSuppressions;
use App\Filament\Resources\NewsletterSuppressionResource\Pages\CreateNewsletterSuppression;
use App\Filament\Resources\NewsletterSuppressionResource\Pages;
use App\Models\NewsletterSuppression;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class NewsletterSuppressionResource extends Resource
{
    protected static ?string $model = NewsletterSuppression::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-no-symbol';

    protected static string | \UnitEnum | null $navigationGroup = 'Newsletter';

    protected static ?string $navigationLabel = 'Unsubscribes';

    protected static ?string $modelLabel = 'unsubscribe';

    protected static ?string $pluralModelLabel = 'Unsubscribes';

    protected static ?string $slug = 'newsletter-unsubscribes';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')
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
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('unsubscribed_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('unsubscribed_at', 'desc')
            ->recordActions([
                DeleteAction::make()
                    ->label('Allow emails again')
                    ->modalHeading('Allow emails again')
                    ->modalDescription('Removes this address from the unsubscribe list. A later newsletter can include it.'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No unsubscribes')
            ->emptyStateDescription('People who use the link in a newsletter show up here. You can also add an address yourself.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNewsletterSuppressions::route('/'),
            'create' => CreateNewsletterSuppression::route('/create'),
        ];
    }
}
