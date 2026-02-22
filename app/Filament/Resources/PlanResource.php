<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlanResource\Pages;
use App\Models\Plan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Plan Identity')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('URL-safe identifier (e.g. "free", "creator", "pro")'),
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Display name shown to users'),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Inactive plans are hidden from pricing page'),
                    ]),
                Forms\Components\Section::make('Pricing')
                    ->description('Enter prices in dollars. Stored as cents in the database. Yearly auto-calculated at 20% discount.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('price_dollars')
                            ->label('Monthly Price ($)')
                            ->numeric()
                            ->prefix('$')
                            ->step('0.01')
                            ->required()
                            ->dehydrated(false) // Virtual field — we handle saving manually
                            ->afterStateHydrated(function (Forms\Components\TextInput $component, ?Plan $record) {
                                if ($record) {
                                    $component->state(number_format($record->price_cents / 100, 2, '.', ''));
                                } else {
                                    $component->state('0.00');
                                }
                            })
                            ->helperText('Yearly price is auto-calculated at 20% discount'),
                        Forms\Components\Placeholder::make('yearly_preview')
                            ->label('Yearly Price Preview')
                            ->content(function (?Plan $record) {
                                if (!$record || $record->price_cents <= 0) return 'Free';
                                $yearlyDollars = round(($record->price_cents / 100) * 12 * 0.8, 2);
                                return '$' . number_format($yearlyDollars, 2) . '/year ($' . number_format($yearlyDollars / 12, 2) . '/mo)';
                            }),
                    ]),
                Forms\Components\Section::make('Voice & Quality')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('voice_tier')
                            ->label('Voice Quality Tier')
                            ->options([
                                'standard' => 'Standard',
                                'hd' => 'HD',
                                'hd_plus' => 'HD+',
                            ])
                            ->required()
                            ->helperText('Voice quality level users get on this plan'),
                        Forms\Components\Select::make('processing_priority')
                            ->options([
                                'standard' => 'Standard',
                                'high' => 'High',
                            ])
                            ->required()
                            ->default('standard'),
                        Forms\Components\Toggle::make('commercial_use_allowed')
                            ->label('Commercial Use Allowed')
                            ->helperText('Whether users can use generated audio commercially'),
                    ]),
                Forms\Components\Section::make('Usage Limits')
                    ->description('Set 0 for unlimited requests.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('monthly_chars')
                            ->label('Monthly Characters')
                            ->numeric()
                            ->required()
                            ->helperText('Total chars allowed per month'),
                        Forms\Components\TextInput::make('monthly_requests')
                            ->label('Monthly Requests')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->helperText('0 = unlimited requests'),
                        Forms\Components\TextInput::make('monthly_minutes')
                            ->label('Monthly Audio Minutes')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('max_chars_per_request')
                            ->label('Max Characters Per Request')
                            ->numeric()
                            ->required(),
                    ]),
                Forms\Components\Section::make('Overage')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('allow_overage')
                            ->label('Allow Overage')
                            ->reactive()
                            ->helperText('If enabled, users can exceed their limit at additional cost'),
                        Forms\Components\TextInput::make('overage_price_dollars')
                            ->label('Overage Price per 1M chars ($)')
                            ->numeric()
                            ->prefix('$')
                            ->step('0.01')
                            ->dehydrated(false) // Virtual field — handled manually
                            ->visible(fn (Get $get) => $get('allow_overage'))
                            ->afterStateHydrated(function (Forms\Components\TextInput $component, ?Plan $record) {
                                if ($record && $record->overage_price_per_million_cents !== null) {
                                    $component->state(number_format($record->overage_price_per_million_cents / 100, 2, '.', ''));
                                } else {
                                    $component->state('0.00');
                                }
                            }),
                    ]),
                Forms\Components\Section::make('Plan Features')
                    ->description('Features shown on the pricing card. Edit labels, values, and icons. Drag to reorder.')
                    ->schema([
                        Forms\Components\Repeater::make('features')
                            ->label('Features List')
                            ->schema([
                                Forms\Components\TextInput::make('label')
                                    ->label('Label')
                                    ->required()
                                    ->placeholder('e.g. Requests')
                                    ->columnSpan(1),
                                Forms\Components\TextInput::make('value')
                                    ->label('Value')
                                    ->required()
                                    ->placeholder('e.g. Unlimited')
                                    ->columnSpan(1),
                                Forms\Components\Select::make('icon')
                                    ->label('Icon')
                                    ->options([
                                        'activity' => '📈 Activity (pulse line)',
                                        'type' => '🔤 Type (text/font)',
                                        'lock' => '🔒 Lock (security)',
                                        'bolt' => '⚡ Bolt (speed/priority)',
                                        'mic' => '🎙️ Mic (voice/audio)',
                                        'check' => '✓ Checkmark',
                                        'globe' => '🌐 Globe (language)',
                                        'clock' => '🕐 Clock (time)',
                                        'star' => '⭐ Star (quality)',
                                        'shield' => '🛡️ Shield (protection)',
                                        'download' => '📥 Download',
                                        'users' => '👥 Users (team)',
                                    ])
                                    ->default('check')
                                    ->required()
                                    ->columnSpan(1),
                            ])
                            ->columns(3)
                            ->afterStateHydrated(function (Forms\Components\Repeater $component, ?Plan $record) {
                                if (! $record) {
                                    return;
                                }

                                $raw = is_array($record->features) ? $record->features : [];

                                // Already structured — use as-is
                                if (! empty($raw) && isset($raw[0]) && is_array($raw[0]) && array_key_exists('label', $raw[0])) {
                                    return;
                                }

                                // Auto-build defaults from plan attributes
                                $tier = \App\Support\VoiceTier::normalize((string) ($record->voice_tier ?: \App\Support\VoiceTier::STANDARD));
                                $defaults = [
                                    ['label' => 'Requests', 'value' => (int) $record->monthly_requests <= 0 ? 'Unlimited' : number_format((int) $record->monthly_requests), 'icon' => 'activity'],
                                    ['label' => 'Max input', 'value' => number_format((int) $record->max_chars_per_request) . ' chars', 'icon' => 'type'],
                                    ['label' => 'Commercial use', 'value' => $record->commercial_use_allowed ? 'Allowed' : 'Personal only', 'icon' => 'lock'],
                                    ['label' => 'Priority queue', 'value' => ($record->processing_priority ?? 'standard') === 'high' ? 'High' : 'Standard', 'icon' => 'bolt'],
                                    ['label' => 'Voice quality', 'value' => \App\Support\VoiceTier::label($tier), 'icon' => 'mic'],
                                ];

                                $component->state($defaults);
                            })
                            ->addActionLabel('Add Feature')
                            ->defaultItems(0)
                            ->reorderable()
                            ->collapsible(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->sortable()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('price_cents')
                    ->label('Monthly')
                    ->formatStateUsing(fn (int $state) => $state <= 0 ? 'Free' : '$' . number_format($state / 100, 2))
                    ->sortable(),
                Tables\Columns\TextColumn::make('voice_tier')
                    ->label('Voice Tier')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'hd' => 'warning',
                        'hd_plus' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('monthly_chars')
                    ->label('Chars/mo')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('monthly_requests')
                    ->label('Requests/mo')
                    ->formatStateUsing(fn (int $state) => $state <= 0 ? 'Unlimited' : number_format($state))
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\IconColumn::make('commercial_use_allowed')
                    ->label('Commercial')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
                Tables\Filters\SelectFilter::make('voice_tier')
                    ->options([
                        'standard' => 'Standard',
                        'hd' => 'HD',
                        'hd_plus' => 'HD+',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
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
            'index' => Pages\ListPlans::route('/'),
            'create' => Pages\CreatePlan::route('/create'),
            'view' => Pages\ViewPlan::route('/{record}'),
            'edit' => Pages\EditPlan::route('/{record}/edit'),
        ];
    }
}
