<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReferralConversionResource\Pages\ListReferralConversions;
use App\Filament\Resources\ReferralConversionResource\Pages\ViewReferralConversion;
use App\Models\DemoRewardTransaction;
use App\Models\ReferralConversion;
use App\Models\UserReferralProfile;
use App\Services\Referral\DemoRewardLedgerService;
use App\Services\Referral\ReferralQualificationService;
use App\Services\Referral\ReferralService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReferralConversionResource extends Resource
{
    protected static ?string $model = ReferralConversion::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|\UnitEnum|null $navigationGroup = 'Referrals';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Conversions';

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Conversion')
                    ->columns(2)
                    ->schema([
                        TextInput::make('id')
                            ->disabled(),
                        Select::make('status')
                            ->options([
                                ReferralConversion::STATUS_PENDING => 'Pending',
                                ReferralConversion::STATUS_APPROVED => 'Approved',
                                ReferralConversion::STATUS_REJECTED => 'Rejected',
                                ReferralConversion::STATUS_FLAGGED => 'Flagged',
                            ])
                            ->disabled()
                            ->helperText('Use the Approve / Reject actions above to change status.'),
                        TextInput::make('referrer.email')
                            ->label('Referrer')
                            ->disabled(),
                        TextInput::make('referredUser.email')
                            ->label('Referred User')
                            ->disabled(),
                        TextInput::make('qualification_step')
                            ->disabled(),
                        TextInput::make('reward_chars')
                            ->disabled(),
                        Textarea::make('rejection_reason')
                            ->disabled()
                            ->columnSpanFull(),
                        KeyValue::make('fraud_indicators')
                            ->disabled()
                            ->columnSpanFull(),
                        Textarea::make('reviewer_notes')
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
                TextColumn::make('id')->sortable()->label('#'),
                BadgeColumn::make('status')
                    ->colors([
                        'warning' => ReferralConversion::STATUS_PENDING,
                        'success' => ReferralConversion::STATUS_APPROVED,
                        'danger' => ReferralConversion::STATUS_REJECTED,
                        'gray' => ReferralConversion::STATUS_FLAGGED,
                    ])
                    ->sortable(),
                TextColumn::make('referrer.email')
                    ->label('Referrer')
                    ->searchable()
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->referrer?->email),
                TextColumn::make('referredUser.email')
                    ->label('Referred')
                    ->searchable()
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->referredUser?->email),
                TextColumn::make('qualification_step')
                    ->label('Trigger')
                    ->toggleable(),
                TextColumn::make('reward_chars')
                    ->label('Reward')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('fraud_indicators')
                    ->label('Flags')
                    // The first three flags, as one line (Filament formats a list item by item, so this
                    // showed "—" for each flag instead of the flags).
                    ->state(fn (ReferralConversion $record): string => is_array($record->fraud_indicators) && $record->fraud_indicators !== []
                        ? implode(', ', array_slice($record->fraud_indicators, 0, 3))
                        : '—')
                    ->toggleable(),
                TextColumn::make('qualified_at')
                    ->label('Qualified')
                    ->dateTime()
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('approved_at')
                    ->label('Approved')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        ReferralConversion::STATUS_PENDING => 'Pending',
                        ReferralConversion::STATUS_APPROVED => 'Approved',
                        ReferralConversion::STATUS_REJECTED => 'Rejected',
                        ReferralConversion::STATUS_FLAGGED => 'Flagged',
                    ])
                    ->multiple(),
                SelectFilter::make('qualification_step')
                    ->options([
                        'paid_plan' => 'Paid Plan',
                        'signup' => 'Signup',
                        'email_verified' => 'Email Verified',
                        'first_demo_generation' => 'First Demo',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (ReferralConversion $record) => $record->status !== ReferralConversion::STATUS_APPROVED)
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('notes')
                            ->label('Reviewer notes (optional)')
                            ->rows(3),
                    ])
                    ->action(function (ReferralConversion $record, array $data) {
                        $service = app(ReferralQualificationService::class);
                        $reviewer = auth()->user();
                        if (! $reviewer) {
                            return;
                        }
                        $service->manuallyApprove($record, $reviewer, $data['notes'] ?? null);
                        Notification::make()
                            ->title('Conversion approved and reward granted.')
                            ->success()
                            ->send();
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ReferralConversion $record) => $record->status !== ReferralConversion::STATUS_REJECTED)
                    ->requiresConfirmation()
                    ->schema([
                        TextInput::make('reason')
                            ->label('Rejection reason')
                            ->maxLength(120)
                            ->required(),
                        Textarea::make('notes')
                            ->label('Reviewer notes (optional)')
                            ->rows(3),
                    ])
                    ->action(function (ReferralConversion $record, array $data) {
                        $service = app(ReferralQualificationService::class);
                        $reviewer = auth()->user();
                        if (! $reviewer) {
                            return;
                        }
                        $service->manuallyReject($record, $reviewer, $data['notes'] ?? null, $data['reason'] ?? null);
                        Notification::make()
                            ->title('Conversion rejected.')
                            ->danger()
                            ->send();
                    }),
                DeleteAction::make()
                    ->modalHeading('Delete conversion & revoke reward')
                    ->modalDescription('Any demo reward characters granted from this conversion will be revoked from the referrer.')
                    ->before(function (ReferralConversion $record) {
                        self::revokeRewardForConversion($record);
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
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
            'index' => ListReferralConversions::route('/'),
            'view' => ViewReferralConversion::route('/{record}'),
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
        } catch (Throwable $e) {
            Log::warning('Failed to revoke reward on conversion delete (Filament).', [
                'conversion_id' => $conversion->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
