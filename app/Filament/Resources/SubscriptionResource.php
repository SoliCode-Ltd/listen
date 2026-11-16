<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubscriptionResource\Pages\CreateSubscription;
use App\Filament\Resources\SubscriptionResource\Pages\EditSubscription;
use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Filament\Resources\SubscriptionResource\Pages\ViewSubscription;
use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\CharGift;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Billing\CharGiftService;
use App\Services\Newsletter\PaidWelcomeEmail;
use App\Support\Countries;
use App\Support\CreditPeriod;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 2;

    /**
     * Paid members only. Everyone on the free plan is in Users; their free-plan
     * row still exists (it gives them the demo) but is not listed here.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('plan', fn (Builder $plan) => $plan->where('price_cents', '>', 0))
            // How the member last paid (Billing → Payments).
            ->addSelect(['paid_with' => Payment::query()->select('method')
                ->whereColumn('payments.user_id', 'subscriptions.user_id')
                ->orderByDesc('paid_at')->orderByDesc('id')->limit(1)]);
    }

    /**
     * A paid plan given here to a member with no card subscription is kept
     * exactly as set. Any other payment state lets the billing sync take it
     * back (Stripe has no subscription for it) or keep moving its end date.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function keepHandGivenPlan(array $data, int $userId): array
    {
        $plan = Plan::query()->find((int) ($data['plan_id'] ?? 0));
        $paysByCard = BillingSubscription::query()
            ->where('user_id', $userId)
            ->whereIn('stripe_status', ['active', 'trialing', 'past_due'])
            ->exists();

        if ($plan && (int) $plan->price_cents > 0 && ! $paysByCard) {
            $data['billing_payment_state'] = 'manual_override';
        }

        return $data;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Subscription Details')
                    ->columns(2)
                    ->schema([
                        Select::make('user_id')
                            ->relationship('user', 'name')
                            ->getOptionLabelFromRecordUsing(fn (User $user): string => trim($user->name.' ('.$user->email.')'))
                            ->searchable(['name', 'email'])
                            ->preload()
                            ->required(),
                        Select::make('plan_id')
                            // A pack is not a subscription: members get packs by buying them.
                            ->relationship('plan', 'name', fn ($query) => $query->subscriptionPlans())
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('status')
                            ->options([
                                'active' => 'Active',
                                'canceled' => 'Canceled',
                                'past_due' => 'Past Due',
                                'trialing' => 'Trialing',
                                'expired' => 'Expired',
                                'incomplete' => 'Incomplete',
                            ])
                            ->required(),
                        Select::make('billing_payment_state')
                            ->options([
                                'paid' => 'Paid',
                                'pending' => 'Pending',
                                'failed' => 'Failed',
                                'grace' => 'Grace',
                                'manual_override' => 'Manual Override (Admin)',
                            ])
                            ->helperText('A paid plan given to a member who has no card subscription is always saved as Manual Override, so it stays exactly as set and ends on its end date.'),
                    ]),
                Section::make('Billing Period')
                    ->columns(2)
                    ->schema([
                        DateTimePicker::make('current_period_start'),
                        DateTimePicker::make('current_period_end'),
                        DateTimePicker::make('billing_grace_ends_at')
                            ->label('Grace Period Ends'),
                    ]),
                Section::make('Notifications & Lifecycle')
                    ->columns(2)
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        DateTimePicker::make('billing_reminder_7d_sent_at')
                            ->label('7-Day Reminder Sent'),
                        DateTimePicker::make('billing_reminder_1d_sent_at')
                            ->label('1-Day Reminder Sent'),
                        DateTimePicker::make('billing_failed_notice_sent_at')
                            ->label('Failed Notice Sent'),
                        DateTimePicker::make('billing_downgraded_at')
                            ->label('Downgraded At'),
                    ]),
                Section::make('Usage History')
                    ->schema([
                        Placeholder::make('usage_table')
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

                                $currentYm = CreditPeriod::current((int) $record->user_id)['key'];

                                $rows = $counters->map(function (UsageCounter $c) use ($currentYm): string {
                                    $isCurrent = $c->period_ym === $currentYm;
                                    $label = $isCurrent
                                        ? e($c->monthLabel()).' <span style="background:#22c55e;color:#fff;padding:1px 8px;border-radius:9999px;font-size:11px;margin-left:6px;">Current</span>'
                                        : e($c->monthLabel());
                                    $bg = $isCurrent ? 'background:rgba(34,197,94,0.06);' : '';

                                    return '<tr style="'.$bg.'">'
                                        .'<td style="padding:8px 12px;border-bottom:1px solid rgba(0,0,0,0.06);">'.$label.'</td>'
                                        .'<td style="padding:8px 12px;border-bottom:1px solid rgba(0,0,0,0.06);text-align:right;">'.number_format((float) $c->total_chars).'</td>'
                                        .'<td style="padding:8px 12px;border-bottom:1px solid rgba(0,0,0,0.06);text-align:right;">'.((int) $c->bonus_chars > 0 ? '+'.number_format((int) $c->bonus_chars) : '—').'</td>'
                                        .'<td style="padding:8px 12px;border-bottom:1px solid rgba(0,0,0,0.06);text-align:right;">'.number_format((int) $c->total_seconds / 60, 1).'</td>'
                                        .'<td style="padding:8px 12px;border-bottom:1px solid rgba(0,0,0,0.06);text-align:right;">'.number_format((int) $c->total_requests).'</td>'
                                        .'</tr>';
                                })->implode('');

                                $html = '<div style="overflow-x:auto;">'
                                    .'<table style="width:100%;border-collapse:collapse;font-size:14px;">'
                                    .'<thead><tr style="background:rgba(0,0,0,0.03);font-weight:600;">'
                                    .'<th style="padding:8px 12px;text-align:left;">Period</th>'
                                    .'<th style="padding:8px 12px;text-align:right;">Characters</th>'
                                    .'<th style="padding:8px 12px;text-align:right;">Gifted</th>'
                                    .'<th style="padding:8px 12px;text-align:right;">Minutes</th>'
                                    .'<th style="padding:8px 12px;text-align:right;">Requests</th>'
                                    .'</tr></thead>'
                                    .'<tbody>'.$rows.'</tbody>'
                                    .'</table></div>';

                                return new HtmlString($html);
                            }),
                    ]),
                Section::make('Gifts from Listen')
                    ->visibleOn('view')
                    ->schema([
                        Placeholder::make('gifts_table')
                            ->label('')
                            ->content(fn (?Subscription $record): HtmlString|string => self::giftsTable($record)),
                    ]),
            ]);
    }

    private static function giftsTable(?Subscription $record): HtmlString|string
    {
        if (! $record || ! $record->user_id) {
            return 'No subscription loaded.';
        }

        $gifts = rescue(fn () => CharGift::query()
            ->with('grantedBy:id,email')
            ->where('user_id', $record->user_id)
            ->latest('id')
            ->limit(50)
            ->get(), null, false);

        if ($gifts === null) {
            return 'Gifts are not available until the database migration has run.';
        }

        if ($gifts->isEmpty()) {
            return 'No characters gifted yet.';
        }

        $cell = 'padding:8px 12px;border-bottom:1px solid rgba(0,0,0,0.06);';
        $rows = $gifts->map(function (CharGift $gift) use ($cell): string {
            $status = $gift->isActive()
                ? ($gift->period_ym === CreditPeriod::current((int) $gift->user_id)['key'] ? 'Active this period' : 'Ended with its period')
                : 'Taken back '.$gift->revoked_at?->format('Y-m-d');

            return '<tr>'
                .'<td style="'.$cell.'">'.e($gift->created_at?->format('Y-m-d H:i')).'</td>'
                .'<td style="'.$cell.'">'.e($gift->period_ym).'</td>'
                .'<td style="'.$cell.'text-align:right;">+'.number_format($gift->chars).'</td>'
                .'<td style="'.$cell.'">'.e((string) ($gift->note ?? '—')).'</td>'
                .'<td style="'.$cell.'">'.e((string) ($gift->grantedBy?->email ?? '—')).'</td>'
                .'<td style="'.$cell.'">'.e($status).'</td>'
                .'</tr>';
        })->implode('');

        return new HtmlString('<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:14px;">'
            .'<thead><tr style="background:rgba(0,0,0,0.03);font-weight:600;">'
            .'<th style="padding:8px 12px;text-align:left;">Given</th>'
            .'<th style="padding:8px 12px;text-align:left;">Month</th>'
            .'<th style="padding:8px 12px;text-align:right;">Characters</th>'
            .'<th style="padding:8px 12px;text-align:left;">Note</th>'
            .'<th style="padding:8px 12px;text-align:left;">By</th>'
            .'<th style="padding:8px 12px;text-align:left;">Status</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table></div>');
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
                TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('user.country_code')
                    ->label('Country')
                    ->formatStateUsing(fn (?string $state) => Countries::label($state))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->state(fn (Subscription $record): string => self::displayStatus($record))
                    ->formatStateUsing(fn (string $state): string => Str::headline($state))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'trialing' => 'info',
                        'past_due' => 'warning',
                        'canceled', 'expired' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('billing_payment_state')
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
                TextColumn::make('paid_with')
                    ->label('Paid with')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Payment::methodLabel($state))
                    ->color(fn (?string $state): string => Payment::methodColor($state))
                    ->placeholder('—')
                    ->tooltip('How the member last paid')
                    ->toggleable(),
                TextColumn::make('current_period_end')
                    ->label('Period Ends')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('country')
                    ->label('Country')
                    ->options(fn () => ['__unknown' => 'Not known yet'] + Countries::optionsInUse())
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        '__unknown' => $query->whereHas('user', fn (Builder $q) => $q->whereNull('country_code')),
                        default => $query->whereHas('user', fn (Builder $q) => $q->where('country_code', $data['value'])),
                    }),
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'canceled' => 'Canceled',
                        'past_due' => 'Past Due',
                        'trialing' => 'Trialing',
                        'expired' => 'Expired',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = (string) ($data['value'] ?? '');

                        return match ($value) {
                            '' => $query,
                            'trialing' => $query->where(fn (Builder $q) => $q
                                ->where('status', 'trialing')
                                ->orWhere(fn (Builder $active) => $active
                                    ->where('status', 'active')
                                    ->whereIn('user_id', self::openTrialUserIds()))),
                            'active' => $query->where('status', 'active')->whereNotIn('user_id', self::openTrialUserIds()),
                            default => $query->where('status', $value),
                        };
                    }),
                SelectFilter::make('plan_id')
                    ->relationship('plan', 'name')
                    ->label('Plan'),
                SelectFilter::make('paid_with')
                    ->label('Has paid with')
                    ->options(Payment::METHODS)
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereIn('subscriptions.user_id', Payment::query()->select('user_id')->where('method', $data['value']))
                        : $query),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('giftChars')
                    ->label('Gift characters')
                    ->icon('heroicon-o-gift')
                    ->color('success')
                    ->modalHeading(fn (Subscription $record): string => 'Gift characters to '.($record->user?->email ?? 'this member'))
                    ->modalDescription(fn (Subscription $record): string => self::giftModalDescription($record))
                    ->schema(self::giftFormSchema())
                    ->action(fn (Subscription $record, array $data) => self::grantGift($record, $data)),
                Action::make('sendWelcome')
                    ->label('Send welcome email')
                    ->icon('heroicon-o-envelope')
                    ->color('gray')
                    ->visible(fn (Subscription $record): bool => (string) $record->status === 'active' && (int) ($record->plan?->price_cents ?? 0) > 0)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Subscription $record): string => 'Send the paid welcome email to '.($record->user?->email ?? 'this member').'?')
                    ->modalDescription(fn (Subscription $record): string => PaidWelcomeEmail::canTrack() && $record->user?->paid_welcome_sent_at
                        ? 'They were already welcomed on '.$record->user->paid_welcome_sent_at->format('Y-m-d').'. This sends it again. Edit the words in Newsletter → Paid welcome email.'
                        : 'Edit the words in Newsletter → Paid welcome email.')
                    ->action(function (Subscription $record): void {
                        try {
                            app(PaidWelcomeEmail::class)->send($record->user);
                        } catch (Throwable $e) {
                            Notification::make()->title('The email was not sent')->body($e->getMessage())->danger()->persistent()->send();

                            return;
                        }
                        Notification::make()->title('Welcome email sent to '.$record->user->email)->success()->send();
                    }),
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
            'index' => ListSubscriptions::route('/'),
            'create' => CreateSubscription::route('/create'),
            'view' => ViewSubscription::route('/{record}'),
            'edit' => EditSubscription::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public static function giftFormSchema(): array
    {
        return [
            TextInput::make('chars')
                ->label('Characters to gift')
                ->numeric()
                ->integer()
                ->required()
                ->minValue(1)
                ->maxValue(CharGiftService::MAX_GIFT)
                ->helperText('Added on top of the plan for the current billing month only ('.now()->format('F Y').'). They end with the month, like the plan\'s own characters.'),
            Textarea::make('note')
                ->label('Note (only admins see this)')
                ->rows(2)
                ->maxLength(500),
        ];
    }

    /**
     * @param  array{chars?:mixed,note?:mixed}  $data
     */
    public static function grantGift(Subscription $record, array $data): void
    {
        try {
            $gift = app(CharGiftService::class)->grant(
                $record,
                (int) ($data['chars'] ?? 0),
                isset($data['note']) ? (string) $data['note'] : null,
                auth()->user(),
            );
        } catch (Throwable $e) {
            Notification::make()
                ->title('The gift was not added')
                ->body($e instanceof InvalidArgumentException ? $e->getMessage() : 'Something went wrong; nothing was changed. If this is a new server, run the database migration first.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(number_format($gift->chars).' characters gifted')
            ->body('This month the member now has '.number_format(app(CharGiftService::class)->activeGiftChars((int) $record->user_id)).' gift characters on top of their plan.')
            ->success()
            ->send();
    }

    public static function giftModalDescription(Subscription $record): string
    {
        $current = rescue(fn () => app(CharGiftService::class)->activeGiftChars((int) $record->user_id), 0, false);
        $plan = $record->plan?->name ?? 'their plan';

        return 'Gift from Listen for '.now()->format('F Y').", on top of {$plan}."
            .($current > 0 ? ' Already gifted this month: '.number_format($current).'.' : '');
    }

    /**
     * Trial users keep status "active" so plan access works; the open trial is what marks them as trialing.
     */
    private static function openTrialUserIds(): Builder
    {
        return BillingTrial::query()
            ->select('user_id')
            ->where('status', BillingTrial::STATUS_TRIALING)
            ->whereNull('paid_at');
    }

    private static function displayStatus(Subscription $record): string
    {
        $status = (string) $record->status;
        if ($status !== 'active') {
            return $status;
        }

        $trialUsers = once(fn () => self::openTrialUserIds()->pluck('user_id')->map(fn ($id) => (int) $id)->flip()->all());

        return isset($trialUsers[(int) $record->user_id]) ? 'trialing' : $status;
    }
}
