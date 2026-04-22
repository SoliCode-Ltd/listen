<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReferralConversionResource\Pages;
use App\Models\DemoRewardTransaction;
use App\Models\ReferralConversion;
use App\Models\UserReferralProfile;
use App\Services\Referral\DemoRewardLedgerService;
use App\Services\Referral\ReferralQualificationService;
use App\Services\Referral\ReferralService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ReferralConversionResource extends Resource
{
    protected static ?string $model = ReferralConversion::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Referrals';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Conversions';

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Conversion')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('id')
                            ->disabled(),
                        Forms\Components\Select::make('status')
                            ->options([
                                ReferralConversion::STATUS_PENDING => 'Pending',
                                ReferralConversion::STATUS_APPROVED => 'Approved',
                                ReferralConversion::STATUS_REJECTED => 'Rejected',
                                ReferralConversion::STATUS_FLAGGED => 'Flagged',
                            ])
                            ->disabled()
                            ->helperText('Use the Approve / Reject actions above to change status.'),
                        Forms\Components\TextInput::make('referrer.email')
                            ->label('Referrer')
                            ->disabled(),
                        Forms\Components\TextInput::make('referredUser.email')
                            ->label('Referred User')
                            ->disabled(),
                        Forms\Components\TextInput::make('qualification_step')
                            ->disabled(),
                        Forms\Components\TextInput::make('reward_chars')
                            ->disabled(),
                        Forms\Components\Textarea::make('rejection_reason')
                            ->disabled()
                            ->columnSpanFull(),
                        Forms\Components\KeyValue::make('fraud_indicators')
                            ->disabled()
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('reviewer_notes')
                            ->rows(3)
                            ->columnSpanFull()
                            ->helperText('Internal notes visible only to admins.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable()->label('#'),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => ReferralConversion::STATUS_PENDING,
                        'success' => ReferralConversion::STATUS_APPROVED,
                        'danger' => ReferralConversion::STATUS_REJECTED,
                        'gray' => ReferralConversion::STATUS_FLAGGED,
                    ])
                    ->sortable(),
                Tables\Columns\TextColumn::make('referrer.email')
                    ->label('Referrer')
                    ->searchable()
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->referrer?->email),
                Tables\Columns\TextColumn::make('referredUser.email')
                    ->label('Referred')
                    ->searchable()
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->referredUser?->email),
                Tables\Columns\TextColumn::make('qualification_step')
                    ->label('Trigger')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('reward_chars')
                    ->label('Reward')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('fraud_indicators')
                    ->label('Flags')
                    ->formatStateUsing(function ($state) {
                        if (! is_array($state) || empty($state)) return '—';
                        return implode(', ', array_slice($state, 0, 3));
                    })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('qualified_at')
                    ->label('Qualified')
                    ->dateTime()
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('approved_at')
                    ->label('Approved')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        ReferralConversion::STATUS_PENDING => 'Pending',
                        ReferralConversion::STATUS_APPROVED => 'Approved',
                        ReferralConversion::STATUS_REJECTED => 'Rejected',
                        ReferralConversion::STATUS_FLAGGED => 'Flagged',
                    ])
                    ->multiple(),
                Tables\Filters\SelectFilter::make('qualification_step')
                    ->options([
                        'paid_plan' => 'Paid Plan',
                        'signup' => 'Signup',
                        'email_verified' => 'Email Verified',
                        'first_demo_generation' => 'First Demo',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (ReferralConversion $record) => $record->status !== ReferralConversion::STATUS_APPROVED)
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('notes')
                            ->label('Reviewer notes (optional)')
                            ->rows(3),
                    ])
                    ->action(function (ReferralConversion $record, array $data) {
                        $service = app(ReferralQualificationService::class);
                        $reviewer = auth()->user();
                        if (! $reviewer) return;
                        $service->manuallyApprove($record, $reviewer, $data['notes'] ?? null);
                        Notification::make()
                            ->title('Conversion approved and reward granted.')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ReferralConversion $record) => $record->status !== ReferralConversion::STATUS_REJECTED)
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\TextInput::make('reason')
                            ->label('Rejection reason')
                            ->maxLength(120)
                            ->required(),
                        Forms\Components\Textarea::make('notes')
                            ->label('Reviewer notes (optional)')
                            ->rows(3),
                    ])
                    ->action(function (ReferralConversion $record, array $data) {
                        $service = app(ReferralQualificationService::class);
                        $reviewer = auth()->user();
                        if (! $reviewer) return;
                        $service->manuallyReject($record, $reviewer, $data['notes'] ?? null, $data['reason'] ?? null);
                        Notification::make()
                            ->title('Conversion rejected.')
                            ->danger()
                            ->send();
                    }),
                Tables\Actions\DeleteAction::make()
                    ->modalHeading('Delete conversion & revoke reward')
                    ->modalDescription('Any demo reward characters granted from this conversion will be revoked from the referrer.')
                    ->before(function (ReferralConversion $record) {
                        self::revokeRewardForConversion($record);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function (Collection $records) {
                            $records->each(fn (ReferralConversion $record) => self::revokeRewardForConversion($record));
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReferralConversions::route('/'),
            'view' => Pages\ViewReferralConversion::route('/{record}'),
        ];
    }

    /**
     * Revoke reward chars currently attributable to the given conversion and
     * recompute the referrer's profile counters. Idempotent — if the net
     * outstanding is already zero (e.g. already rejected), this is a no-op.
     */
    protected static function revokeRewardForConversion(ReferralConversion $conversion): void
    {
        try {
            $netOutstanding = (int) DemoRewardTransaction::query()
                ->where('conversion_id', $conversion->id)
                ->whereIn('kind', [DemoRewardTransaction::KIND_GRANT, DemoRewardTransaction::KIND_REVOKE])
                ->sum('delta_chars');

            if ($netOutstanding > 0) {
                $referrer = $conversion->referrer()->first();
                if ($referrer) {
                    app(DemoRewardLedgerService::class)
                        ->revoke($referrer, $netOutstanding, null, 'conversion_deleted');

                    $profile = UserReferralProfile::query()
                        ->where('user_id', $referrer->id)
                        ->first();
                    if ($profile) {
                        app(ReferralService::class)->recomputeProfileCounters($profile);
                    }
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to revoke reward on conversion delete (Filament).', [
                'conversion_id' => $conversion->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
