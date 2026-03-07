<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DemoFraudSignalResource\Pages;
use App\Models\DemoFraudSignal;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DemoFraudSignalResource extends Resource
{
    protected static ?string $model = DemoFraudSignal::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationGroup = 'Demo Tracking';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Fraud Signals';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Fraud Signal')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('identity_hash')
                            ->required()
                            ->maxLength(80),
                        Forms\Components\Select::make('signal_type')
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
                Forms\Components\Section::make('Signal Data')
                    ->schema([
                        Forms\Components\KeyValue::make('signal_data'),
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
                Tables\Columns\BadgeColumn::make('signal_type')
                    ->label('Signal Type')
                    ->searchable()
                    ->colors([
                        'danger' => fn ($state) => in_array($state, ['automation_detected', 'webdriver_detected', 'bot_suspected']),
                        'warning' => fn ($state) => in_array($state, ['fingerprint_blocking', 'ip_fingerprint_flood', 'vpn_detected']),
                        'gray' => fn ($state) => in_array($state, ['missing_client_hints', 'private_ip']),
                    ]),
                Tables\Columns\TextColumn::make('signal_data')
                    ->label('Data')
                    ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_UNESCAPED_UNICODE) : $state)
                    ->limit(50)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Detected At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('signal_type')
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
            ->actions([
                Tables\Actions\ViewAction::make(),
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
            'index' => Pages\ListDemoFraudSignals::route('/'),
        ];
    }
}
