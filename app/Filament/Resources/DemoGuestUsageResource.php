<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DemoGuestUsageResource\Pages\ListDemoGuestUsages;
use App\Filament\Resources\DemoGuestUsageResource\Pages\ViewDemoGuestUsage;
use App\Models\DemoGuestUsage;
use App\Models\DemoIdentityLink;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class DemoGuestUsageResource extends Resource
{
    protected static ?string $model = DemoGuestUsage::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|\UnitEnum|null $navigationGroup = 'Demo Tracking';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Guest Usage';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Guest Usage')
                    ->columns(2)
                    ->schema([
                        TextInput::make('identity_hash')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('link_group')
                            ->maxLength(80),
                        TextInput::make('used_chars')
                            ->numeric()
                            ->required(),
                        TextInput::make('abuse_score')
                            ->numeric()
                            ->default(0),
                        Toggle::make('is_blocked')
                            ->default(false),
                        DateTimePicker::make('last_seen_at'),
                    ]),
                Section::make('Metadata')
                    ->schema([
                        KeyValue::make('metadata'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('identity_hash')
                    ->label('Identity')
                    ->searchable()
                    ->limit(16)
                    ->tooltip(fn ($record) => $record->identity_hash),
                TextColumn::make('link_group')
                    ->label('Link Group')
                    ->searchable()
                    ->limit(16)
                    ->toggleable(),
                TextColumn::make('used_chars')
                    ->label('Chars Used')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('abuse_score')
                    ->label('Abuse Score')
                    ->numeric()
                    ->sortable()
                    ->color(fn ($state) => match (true) {
                        $state >= 100 => 'danger',
                        $state >= 50 => 'warning',
                        $state > 0 => 'gray',
                        default => null,
                    }),
                IconColumn::make('is_blocked')
                    ->label('Blocked')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_blocked')
                    ->label('Blocked'),
                Filter::make('high_abuse')
                    ->label('High Abuse Score')
                    ->query(fn ($query) => $query->where('abuse_score', '>=', 50)),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('reset_chars')
                    ->label('Reset Chars')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Reset used_chars to 0 for this identity only.')
                    ->action(fn ($record) => $record->update(['used_chars' => 0])),
                Action::make('reset_link_group')
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
                Action::make('block')
                    ->label('Block')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn ($record) => ! $record->is_blocked)
                    ->action(fn ($record) => $record->update(['is_blocked' => true])),
                Action::make('unblock')
                    ->label('Unblock')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn ($record) => $record->is_blocked)
                    ->action(fn ($record) => $record->update(['is_blocked' => false])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    BulkAction::make('reset_chars_selected')
                        ->label('Reset Chars')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['used_chars' => 0])),
                    BulkAction::make('block_selected')
                        ->label('Block Selected')
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['is_blocked' => true])),
                    BulkAction::make('unblock_selected')
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
            'index' => ListDemoGuestUsages::route('/'),
            'view' => ViewDemoGuestUsage::route('/{record}'),
        ];
    }
}
