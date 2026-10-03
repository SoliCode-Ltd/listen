<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\DemoVoiceGeneration;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BlockedUserService;
use App\Services\MultiAccountEnforcementService;
use App\Support\Countries;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('ui.admin.users.account_section'))
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->maxLength(255),
                        Forms\Components\Toggle::make('is_admin')
                            ->label(__('ui.admin.users.is_admin'))
                            ->helperText(__('ui.admin.users.is_admin_help')),
                    ]),
                Forms\Components\Section::make(__('ui.admin.users.blocking_section'))
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('is_blocked')
                            ->label(__('ui.admin.users.is_blocked'))
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\DateTimePicker::make('blocked_at')
                            ->label(__('ui.admin.users.blocked_at'))
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\Textarea::make('blocked_reason')
                            ->label(__('ui.admin.users.blocked_reason'))
                            ->rows(3)
                            ->helperText(__('ui.admin.users.blocked_reason_legacy_help'))
                            ->columnSpanFull()
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\Textarea::make('blocked_reason_ar')
                            ->label(__('ui.admin.users.blocked_reason_ar'))
                            ->rows(3)
                            ->columnSpanFull()
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\Textarea::make('blocked_reason_en')
                            ->label(__('ui.admin.users.blocked_reason_en'))
                            ->rows(3)
                            ->columnSpanFull()
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\Toggle::make('multi_account_whitelisted')
                            ->label(__('ui.admin.users.whitelisted_label'))
                            ->helperText(__('ui.admin.users.whitelisted_help'))
                            ->disabled()
                            ->dehydrated(false),
                    ]),
                Forms\Components\Section::make(__('ui.admin.users.ip_demo_section'))
                    ->columns(2)
                    ->schema([
                        Forms\Components\Placeholder::make('country')
                            ->label('Country')
                            ->content(fn (?User $record) => Countries::label($record?->country_code)),
                        Forms\Components\TextInput::make('registration_ip')
                            ->label(__('ui.admin.users.registration_ip'))
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('last_login_ip')
                            ->label(__('ui.admin.users.last_login_ip'))
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('registration_device_id')
                            ->label(__('ui.admin.suspicious.registration_device_id'))
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('last_seen_device_id')
                            ->label(__('ui.admin.suspicious.last_seen_device_id'))
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\Placeholder::make('demo_chars_used_display')
                            ->label(__('ui.admin.users.demo_chars_used'))
                            ->content(fn (?User $record): string => $record
                                ? number_format((int) DemoVoiceGeneration::where('user_id', $record->id)->sum('billable_chars')).' / '.number_format((int) config('listen.demo.guest_char_allowance', 300))
                                : '0'),
                        Forms\Components\Placeholder::make('ip_linked_accounts_display')
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
                Forms\Components\Section::make(__('ui.admin.users.oauth_section'))
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('phone_number')
                            ->label(__('ui.admin.users.phone_number'))
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\DateTimePicker::make('phone_verified_at')
                            ->label(__('ui.admin.users.phone_verified_at'))
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('firebase_uid')
                            ->label(__('ui.admin.users.firebase_uid'))
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('auth_provider')
                            ->label(__('ui.admin.users.auth_provider'))
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('google_id')
                            ->label(__('ui.admin.users.google_id'))
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('google_avatar')
                            ->label(__('ui.admin.users.google_avatar'))
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('api_key_last4')
                            ->label(__('ui.admin.users.api_key_last4'))
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\DateTimePicker::make('api_key_created_at')
                            ->label(__('ui.admin.users.api_key_created_at'))
                            ->disabled()
                            ->dehydrated(false),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('blocked_status')
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
                Tables\Columns\TextColumn::make('current_plan')
                    ->label('Plan')
                    ->badge()
                    ->getStateUsing(function (User $record): string {
                        $subscription = Subscription::where('user_id', $record->id)
                            ->where('status', 'active')
                            ->with('plan')
                            ->latest('id')
                            ->first();

                        if (! $subscription || ! $subscription->plan) {
                            return 'Free';
                        }

                        return $subscription->plan->price_cents > 0
                            ? $subscription->plan->name
                            : 'Free';
                    })
                    ->color(function (User $record): string {
                        $subscription = Subscription::where('user_id', $record->id)
                            ->where('status', 'active')
                            ->with('plan')
                            ->latest('id')
                            ->first();

                        if (! $subscription || ! $subscription->plan || $subscription->plan->price_cents <= 0) {
                            return 'gray';
                        }

                        return 'warning';
                    })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('country_code')
                    ->label('Country')
                    ->formatStateUsing(fn (?string $state) => Countries::label($state))
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('phone_number')
                    ->label(__('ui.admin.users.phone_number'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
                Tables\Columns\TextColumn::make('registration_ip')
                    ->label(__('ui.admin.users.registration_ip'))
                    ->searchable()
                    ->copyable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_login_ip')
                    ->label(__('ui.admin.users.last_login_ip'))
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('demo_chars_used')
                    ->label(__('ui.admin.users.demo_chars_used'))
                    ->getStateUsing(fn (User $record) => (int) DemoVoiceGeneration::where('user_id', $record->id)->sum('billable_chars'))
                    ->numeric()
                    ->sortable(query: fn ($query, string $direction) => $query
                        ->withSum('demoVoiceGenerations as demo_chars_sum', 'billable_chars')
                        ->orderBy('demo_chars_sum', $direction))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('ip_linked_accounts')
                    ->label(__('ui.admin.users.ip_linked_accounts'))
                    ->getStateUsing(function (User $record): int {
                        return self::linkedUsersQuery($record)->count();
                    })
                    ->badge()
                    ->color(fn ($state) => ((int) $state) > 0 ? 'danger' : 'gray')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_admin')
                    ->label(__('ui.admin.users.admin'))
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('auth_method_display')
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
                Tables\Columns\TextColumn::make('google_id')
                    ->label(__('ui.admin.users.google'))
                    ->badge()
                    ->getStateUsing(fn (User $record) => $record->google_id ? __('ui.admin.users.connected') : '-')
                    ->color(fn (User $record) => $record->google_id ? 'success' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('api_key_last4')
                    ->label(__('ui.admin.users.api_key'))
                    ->getStateUsing(fn (User $record) => $record->api_key_last4 ? '****'.$record->api_key_last4 : '-')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('blocked_at')
                    ->label(__('ui.admin.users.blocked_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('blockedBy.name')
                    ->label(__('ui.admin.users.blocked_by'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('country_code')
                    ->label('Country')
                    ->options(fn () => ['__unknown' => 'Not known yet'] + Countries::optionsInUse())
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        '__unknown' => $query->whereNull('country_code'),
                        default => $query->where('country_code', $data['value']),
                    }),
                Tables\Filters\TernaryFilter::make('is_admin')
                    ->label(__('ui.admin.users.admin_filter')),
                Tables\Filters\TernaryFilter::make('is_blocked')
                    ->label(__('ui.admin.users.blocked_filter')),
                Tables\Filters\TernaryFilter::make('multi_account_whitelisted')
                    ->label(__('ui.admin.users.whitelisted_filter')),
                Tables\Filters\Filter::make('has_api_key')
                    ->label(__('ui.admin.users.has_api_key'))
                    ->query(fn ($query) => $query->whereNotNull('api_key_hash')),
                Tables\Filters\Filter::make('has_google')
                    ->label(__('ui.admin.users.google_connected'))
                    ->query(fn ($query) => $query->whereNotNull('google_id')),
                Tables\Filters\Filter::make('has_phone')
                    ->label(__('ui.admin.users.phone_connected'))
                    ->query(fn ($query) => $query->whereNotNull('phone_number')),
                Tables\Filters\Filter::make('paid_users')
                    ->label('Paid Users')
                    ->query(fn ($query) => $query->whereHas('planSubscriptions', fn ($q) => $q
                        ->where('status', 'active')
                        ->whereHas('plan', fn ($p) => $p->where('price_cents', '>', 0))
                    )),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('blockUser')
                    ->label(__('ui.admin.users.block'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (User $record) => ! $record->isBlocked() && ! $record->is_admin && Auth::id() !== $record->id)
                    ->requiresConfirmation()
                    ->modalHeading(__('ui.admin.users.block_confirm_title'))
                    ->modalDescription(__('ui.admin.users.block_confirm_description'))
                    ->modalSubmitActionLabel(__('ui.admin.users.block'))
                    ->form([
                        Forms\Components\Textarea::make('reason_ar')
                            ->label(__('ui.admin.users.block_reason_label_ar'))
                            ->placeholder(__('ui.admin.users.block_reason_placeholder_ar'))
                            ->default(fn (): string => BlockedUserService::DEFAULT_REASON_AR)
                            ->rows(3)
                            ->maxLength(1000),
                        Forms\Components\Textarea::make('reason_en')
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
                Tables\Actions\Action::make('unblockUser')
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
                Tables\Actions\Action::make('resetDemoChars')
                    ->label(__('ui.admin.users.reset_demo_chars'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(__('ui.admin.users.reset_demo_confirm_title'))
                    ->modalDescription(__('ui.admin.users.reset_demo_confirm_description'))
                    ->modalSubmitActionLabel(__('ui.admin.users.reset_demo_chars'))
                    ->visible(fn (User $record) => DemoVoiceGeneration::where('user_id', $record->id)->exists())
                    ->action(function (User $record): void {
                        DemoVoiceGeneration::where('user_id', $record->id)->delete();
                        Cache::forget("demo:ip_linked_users:{$record->id}");
                        Cache::forget('admin:suspicious_users_count');

                        Notification::make()
                            ->success()
                            ->title(__('ui.admin.users.reset_demo_success'))
                            ->send();
                    }),
                Tables\Actions\Action::make('revokeApiKey')
                    ->label(__('ui.admin.users.revoke_api_key'))
                    ->icon('heroicon-o-key')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (User $record) => $record->api_key_hash !== null)
                    ->action(fn (User $record) => $record->revokeApiKey()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
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
}
