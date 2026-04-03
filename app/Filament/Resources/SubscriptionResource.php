<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubscriptionResource\Pages;
use App\Models\Subscription;
use App\Models\UsageCounter;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Subscription Details')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->relationship('user', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('plan_id')
                            ->relationship('plan', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('status')
                            ->options([
                                'active' => 'Active',
                                'canceled' => 'Canceled',
                                'past_due' => 'Past Due',
                                'trialing' => 'Trialing',
                                'expired' => 'Expired',
                                'incomplete' => 'Incomplete',
                            ])
                            ->required(),
                        Forms\Components\Select::make('billing_payment_state')
                            ->options([
                                'paid' => 'Paid',
                                'pending' => 'Pending',
                                'failed' => 'Failed',
                                'grace' => 'Grace',
                                'manual_override' => 'Manual Override (Admin)',
                            ]),
                    ]),
                Forms\Components\Section::make('Billing Period')
                    ->columns(2)
                    ->schema([
                        Forms\Components\DateTimePicker::make('current_period_start'),
                        Forms\Components\DateTimePicker::make('current_period_end'),
                        Forms\Components\DateTimePicker::make('billing_grace_ends_at')
                            ->label('Grace Period Ends'),
                    ]),
                Forms\Components\Section::make('Notifications & Lifecycle')
                    ->columns(2)
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Forms\Components\DateTimePicker::make('billing_reminder_7d_sent_at')
                            ->label('7-Day Reminder Sent'),
                        Forms\Components\DateTimePicker::make('billing_reminder_1d_sent_at')
                            ->label('1-Day Reminder Sent'),
                        Forms\Components\DateTimePicker::make('billing_failed_notice_sent_at')
                            ->label('Failed Notice Sent'),
                        Forms\Components\DateTimePicker::make('billing_downgraded_at')
                            ->label('Downgraded At'),
                    ]),
                Forms\Components\Section::make('Usage History')
                    ->schema([
                        Forms\Components\Placeholder::make('usage_table')
                            ->label('')
                            ->content(function (?Subscription $record): HtmlString|string {
                                if (! $record || ! $record->user_id) {
                                    return 'No subscription loaded.';
                                }

                                $counters = UsageCounter::where('user_id', $record->user_id)
                                    ->orderByDesc('period_ym')
                                    ->get();

                                if ($counters->isEmpty()) {
                                    return 'No usage data recorded yet.';
                                }

                                $currentYm = now()->format('Y-m');

                                $rows = $counters->map(function (UsageCounter $c) use ($currentYm): string {
                                    $isCurrent = $c->period_ym === $currentYm;
                                    $label = $isCurrent
                                        ? e($c->period_ym) . ' <span style="background:#22c55e;color:#fff;padding:1px 8px;border-radius:9999px;font-size:11px;margin-left:6px;">Current</span>'
                                        : e($c->period_ym);
                                    $bg = $isCurrent ? 'background:rgba(34,197,94,0.06);' : '';

                                    return '<tr style="' . $bg . '">'
                                        . '<td style="padding:8px 12px;border-bottom:1px solid rgba(0,0,0,0.06);">' . $label . '</td>'
                                        . '<td style="padding:8px 12px;border-bottom:1px solid rgba(0,0,0,0.06);text-align:right;">' . number_format((float) $c->total_chars) . '</td>'
                                        . '<td style="padding:8px 12px;border-bottom:1px solid rgba(0,0,0,0.06);text-align:right;">' . number_format((float) $c->total_minutes, 1) . '</td>'
                                        . '<td style="padding:8px 12px;border-bottom:1px solid rgba(0,0,0,0.06);text-align:right;">' . number_format((int) $c->total_requests) . '</td>'
                                        . '</tr>';
                                })->implode('');

                                $html = '<div style="overflow-x:auto;">'
                                    . '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
                                    . '<thead><tr style="background:rgba(0,0,0,0.03);font-weight:600;">'
                                    . '<th style="padding:8px 12px;text-align:left;">Period</th>'
                                    . '<th style="padding:8px 12px;text-align:right;">Characters</th>'
                                    . '<th style="padding:8px 12px;text-align:right;">Minutes</th>'
                                    . '<th style="padding:8px 12px;text-align:right;">Requests</th>'
                                    . '</tr></thead>'
                                    . '<tbody>' . $rows . '</tbody>'
                                    . '</table></div>';

                                return new HtmlString($html);
                            }),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('plan.name')
                    ->label('Plan')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'trialing' => 'info',
                        'past_due' => 'warning',
                        'canceled', 'expired' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('billing_payment_state')
                    ->label('Payment')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'paid' => 'success',
                        'pending' => 'warning',
                        'failed' => 'danger',
                        'grace' => 'info',
                        'manual_override' => 'primary',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('current_period_end')
                    ->label('Period Ends')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'canceled' => 'Canceled',
                        'past_due' => 'Past Due',
                        'trialing' => 'Trialing',
                        'expired' => 'Expired',
                    ]),
                Tables\Filters\SelectFilter::make('plan_id')
                    ->relationship('plan', 'name')
                    ->label('Plan'),
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
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptions::route('/'),
            'create' => Pages\CreateSubscription::route('/create'),
            'view' => Pages\ViewSubscription::route('/{record}'),
            'edit' => Pages\EditSubscription::route('/{record}/edit'),
        ];
    }
}
