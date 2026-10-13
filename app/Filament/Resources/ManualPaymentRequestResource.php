<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ManualPaymentRequestResource\Pages;
use App\Models\ManualPaymentRequest;
use App\Services\LocalizedPricingService;
use App\Services\ManualPaymentService;
use DomainException;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ManualPaymentRequestResource extends Resource
{
    protected static ?string $model = ManualPaymentRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Request')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('user.name')
                            ->label('User')
                            ->disabled(),
                        Forms\Components\TextInput::make('plan.name')
                            ->label('Plan')
                            ->disabled(),
                        Forms\Components\TextInput::make('payment_method')
                            ->label('Payment Method')
                            ->formatStateUsing(fn (string $state): string => app(LocalizedPricingService::class)->paymentMethodMeta($state)['label'])
                            ->disabled(),
                        Forms\Components\TextInput::make('billing_cycle')
                            ->label('Billing Cycle')
                            ->disabled(),
                        Forms\Components\TextInput::make('expected_amount_minor')
                            ->label('Expected Amount')
                            ->formatStateUsing(function (?string $state, ?ManualPaymentRequest $record): string {
                                return app(LocalizedPricingService::class)->formatAmount(
                                    (int) ($record?->expected_amount_minor ?? 0),
                                    (string) ($record?->currency ?? 'USD'),
                                );
                            })
                            ->disabled(),
                        Forms\Components\TextInput::make('status')
                            ->disabled(),
                        Forms\Components\TextInput::make('country_code')
                            ->label('Country')
                            ->disabled(),
                        Forms\Components\TextInput::make('submitted_at')
                            ->label('Submitted At')
                            ->disabled(),
                        Forms\Components\Textarea::make('reviewer_notes')
                            ->label('Reviewer Notes')
                            ->rows(4)
                            ->disabled(),
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
                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Method')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => app(LocalizedPricingService::class)->paymentMethodMeta($state)['label']),
                Tables\Columns\TextColumn::make('billing_cycle')
                    ->label('Cycle')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'one_time' ? 'one-time pack' : $state),
                Tables\Columns\TextColumn::make('expected_amount_minor')
                    ->label('Amount')
                    ->formatStateUsing(function (?string $state, ManualPaymentRequest $record): string {
                        return app(LocalizedPricingService::class)->formatAmount(
                            (int) $record->expected_amount_minor,
                            (string) $record->currency,
                        );
                    }),
                Tables\Columns\TextColumn::make('proof_path')
                    ->label('Proof')
                    ->badge()
                    ->getStateUsing(fn (ManualPaymentRequest $record): string => $record->proof_path ? 'Uploaded' : 'None')
                    ->color(fn (ManualPaymentRequest $record): string => $record->proof_path ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ManualPaymentRequest::STATUS_APPROVED => 'success',
                        ManualPaymentRequest::STATUS_REJECTED => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('submitted_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('reviewer.name')
                    ->label('Reviewed By')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        ManualPaymentRequest::STATUS_PENDING => 'Pending',
                        ManualPaymentRequest::STATUS_APPROVED => 'Approved',
                        ManualPaymentRequest::STATUS_REJECTED => 'Rejected',
                    ]),
                Tables\Filters\SelectFilter::make('payment_method')
                    ->options([
                        'instapay' => 'InstaPay',
                        'vodafone_cash' => 'Vodafone Cash',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('viewProof')
                    ->label('View Proof')
                    ->icon('heroicon-o-photo')
                    ->color('info')
                    ->visible(fn (ManualPaymentRequest $record): bool => (bool) $record->proof_path)
                    ->url(fn (ManualPaymentRequest $record): string => route('admin.manual-payments.proof', [$record->user?->email ?? 'unknown', $record->proof_token]))
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ManualPaymentRequest $record): bool => $record->status === ManualPaymentRequest::STATUS_PENDING)
                    ->form([
                        Forms\Components\Textarea::make('reviewer_notes')
                            ->label('Reviewer Notes')
                            ->rows(3),
                    ])
                    ->action(function (ManualPaymentRequest $record, array $data): void {
                        try {
                            app(ManualPaymentService::class)->approve(
                                $record,
                                auth()->user(),
                                isset($data['reviewer_notes']) ? (string) $data['reviewer_notes'] : null,
                            );

                            Notification::make()
                                ->title('Manual payment approved')
                                ->success()
                                ->send();
                        } catch (DomainException $exception) {
                            Notification::make()
                                ->title($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ManualPaymentRequest $record): bool => $record->status === ManualPaymentRequest::STATUS_PENDING)
                    ->form([
                        Forms\Components\Textarea::make('reviewer_notes')
                            ->label('Reviewer Notes')
                            ->rows(3)
                            ->required(),
                    ])
                    ->action(function (ManualPaymentRequest $record, array $data): void {
                        try {
                            app(ManualPaymentService::class)->reject(
                                $record,
                                auth()->user(),
                                (string) ($data['reviewer_notes'] ?? ''),
                            );

                            Notification::make()
                                ->title('Manual payment rejected')
                                ->success()
                                ->send();
                        } catch (DomainException $exception) {
                            Notification::make()
                                ->title($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('delete_request')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete manual payment request')
                    ->modalDescription(function (ManualPaymentRequest $record): string {
                        if ($record->status === ManualPaymentRequest::STATUS_APPROVED && $record->plan?->isPack()) {
                            return 'Deleting an approved pack payment takes back whatever the member has not used of that pack.';
                        }
                        if ($record->status === ManualPaymentRequest::STATUS_APPROVED) {
                            return 'Deleting an approved request will rollback the user plan to the previous plan snapshot, or to Free if no previous snapshot is available.';
                        }

                        return 'This action permanently deletes the request record and any uploaded proof file.';
                    })
                    ->action(function (ManualPaymentRequest $record): void {
                        $result = app(ManualPaymentService::class)->deleteRequest($record);

                        if (($result['rolled_back'] ?? false) === true) {
                            $message = $record->plan?->isPack()
                                ? 'Request deleted. The unused pack characters were taken back.'
                                : (($result['used_fallback_plan'] ?? false) === true
                                ? 'Request deleted. User plan rolled back to Free fallback.'
                                : 'Request deleted. User plan rolled back to previous plan.');

                            Notification::make()
                                ->title($message)
                                ->success()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Manual payment request deleted')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('submitted_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListManualPaymentRequests::route('/'),
        ];
    }
}
