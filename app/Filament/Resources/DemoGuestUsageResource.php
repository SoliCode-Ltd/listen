<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DemoGuestUsageResource\Pages;
use App\Models\DemoGuestUsage;
use App\Models\DemoIdentityLink;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;

class DemoGuestUsageResource extends Resource
{
    protected static ?string $model = DemoGuestUsage::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Demo Tracking';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Guest Usage';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Guest Usage')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('identity_hash')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('link_group')
                            ->maxLength(80),
                        Forms\Components\TextInput::make('used_chars')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('abuse_score')
                            ->numeric()
                            ->default(0),
                        Forms\Components\Toggle::make('is_blocked')
                            ->default(false),
                        Forms\Components\DateTimePicker::make('last_seen_at'),
                    ]),
                Forms\Components\Section::make('Metadata')
                    ->schema([
                        Forms\Components\KeyValue::make('metadata'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('identity_hash')
                    ->label('Identity')
                    ->searchable()
                    ->limit(16)
                    ->tooltip(fn ($record) => $record->identity_hash),
                Tables\Columns\TextColumn::make('link_group')
                    ->label('Link Group')
                    ->searchable()
                    ->limit(16)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('used_chars')
                    ->label('Chars Used')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('abuse_score')
                    ->label('Abuse Score')
                    ->numeric()
                    ->sortable()
                    ->color(fn ($state) => match (true) {
                        $state >= 100 => 'danger',
                        $state >= 50 => 'warning',
                        $state > 0 => 'gray',
                        default => null,
                    }),
                Tables\Columns\IconColumn::make('is_blocked')
                    ->label('Blocked')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_blocked')
                    ->label('Blocked'),
                Tables\Filters\Filter::make('high_abuse')
                    ->label('High Abuse Score')
                    ->query(fn ($query) => $query->where('abuse_score', '>=', 50)),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('reset_chars')
                    ->label('Reset Chars')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Reset used_chars to 0 for this identity only.')
                    ->action(fn ($record) => $record->update(['used_chars' => 0])),
                Tables\Actions\Action::make('reset_link_group')
                    ->label('Reset Link Group')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Reset ALL identities in the same link group and remove links. Use this to fully reset a user\'s demo quota.')
                    ->visible(fn ($record) => ! empty($record->link_group))
                    ->action(function ($record) {
                        $linkGroup = $record->link_group;
                        if (empty($linkGroup)) {
                            return;
                        }
                        // Reset all usage records in this link group
                        DemoGuestUsage::where('link_group', $linkGroup)
                            ->update(['used_chars' => 0, 'link_group' => null]);
                        // Delete all identity links in this group
                        DemoIdentityLink::where('link_group', $linkGroup)->delete();
                        Notification::make()
                            ->success()
                            ->title('Link group reset')
                            ->body("All identities in group {$linkGroup} have been reset.")
                            ->send();
                    }),
                Tables\Actions\Action::make('block')
                    ->label('Block')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn ($record) => ! $record->is_blocked)
                    ->action(fn ($record) => $record->update(['is_blocked' => true])),
                Tables\Actions\Action::make('unblock')
                    ->label('Unblock')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn ($record) => $record->is_blocked)
                    ->action(fn ($record) => $record->update(['is_blocked' => false])),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\BulkAction::make('reset_chars_selected')
                        ->label('Reset Chars')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['used_chars' => 0])),
                    Tables\Actions\BulkAction::make('block_selected')
                        ->label('Block Selected')
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['is_blocked' => true])),
                    Tables\Actions\BulkAction::make('unblock_selected')
                        ->label('Unblock Selected')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['is_blocked' => false])),
                ]),
            ])
            ->defaultSort('last_seen_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDemoGuestUsages::route('/'),
            'view' => Pages\ViewDemoGuestUsage::route('/{record}'),
        ];
    }
}
