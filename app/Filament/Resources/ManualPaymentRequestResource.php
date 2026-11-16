<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\Action;
use App\Filament\Resources\ManualPaymentRequestResource\Pages\ListManualPaymentRequests;
use App\Filament\Resources\ManualPaymentRequestResource\Pages;
use App\Models\ManualPaymentRequest;
use App\Services\LocalizedPricingService;
use App\Services\ManualPaymentService;
use DomainException;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ManualPaymentRequestResource extends Resource
{
    protected static ?string $model = ManualPaymentRequest::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-banknotes';

    protected static string | \UnitEnum | null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request')
                    ->columns(2)
                    ->schema([
                        TextInput::make('user.name')
                            ->label('User')
                            ->disabled(),
                        TextInput::make('plan.name')
                            ->label('Plan')
                            ->disabled(),
                        TextInput::make('payment_method')
                            ->label('Payment Method')
                            ->formatStateUsing(fn (string $state): string => app(LocalizedPricingService::class)->paymentMethodMeta($state)['label'])
                            ->disabled(),
                        TextInput::make('billing_cycle')
                            ->label('Billing Cycle')
                            ->disabled(),
                        TextInput::make('expected_amount_minor')
                            ->label('Expected Amount')
                            ->formatStateUsing(function (?string $state, ?ManualPaymentRequest $record): string {
                                return app(LocalizedPricingService::class)->formatAmount(
                                    (int) ($record?->expected_amount_minor ?? 0),
                                    (string) ($record?->currency ?? 'USD'),
                                );
                            })
                            ->disabled(),
                        TextInput::make('status')
                            ->disabled(),
                        TextInput::make('country_code')
                            ->label('Country')
                            ->disabled(),
                        TextInput::make('submitted_at')
                            ->label('Submitted At')
                            ->disabled(),
                        Textarea::make('reviewer_notes')
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
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('payment_method')
                    ->label('Method')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => app(LocalizedPricingService::class)->paymentMethodMeta($state)['label']),
                TextColumn::make('billing_cycle')
                    ->label('Cycle')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'one_time' ? 'one-time pack' : $state),
                TextColumn::make('expected_amount_minor')
                    ->label('Amount')
                    ->formatStateUsing(function (?string $state, ManualPaymentRequest $record): string {
                        return app(LocalizedPricingService::class)->formatAmount(
                            (int) $record->expected_amount_minor,
                            (string) $record->currency,
                        );
                    }),
                TextColumn::make('proof_path')
                    ->label('Proof')
                    ->badge()
                    ->getStateUsing(fn (ManualPaymentRequest $record): string => $record->proof_path ? 'Uploaded' : 'None')
                    ->color(fn (ManualPaymentRequest $record): string => $record->proof_path ? 'success' : 'gray'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ManualPaymentRequest::STATUS_APPROVED => 'success',
                        ManualPaymentRequest::STATUS_REJECTED => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('submitted_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('reviewer.name')
                    ->label('Reviewed By')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        ManualPaymentRequest::STATUS_PENDING => 'Pending',
                        ManualPaymentRequest::STATUS_APPROVED => 'Approved',
                        ManualPaymentRequest::STATUS_REJECTED => 'Rejected',
                    ]),
                SelectFilter::make('payment_method')
                    ->options([
                        'instapay' => 'InstaPay',
                        'vodafone_cash' => 'Vodafone Cash',
                    ]),
            ])
            ->recordActions([
                Action::make('viewProof')
                    ->label('View Proof')
                    ->icon('heroicon-o-photo')
                    ->color('info')
                    ->visible(fn (ManualPaymentRequest $record): bool => (bool) $record->proof_path)
                    ->url(fn (ManualPaymentRequest $record): string => route('admin.manual-payments.proof', [$record->user?->email ?? 'unknown', $record->proof_token]))
                    ->openUrlInNewTab(),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ManualPaymentRequest $record): bool => $record->status === ManualPaymentRequest::STATUS_PENDING)
                    ->schema([
                        Textarea::make('reviewer_notes')
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
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ManualPaymentRequest $record): bool => $record->status === ManualPaymentRequest::STATUS_PENDING)
                    ->schema([
                        Textarea::make('reviewer_notes')
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
                Action::make('delete_request')
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
            ->toolbarActions([])
            ->defaultSort('submitted_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListManualPaymentRequests::route('/'),
        ];
    }
}
