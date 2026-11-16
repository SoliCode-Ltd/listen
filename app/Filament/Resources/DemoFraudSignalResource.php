<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DemoFraudSignalResource\Pages\ListDemoFraudSignals;
use App\Models\DemoFraudSignal;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DemoFraudSignalResource extends Resource
{
    protected static ?string $model = DemoFraudSignal::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static string|\UnitEnum|null $navigationGroup = 'Demo Tracking';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Fraud Signals';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Fraud Signal')
                    ->columns(2)
                    ->schema([
                        TextInput::make('identity_hash')
                            ->required()
                            ->maxLength(80),
                        Select::make('signal_type')
                            ->options([
                                'automation_detected' => 'Automation Detected',
                                'missing_client_hints' => 'Missing Client Hints',
                                'webdriver_detected' => 'WebDriver Detected',
                                'fingerprint_blocking' => 'Fingerprint Blocking',
                                'ip_fingerprint_flood' => 'IP Fingerprint Flood',
                                'private_ip' => 'Private IP',
                                'vpn_detected' => 'VPN Detected',
                                'bot_suspected' => 'Bot Suspected',
                            ])
                            ->required(),
                    ]),
                Section::make('Signal Data')
                    ->schema([
                        KeyValue::make('signal_data'),
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
                BadgeColumn::make('signal_type')
                    ->label('Signal Type')
                    ->searchable()
                    ->colors([
                        'danger' => fn ($state) => in_array($state, ['automation_detected', 'webdriver_detected', 'bot_suspected']),
                        'warning' => fn ($state) => in_array($state, ['fingerprint_blocking', 'ip_fingerprint_flood', 'vpn_detected']),
                        'gray' => fn ($state) => in_array($state, ['missing_client_hints', 'private_ip']),
                    ]),
                TextColumn::make('signal_data')
                    ->label('Data')
                    // One line, as Filament 3 showed it (its values, a nested one as JSON), cut at 50
                    // as a whole: Filament 5 would format and cut each value on its own.
                    ->state(fn (DemoFraudSignal $record): ?string => is_array($record->signal_data)
                        ? implode(', ', array_map(fn (mixed $value): string => is_array($value) ? (string) json_encode($value) : (string) $value, $record->signal_data))
                        : $record->signal_data)
                    ->limit(50)
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Detected At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('signal_type')
                    ->multiple()
                    ->options([
                        'automation_detected' => 'Automation Detected',
                        'missing_client_hints' => 'Missing Client Hints',
                        'webdriver_detected' => 'WebDriver Detected',
                        'fingerprint_blocking' => 'Fingerprint Blocking',
                        'ip_fingerprint_flood' => 'IP Fingerprint Flood',
                        'private_ip' => 'Private IP',
                        'vpn_detected' => 'VPN Detected',
                        'bot_suspected' => 'Bot Suspected',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDemoFraudSignals::route('/'),
        ];
    }
}
