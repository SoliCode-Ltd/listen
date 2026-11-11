<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Placeholder;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\Filter;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\UserResource\RelationManagers\FeatureGrantsRelationManager;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages;
use App\Models\DemoVoiceGeneration;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\CharPackService;
use App\Services\BlockedUserService;
use App\Services\MultiAccountEnforcementService;
use App\Services\UserDeletionService;
use App\Support\Countries;
use App\Support\DemoAllowance;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-users';

    protected static string | \UnitEnum | null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('ui.admin.users.account_section'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        TextInput::make('password')
                            ->password()
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->maxLength(255),
                        Toggle::make('is_admin')
                            ->label(__('ui.admin.users.is_admin'))
                            ->helperText(__('ui.admin.users.is_admin_help')),
                    ]),
                Section::make(__('ui.admin.users.blocking_section'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_blocked')
                            ->label(__('ui.admin.users.is_blocked'))
                            ->disabled()
                            ->dehydrated(false),
                        DateTimePicker::make('blocked_at')
                            ->label(__('ui.admin.users.blocked_at'))
                            ->disabled()
                            ->dehydrated(false),
                        Textarea::make('blocked_reason')
                            ->label(__('ui.admin.users.blocked_reason'))
                            ->rows(3)
                            ->helperText(__('ui.admin.users.blocked_reason_legacy_help'))
                            ->columnSpanFull()
                            ->disabled()
                            ->dehydrated(false),
                        Textarea::make('blocked_reason_ar')
                            ->label(__('ui.admin.users.blocked_reason_ar'))
                            ->rows(3)
                            ->columnSpanFull()
                            ->disabled()
                            ->dehydrated(false),
                        Textarea::make('blocked_reason_en')
                            ->label(__('ui.admin.users.blocked_reason_en'))
                            ->rows(3)
                            ->columnSpanFull()
                            ->disabled()
                            ->dehydrated(false),
                        Toggle::make('multi_account_whitelisted')
                            ->label(__('ui.admin.users.whitelisted_label'))
                            ->helperText(__('ui.admin.users.whitelisted_help'))
                            ->disabled()
                            ->dehydrated(false),
                    ]),
                Section::make(__('ui.admin.users.ip_demo_section'))
                    ->columns(2)
                    ->schema([
                        Placeholder::make('country')
                            ->label('Country')
                            ->content(fn (?User $record) => Countries::label($record?->country_code)),
                        TextInput::make('registration_ip')
                            ->label(__('ui.admin.users.registration_ip'))
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('last_login_ip')
                            ->label(__('ui.admin.users.last_login_ip'))
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('registration_device_id')
                            ->label(__('ui.admin.suspicious.registration_device_id'))
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('last_seen_device_id')
                            ->label(__('ui.admin.suspicious.last_seen_device_id'))
                            ->disabled()
                            ->dehydrated(false),
                        Placeholder::make('demo_chars_used_display')
                            ->label(__('ui.admin.users.demo_chars_used'))
                            ->content(fn (?User $record): string => $record
                                ? number_format((int) DemoVoiceGeneration::where('user_id', $record->id)->sum('billable_chars')).' / '.number_format(DemoAllowance::chars())
                                : '0'),
                        Placeholder::make('pack_chars_display')
                            ->label('Premium pack')
                            ->content(function (?User $record): string {
                                $pack = $record ? app(CharPackService::class)->summary((int) $record->id) : null;

                                return $pack && $pack['active']
                                    ? number_format($pack['remaining']).' of '.number_format($pack['total']).' characters left, until '.$pack['expires_at']->format('j M Y')
                                    : 'None';
                            }),
                        Placeholder::make('ip_linked_accounts_display')
                            ->label(__('ui.admin.users.ip_linked_accounts'))
                            ->columnSpanFull()
                            ->content(function (?User $record): HtmlString|string {
                                if (! $record) {
                                    return '0';
                                }

                                $signals = app(MultiAccountEnforcementService::class)->linkedSignals($record);
                                if (
                                    ($signals['ips'] ?? []) === []
                                    && ($signals['device_ids'] ?? []) === []
                                ) {
                                    return __('ui.admin.users.no_ip_data');
                                }

                                $linked = self::linkedUsersQuery($record)->get([
                                    'id',
                                    'name',
                                    'email',
                                    'registration_device_id',
                                    'last_seen_device_id',
                                ]);
                                if ($linked->isEmpty()) {
                                    return __('ui.admin.users.no_linked_accounts');
                                }

                                $items = $linked->map(function (User $u): string {
                                    $url = self::getUrl('view', ['record' => $u]);
                                    $name = e((string) $u->name);
                                    $email = e((string) $u->email);
                                    $regDevice = e((string) ($u->registration_device_id ?: '-'));
                                    $lastDevice = e((string) ($u->last_seen_device_id ?: '-'));

                                    return <<<HTML
<li style="margin-bottom: 10px;">
  <a href="{$url}" style="font-weight: 600; text-decoration: underline;">{$name} ({$email})</a>
  <div style="font-size: 12px; opacity: .85; margin-top: 2px;">Device ID: {$regDevice} / {$lastDevice}</div>
</li>
HTML;
                                })->implode('');

                                return new HtmlString('<ul style="margin: 0; padding-left: 18px;">'.$items.'</ul>');
                            }),
                    ]),
                Section::make(__('ui.admin.users.oauth_section'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('phone_number')
                            ->label(__('ui.admin.users.phone_number'))
                            ->disabled()
                            ->dehydrated(false),
                        DateTimePicker::make('phone_verified_at')
                            ->label(__('ui.admin.users.phone_verified_at'))
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('firebase_uid')
                            ->label(__('ui.admin.users.firebase_uid'))
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('auth_provider')
                            ->label(__('ui.admin.users.auth_provider'))
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('google_id')
                            ->label(__('ui.admin.users.google_id'))
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('google_avatar')
                            ->label(__('ui.admin.users.google_avatar'))
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('api_key_last4')
                            ->label(__('ui.admin.users.api_key_last4'))
                            ->disabled()
                            ->dehydrated(false),
                        DateTimePicker::make('api_key_created_at')
                            ->label(__('ui.admin.users.api_key_created_at'))
                            ->disabled()
                            ->dehydrated(false),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Each row's plan and demo characters, for the whole page in two queries (not three a row).
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('latestActiveSubscription.plan')
                ->withSum('demoVoiceGenerations as demo_chars_list_sum', 'billable_chars')
                ->withExists('demoVoiceGenerations as has_demo_history')
                ->withExists('planSubscriptions as has_any_subscription'))
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('blocked_status')
                    ->label(__('ui.admin.users.status'))
                    ->badge()
                    ->getStateUsing(function (User $record): string {
                        if ($record->isBlocked()) {
                            return __('ui.admin.users.blocked');
                        }

                        if ($record->multi_account_whitelisted) {
                            return __('ui.admin.users.active').' (Whitelisted)';
                        }

                        return __('ui.admin.users.active');
                    })
                    ->color(function (User $record): string {
                        if ($record->isBlocked()) {
                            return 'danger';
                        }

                        return $record->multi_account_whitelisted ? 'info' : 'success';
                    })
                    ->sortable(query: fn ($query, string $direction) => $query->orderBy('is_blocked', $direction)),
                TextColumn::make('current_plan')
                    ->label('Plan')
                    ->badge()
                    ->getStateUsing(function (User $record): string {
                        // The newest active subscription, loaded with the page (latestActiveSubscription).
                        $subscription = $record->latestActiveSubscription;

                        if (! $subscription || ! $subscription->plan) {
                            return 'Free';
                        }

                        return $subscription->plan->price_cents > 0
                            ? $subscription->plan->name
                            : 'Free';
                    })
                    ->color(function (User $record): string {
                        $subscription = $record->latestActiveSubscription;

                        if (! $subscription || ! $subscription->plan || $subscription->plan->price_cents <= 0) {
                            return 'gray';
                        }

                        return 'warning';
                    })
                    ->toggleable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('country_code')
                    ->label('Country')
                    ->formatStateUsing(fn (?string $state) => Countries::label($state))
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('phone_number')
                    ->label(__('ui.admin.users.phone_number'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
                TextColumn::make('registration_ip')
                    ->label(__('ui.admin.users.registration_ip'))
                    ->searchable()
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('last_login_ip')
                    ->label(__('ui.admin.users.last_login_ip'))
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('demo_chars_used')
                    ->label(__('ui.admin.users.demo_chars_used'))
                    // Added up with the page (table()'s query), else for this member alone.
                    ->getStateUsing(fn (User $record) => array_key_exists('demo_chars_list_sum', $record->getAttributes())
                        ? (int) $record->getAttribute('demo_chars_list_sum')
                        : (int) DemoVoiceGeneration::where('user_id', $record->id)->sum('billable_chars'))
                    ->numeric()
                    ->sortable(query: fn ($query, string $direction) => $query
                        ->withSum('demoVoiceGenerations as demo_chars_sum', 'billable_chars')
                        ->orderBy('demo_chars_sum', $direction))
                    ->toggleable(),
                TextColumn::make('ip_linked_accounts')
                    ->label(__('ui.admin.users.ip_linked_accounts'))
                    ->getStateUsing(fn (User $record): int => self::linkedCount($record))
                    ->badge()
                    ->color(fn ($state) => ((int) $state) > 0 ? 'danger' : 'gray')
                    ->toggleable(),
                IconColumn::make('is_admin')
                    ->label(__('ui.admin.users.admin'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('auth_method_display')
                    ->label(__('ui.admin.users.auth_provider'))
                    ->badge()
                    ->getStateUsing(fn (User $record): string => match (true) {
                        $record->auth_provider === 'phone' => 'Phone',
                        $record->auth_provider === 'firebase_google' => 'Firebase Google',
                        filled($record->google_id) => 'Google',
                        default => 'Email',
                    })
                    ->color(fn (User $record): string => match (true) {
                        $record->auth_provider === 'phone' => 'info',
                        $record->auth_provider === 'firebase_google' => 'warning',
                        filled($record->google_id) => 'success',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('google_id')
                    ->label(__('ui.admin.users.google'))
                    ->badge()
                    ->getStateUsing(fn (User $record) => $record->google_id ? __('ui.admin.users.connected') : '-')
                    ->color(fn (User $record) => $record->google_id ? 'success' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('api_key_last4')
                    ->label(__('ui.admin.users.api_key'))
                    ->getStateUsing(fn (User $record) => $record->api_key_last4 ? '****'.$record->api_key_last4 : '-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('blocked_at')
                    ->label(__('ui.admin.users.blocked_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('blockedBy.name')
                    ->label(__('ui.admin.users.blocked_by'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('country_code')
                    ->label('Country')
                    ->options(fn () => ['__unknown' => 'Not known yet'] + Countries::optionsInUse())
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        '__unknown' => $query->whereNull('country_code'),
                        default => $query->where('country_code', $data['value']),
                    }),
                TernaryFilter::make('is_admin')
                    ->label(__('ui.admin.users.admin_filter')),
                TernaryFilter::make('is_blocked')
                    ->label(__('ui.admin.users.blocked_filter')),
                TernaryFilter::make('multi_account_whitelisted')
                    ->label(__('ui.admin.users.whitelisted_filter')),
                Filter::make('has_api_key')
                    ->label(__('ui.admin.users.has_api_key'))
                    ->query(fn ($query) => $query->whereNotNull('api_key_hash')),
                Filter::make('has_google')
                    ->label(__('ui.admin.users.google_connected'))
                    ->query(fn ($query) => $query->whereNotNull('google_id')),
                Filter::make('has_phone')
                    ->label(__('ui.admin.users.phone_connected'))
                    ->query(fn ($query) => $query->whereNotNull('phone_number')),
                Filter::make('paid_users')
                    ->label('Paid Users')
                    ->query(fn ($query) => $query->whereHas('planSubscriptions', fn ($q) => $q
                        ->where('status', 'active')
                        ->whereHas('plan', fn ($p) => $p->where('price_cents', '>', 0))
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                // Free members are not in Subscriptions any more, so gifts can be given from here.
                Action::make('giftChars')
                    ->label('Gift characters')
                    ->icon('heroicon-o-gift')
                    ->color('success')
                    ->visible(fn (User $record): bool => array_key_exists('has_any_subscription', $record->getAttributes())
                        ? (bool) $record->getAttribute('has_any_subscription')
                        : self::latestSubscription($record) !== null)
                    ->modalHeading(fn (User $record): string => 'Gift characters to '.$record->email)
                    ->modalDescription(fn (User $record): string => SubscriptionResource::giftModalDescription(self::latestSubscription($record)))
                    ->schema(SubscriptionResource::giftFormSchema())
                    ->action(fn (User $record, array $data) => SubscriptionResource::grantGift(self::latestSubscription($record), $data)),
                Action::make('blockUser')
                    ->label(__('ui.admin.users.block'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (User $record) => ! $record->isBlocked() && ! $record->is_admin && Auth::id() !== $record->id)
                    ->requiresConfirmation()
                    ->modalHeading(__('ui.admin.users.block_confirm_title'))
                    ->modalDescription(__('ui.admin.users.block_confirm_description'))
                    ->modalSubmitActionLabel(__('ui.admin.users.block'))
                    ->schema([
                        Textarea::make('reason_ar')
                            ->label(__('ui.admin.users.block_reason_label_ar'))
                            ->placeholder(__('ui.admin.users.block_reason_placeholder_ar'))
                            ->default(fn (): string => BlockedUserService::DEFAULT_REASON_AR)
                            ->rows(3)
                            ->maxLength(1000),
                        Textarea::make('reason_en')
                            ->label(__('ui.admin.users.block_reason_label_en'))
                            ->placeholder(__('ui.admin.users.block_reason_placeholder_en'))
                            ->default(fn (): string => BlockedUserService::DEFAULT_REASON_EN)
                            ->rows(4)
                            ->maxLength(1000),
                    ])
                    ->action(function (User $record, array $data): void {
                        $reasonAr = trim((string) ($data['reason_ar'] ?? '')) ?: null;
                        $reasonEn = trim((string) ($data['reason_en'] ?? '')) ?: null;
                        $legacyFallback = $reasonEn ?? $reasonAr;

                        app(BlockedUserService::class)->block(
                            $record,
                            $legacyFallback,
                            Auth::user(),
                            [
                                'ar' => $reasonAr,
                                'en' => $reasonEn,
                            ],
                        );

                        Notification::make()
                            ->success()
                            ->title(__('ui.admin.users.blocked_success'))
                            ->send();
                    }),
                Action::make('unblockUser')
                    ->label(__('ui.admin.users.unblock'))
                    ->icon('heroicon-o-lock-open')
                    ->color('success')
                    ->visible(fn (User $record) => $record->isBlocked())
                    ->requiresConfirmation()
                    ->modalHeading(__('ui.admin.users.unblock_confirm_title'))
                    ->modalDescription(__('ui.admin.users.unblock_confirm_description'))
                    ->modalSubmitActionLabel(__('ui.admin.users.unblock'))
                    ->action(function (User $record): void {
                        app(BlockedUserService::class)->unblock($record);

                        Notification::make()
                            ->success()
                            ->title(__('ui.admin.users.unblocked_success'))
                            ->send();
                    }),
                Action::make('resetDemoChars')
                    ->label(__('ui.admin.users.reset_demo_chars'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(__('ui.admin.users.reset_demo_confirm_title'))
                    ->modalDescription(__('ui.admin.users.reset_demo_confirm_description'))
                    ->modalSubmitActionLabel(__('ui.admin.users.reset_demo_chars'))
                    ->visible(fn (User $record) => array_key_exists('has_demo_history', $record->getAttributes())
                        ? (bool) $record->getAttribute('has_demo_history')
                        : DemoVoiceGeneration::where('user_id', $record->id)->exists())
                    ->action(function (User $record): void {
                        DemoVoiceGeneration::where('user_id', $record->id)->delete();
                        Cache::forget("demo:ip_linked_users:{$record->id}");
                        Cache::forget('admin:suspicious_users_count');

                        Notification::make()
                            ->success()
                            ->title(__('ui.admin.users.reset_demo_success'))
                            ->send();
                    }),
                Action::make('revokeApiKey')
                    ->label(__('ui.admin.users.revoke_api_key'))
                    ->icon('heroicon-o-key')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (User $record) => $record->api_key_hash !== null)
                    ->action(fn (User $record) => $record->revokeApiKey()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Members who would still be charged are kept; the notice says how many.
                    DeleteBulkAction::make()
                        ->action(function (DeleteBulkAction $action, Collection $records): void {
                            $deletion = app(UserDeletionService::class);
                            $kept = 0;
                            foreach ($records as $user) {
                                if ($deletion->delete($user, Auth::user()) !== null) {
                                    $kept++;
                                }
                            }

                            if ($kept === 0) {
                                $action->success();

                                return;
                            }

                            Notification::make()
                                ->warning()
                                ->title(__('ui.admin.users.bulk_delete_kept_title'))
                                ->body(__('ui.admin.users.bulk_delete_kept_body', [
                                    'deleted' => $records->count() - $kept,
                                    'kept' => $kept,
                                ]))
                                ->persistent()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            FeatureGrantsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    /** Counted once for each row on the page (its badge asks again for its colour); none to count without signals. */
    private static function linkedCount(User $record): int
    {
        return once(function () use ($record): int {
            $signals = app(MultiAccountEnforcementService::class)->linkedSignals($record);
            if (($signals['ips'] ?? []) === [] && ($signals['device_ids'] ?? []) === []) {
                return 0;
            }

            return self::linkedUsersQuery($record)->count();
        });
    }

    private static function linkedUsersQuery(User $record): Builder
    {
        $signals = app(MultiAccountEnforcementService::class)->linkedSignals($record);
        $ips = $signals['ips'] ?? [];
        $deviceIds = $signals['device_ids'] ?? [];

        return User::query()
            ->where('id', '!=', $record->id)
            ->where(function ($q) use ($ips, $deviceIds): void {
                $hasAnySignal = false;

                if ($ips !== []) {
                    $q->whereIn('registration_ip', $ips)
                        ->orWhereIn('last_login_ip', $ips);
                    $hasAnySignal = true;
                }

                if ($deviceIds !== []) {
                    if ($hasAnySignal) {
                        $q->orWhereIn('registration_device_id', $deviceIds)
                            ->orWhereIn('last_seen_device_id', $deviceIds);
                    } else {
                        $q->whereIn('registration_device_id', $deviceIds)
                            ->orWhereIn('last_seen_device_id', $deviceIds);
                        $hasAnySignal = true;
                    }
                }

                if (! $hasAnySignal) {
                    $q->whereRaw('1 = 0');
                }
            });
    }

    private static function latestSubscription(User $user): ?Subscription
    {
        return Subscription::query()->where('user_id', $user->id)->latest('id')->first();
    }
}
