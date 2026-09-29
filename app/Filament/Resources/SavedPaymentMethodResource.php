<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SavedPaymentMethodResource\Pages;
use App\Models\SavedPaymentMethod;
use Filament\Forms;
use Illuminate\Database\Eloquent\Model;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SavedPaymentMethodResource extends Resource
{
    protected static ?string $model = SavedPaymentMethod::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Saved cards';

    protected static ?string $modelLabel = 'Saved card';

    protected static ?int $navigationSort = 6;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Card')
                ->description('The full card number and CVC are never stored. Stripe keeps those.')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('user_id')->disabled(),
                    Forms\Components\TextInput::make('brand')->disabled(),
                    Forms\Components\TextInput::make('last4')->disabled(),
                    Forms\Components\TextInput::make('exp_month')->disabled(),
                    Forms\Components\TextInput::make('exp_year')->disabled(),
                    Forms\Components\TextInput::make('funding')->disabled(),
                    Forms\Components\TextInput::make('country')->disabled(),
                    Forms\Components\TextInput::make('fingerprint')->disabled(),
                    Forms\Components\TextInput::make('cvc_check')->disabled(),
                    Forms\Components\TextInput::make('purpose')->disabled(),
                    Forms\Components\Toggle::make('off_session_ready')->disabled(),
                    Forms\Components\Toggle::make('is_default')->disabled(),
                    Forms\Components\TextInput::make('stripe_customer_id')->disabled(),
                    Forms\Components\TextInput::make('stripe_payment_method_id')->disabled(),
                    Forms\Components\TextInput::make('setup_intent_id')->disabled(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('user.email')->label('User')->searchable(),
                Tables\Columns\TextColumn::make('brand')->searchable(),
                Tables\Columns\TextColumn::make('last4')->label('Last 4')->searchable(),
                Tables\Columns\TextColumn::make('exp_month')->label('Exp month'),
                Tables\Columns\TextColumn::make('exp_year')->label('Exp year'),
                Tables\Columns\TextColumn::make('purpose')->badge(),
                Tables\Columns\IconColumn::make('off_session_ready')->boolean()->label('Off-session'),
                Tables\Columns\IconColumn::make('is_default')->boolean()->label('Default'),
                Tables\Columns\TextColumn::make('fingerprint')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('stripe_payment_method_id')->label('Stripe PM')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSavedPaymentMethods::route('/'),
            'view' => Pages\ViewSavedPaymentMethod::route('/{record}'),
        ];
    }
}
