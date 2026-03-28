<?php

namespace App\Filament\Pages;

use App\Models\DemoVoiceGeneration;
use App\Models\User;
use App\Services\BlockedUserService;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SuspiciousUsers extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationGroup = 'Demo';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.suspicious-users';

    /**
     * @var array{
     *   ips: array<string, true>,
     *   device_ids: array<string, true>,
     *   request_signatures: array<string, true>
     * }|null
     */
    private ?array $sharedSignalSets = null;

    public static function getNavigationLabel(): string
    {
        return __('ui.admin.suspicious.navigation_label');
    }

    public function getTitle(): string
    {
        return __('ui.admin.suspicious.title');
    }

    public function getSubheading(): ?string
    {
        return __('ui.admin.suspicious.subheading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getSuspiciousUsersQuery())
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ui.admin.suspicious.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('ui.admin.suspicious.email'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('phone_number')
                    ->label(__('ui.admin.suspicious.phone'))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('auth_method_display')
                    ->label(__('ui.admin.suspicious.auth_method'))
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
                    }),
                Tables\Columns\TextColumn::make('registration_ip')
                    ->label(__('ui.admin.suspicious.registration_ip'))
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('last_login_ip')
                    ->label(__('ui.admin.suspicious.last_login_ip'))
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('suspicion_signals')
                    ->label(__('ui.admin.suspicious.suspicion_signals'))
                    ->badge()
                    ->getStateUsing(fn (User $record): string => $this->describeSuspicionSignals($record))
                    ->color(function (User $record): string {
                        $signals = $this->describeSuspicionSignals($record);

                        return str_contains($signals, 'Device') || str_contains($signals, 'Signature')
                            ? 'danger'
                            : 'warning';
                    }),
                Tables\Columns\TextColumn::make('registration_device_id')
                    ->label(__('ui.admin.suspicious.registration_device_id'))
                    ->searchable()
                    ->copyable()
                    ->placeholder('-')
                    ->visible(fn () => $this->hasDeviceColumns())
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_seen_device_id')
                    ->label(__('ui.admin.suspicious.last_seen_device_id'))
                    ->searchable()
                    ->copyable()
                    ->placeholder('-')
                    ->visible(fn () => $this->hasDeviceColumns())
                    ->toggleable(),
                Tables\Columns\TextColumn::make('registration_request_signature')
                    ->label(__('ui.admin.suspicious.registration_request_signature'))
                    ->searchable()
                    ->copyable()
                    ->placeholder('-')
                    ->visible(fn () => $this->hasRequestSignatureColumns())
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_seen_request_signature')
                    ->label(__('ui.admin.suspicious.last_seen_request_signature'))
                    ->searchable()
                    ->copyable()
                    ->placeholder('-')
                    ->visible(fn () => $this->hasRequestSignatureColumns())
                    ->toggleable(),
                Tables\Columns\TextColumn::make('linked_count')
                    ->label(__('ui.admin.suspicious.linked_accounts'))
                    ->badge()
                    ->color(fn ($state): string => match (true) {
                        ((int) $state) >= 5 => 'danger',
                        ((int) $state) >= 2 => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('demo_chars_used')
                    ->label(__('ui.admin.suspicious.demo_chars'))
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('ip_group_total_chars')
                    ->label(__('ui.admin.suspicious.group_total_chars'))
                    ->numeric()
                    ->badge()
                    ->color(fn ($state): string => ((int) $state) >= (int) config('listen.demo.guest_char_allowance', 300) ? 'danger' : 'success')
                    ->sortable(),
                Tables\Columns\TextColumn::make('blocked_status')
                    ->label(__('ui.admin.suspicious.status'))
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
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ui.admin.suspicious.registered_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('min_linked')
                    ->label(__('ui.admin.suspicious.min_linked_filter'))
                    ->options([
                        '1' => '1+',
                        '2' => '2+',
                        '5' => '5+',
                        '10' => '10+',
                    ])
                    ->query(fn (Builder $query, array $data) => $data['value']
                        ? $query->having('linked_count', '>=', (int) $data['value'])
                        : $query),
                Tables\Filters\Filter::make('has_demo_usage')
                    ->label(__('ui.admin.suspicious.has_demo_usage'))
                    ->query(fn (Builder $query) => $query->having('demo_chars_used', '>', 0)),
                Tables\Filters\Filter::make('exceeded_limit')
                    ->label(__('ui.admin.suspicious.exceeded_limit'))
                    ->query(fn (Builder $query) => $query->having(
                        'ip_group_total_chars',
                        '>=',
                        (int) config('listen.demo.guest_char_allowance', 300)
                    )),
                Tables\Filters\TernaryFilter::make('multi_account_whitelisted')
                    ->label(__('ui.admin.suspicious.whitelisted_filter'))
                    ->queries(
                        true: fn (Builder $query) => $query->where('users.multi_account_whitelisted', true),
                        false: fn (Builder $query) => $query->where(fn ($q) => $q->where('users.multi_account_whitelisted', false)->orWhereNull('users.multi_account_whitelisted')),
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('deleteUser')
                    ->label(__('ui.admin.suspicious.delete_user'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (User $record) => ! $record->is_admin && auth()->id() !== $record->id)
                    ->requiresConfirmation()
                    ->modalHeading(__('ui.admin.suspicious.delete_confirm_title'))
                    ->modalDescription(__('ui.admin.suspicious.delete_confirm_description'))
                    ->action(function (User $record): void {
                        $userId = $record->id;

                        DemoVoiceGeneration::where('user_id', $userId)->delete();

                        if (Schema::hasTable(config('session.table', 'sessions'))) {
                            DB::table(config('session.table', 'sessions'))
                                ->where('user_id', $userId)
                                ->delete();
                        }

                        $record->delete();

                        Cache::forget("demo:ip_linked_users:{$userId}");
                        Cache::forget('admin:suspicious_users_count');

                        Notification::make()
                            ->success()
                            ->title(__('ui.admin.suspicious.delete_success'))
                            ->send();
                    }),
                Tables\Actions\Action::make('blockUser')
                    ->label(__('ui.admin.users.block'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (User $record) => ! $record->isBlocked() && ! $record->is_admin)
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        $enforcement = app(\App\Services\MultiAccountEnforcementService::class);
                        $reasonEn = $enforcement->blockReasonEn();
                        $reasonAr = $enforcement->blockReasonAr();

                        app(BlockedUserService::class)->block(
                            $record,
                            $reasonEn,
                            auth()->user(),
                            [
                                'en' => $reasonEn,
                                'ar' => $reasonAr,
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
                    ->action(function (User $record): void {
                        app(BlockedUserService::class)->unblock($record);

                        Notification::make()
                            ->success()
                            ->title(__('ui.admin.users.unblocked_success'))
                            ->send();
                    }),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('viewUser')
                        ->label(__('ui.admin.suspicious.view_user'))
                        ->icon('heroicon-o-eye')
                        ->url(fn (User $record): string => \App\Filament\Resources\UserResource::getUrl('view', ['record' => $record]))
                        ->openUrlInNewTab(),
                    Tables\Actions\Action::make('viewLinked')
                        ->label(__('ui.admin.suspicious.view_linked'))
                        ->icon('heroicon-o-link')
                        ->color('warning')
                        ->modalHeading(fn (User $record): string => __('ui.admin.suspicious.linked_modal_title', ['name' => $record->name]))
                        ->modalContent(fn (User $record) => view('filament.pages.partials.linked-users-modal', [
                            'users' => $this->getLinkedUsers($record),
                            'sourceUser' => $record,
                            'charAllowance' => (int) config('listen.demo.guest_char_allowance', 300),
                        ]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('ui.admin.suspicious.close')),
                    Tables\Actions\Action::make('resetDemoChars')
                        ->label(__('ui.admin.users.reset_demo_chars'))
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading(__('ui.admin.users.reset_demo_confirm_title'))
                        ->modalDescription(__('ui.admin.users.reset_demo_confirm_description'))
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
                ]),
            ])
            ->defaultSort('linked_count', 'desc')
            ->striped()
            ->paginated([10, 25, 50]);
    }

    protected function getSuspiciousUsersQuery(): Builder
    {
        // Find all IPs that are shared by more than one user.
        $sharedIps = $this->getSharedIps();
        $sharedDeviceIds = $this->getSharedDeviceIds();
        $sharedRequestSignatures = $this->getSharedRequestSignatures();

        if ($sharedIps->isEmpty() && $sharedDeviceIds->isEmpty() && $sharedRequestSignatures->isEmpty()) {
            // Return an impossible condition so the table is empty.
            return User::query()
                ->selectRaw('users.*, 0 as linked_count, 0 as demo_chars_used, 0 as ip_group_total_chars')
                ->whereRaw('1 = 0');
        }

        $hasDeviceColumns = $this->hasDeviceColumns();
        $hasRequestSignatureColumns = $this->hasRequestSignatureColumns();
        $allSharedIps = $sharedIps->toArray();
        $allSharedDeviceIds = $sharedDeviceIds->toArray();
        $allSharedRequestSignatures = $sharedRequestSignatures->toArray();

        return User::query()
            ->select('users.*')
            ->selectSub(
                // Count of other users sharing any IP with this user
                User::query()
                    ->selectRaw('COUNT(DISTINCT u2.id)')
                    ->from('users as u2')
                    ->whereColumn('u2.id', '!=', 'users.id')
                    ->where(function ($q) use ($hasDeviceColumns, $hasRequestSignatureColumns) {
                        $q->whereRaw('u2.registration_ip = users.registration_ip AND users.registration_ip IS NOT NULL')
                          ->orWhereRaw('u2.registration_ip = users.last_login_ip AND users.last_login_ip IS NOT NULL')
                          ->orWhereRaw('u2.last_login_ip = users.registration_ip AND users.registration_ip IS NOT NULL')
                          ->orWhereRaw('u2.last_login_ip = users.last_login_ip AND users.last_login_ip IS NOT NULL');

                        if ($hasDeviceColumns) {
                            $q->orWhereRaw('u2.registration_device_id = users.registration_device_id AND users.registration_device_id IS NOT NULL')
                                ->orWhereRaw('u2.registration_device_id = users.last_seen_device_id AND users.last_seen_device_id IS NOT NULL')
                                ->orWhereRaw('u2.last_seen_device_id = users.registration_device_id AND users.registration_device_id IS NOT NULL')
                                ->orWhereRaw('u2.last_seen_device_id = users.last_seen_device_id AND users.last_seen_device_id IS NOT NULL');
                        }

                        if ($hasRequestSignatureColumns) {
                            $q->orWhereRaw('u2.registration_request_signature = users.registration_request_signature AND users.registration_request_signature IS NOT NULL')
                                ->orWhereRaw('u2.registration_request_signature = users.last_seen_request_signature AND users.last_seen_request_signature IS NOT NULL')
                                ->orWhereRaw('u2.last_seen_request_signature = users.registration_request_signature AND users.registration_request_signature IS NOT NULL')
                                ->orWhereRaw('u2.last_seen_request_signature = users.last_seen_request_signature AND users.last_seen_request_signature IS NOT NULL');
                        }
                    }),
                'linked_count'
            )
            ->selectSub(
                DemoVoiceGeneration::query()
                    ->selectRaw('COALESCE(SUM(billable_chars), 0)')
                    ->whereColumn('user_id', 'users.id'),
                'demo_chars_used'
            )
            ->selectSub(
                // Total demo chars used by all users sharing any IP with this user (including self)
                DemoVoiceGeneration::query()
                    ->selectRaw('COALESCE(SUM(billable_chars), 0)')
                    ->whereIn('user_id', function ($sub) use ($hasDeviceColumns, $hasRequestSignatureColumns) {
                        $sub->select('u3.id')
                            ->from('users as u3')
                            ->where(function ($q) use ($hasDeviceColumns, $hasRequestSignatureColumns) {
                                $q->whereRaw('u3.registration_ip = users.registration_ip AND users.registration_ip IS NOT NULL')
                                  ->orWhereRaw('u3.registration_ip = users.last_login_ip AND users.last_login_ip IS NOT NULL')
                                  ->orWhereRaw('u3.last_login_ip = users.registration_ip AND users.registration_ip IS NOT NULL')
                                  ->orWhereRaw('u3.last_login_ip = users.last_login_ip AND users.last_login_ip IS NOT NULL');

                                if ($hasDeviceColumns) {
                                    $q->orWhereRaw('u3.registration_device_id = users.registration_device_id AND users.registration_device_id IS NOT NULL')
                                        ->orWhereRaw('u3.registration_device_id = users.last_seen_device_id AND users.last_seen_device_id IS NOT NULL')
                                        ->orWhereRaw('u3.last_seen_device_id = users.registration_device_id AND users.registration_device_id IS NOT NULL')
                                        ->orWhereRaw('u3.last_seen_device_id = users.last_seen_device_id AND users.last_seen_device_id IS NOT NULL');
                                }

                                if ($hasRequestSignatureColumns) {
                                    $q->orWhereRaw('u3.registration_request_signature = users.registration_request_signature AND users.registration_request_signature IS NOT NULL')
                                        ->orWhereRaw('u3.registration_request_signature = users.last_seen_request_signature AND users.last_seen_request_signature IS NOT NULL')
                                        ->orWhereRaw('u3.last_seen_request_signature = users.registration_request_signature AND users.registration_request_signature IS NOT NULL')
                                        ->orWhereRaw('u3.last_seen_request_signature = users.last_seen_request_signature AND users.last_seen_request_signature IS NOT NULL');
                                }
                            })
                            ->orWhereColumn('u3.id', 'users.id');
                    }),
                'ip_group_total_chars'
            )
            ->where(function ($q) use ($allSharedIps, $allSharedDeviceIds, $allSharedRequestSignatures, $hasDeviceColumns, $hasRequestSignatureColumns) {
                $q->whereIn('users.registration_ip', $allSharedIps)
                  ->orWhereIn('users.last_login_ip', $allSharedIps);

                if ($hasDeviceColumns) {
                    $q->orWhereIn('users.registration_device_id', $allSharedDeviceIds)
                        ->orWhereIn('users.last_seen_device_id', $allSharedDeviceIds);
                }

                if ($hasRequestSignatureColumns) {
                    $q->orWhereIn('users.registration_request_signature', $allSharedRequestSignatures)
                        ->orWhereIn('users.last_seen_request_signature', $allSharedRequestSignatures);
                }
            });
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    protected function getSharedIps(): \Illuminate\Support\Collection
    {
        $regIps = DB::table('users')
            ->select('registration_ip as ip')
            ->whereNotNull('registration_ip')
            ->groupBy('registration_ip')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('ip');

        $loginIps = DB::table('users')
            ->select('last_login_ip as ip')
            ->whereNotNull('last_login_ip')
            ->groupBy('last_login_ip')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('ip');

        // Also find cross-column matches: reg_ip of one user = login_ip of another
        $crossIps = DB::table('users as u1')
            ->join('users as u2', function ($join) {
                $join->on('u1.registration_ip', '=', 'u2.last_login_ip')
                     ->whereColumn('u1.id', '!=', 'u2.id');
            })
            ->whereNotNull('u1.registration_ip')
            ->distinct()
            ->pluck('u1.registration_ip');

        return $regIps->merge($loginIps)->merge($crossIps)->unique()->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    protected function getSharedDeviceIds(): \Illuminate\Support\Collection
    {
        if (! $this->hasDeviceColumns()) {
            return collect();
        }

        $regDeviceIds = DB::table('users')
            ->select('registration_device_id as device_id')
            ->whereNotNull('registration_device_id')
            ->groupBy('registration_device_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('device_id');

        $seenDeviceIds = DB::table('users')
            ->select('last_seen_device_id as device_id')
            ->whereNotNull('last_seen_device_id')
            ->groupBy('last_seen_device_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('device_id');

        $crossRegToSeen = DB::table('users as u1')
            ->join('users as u2', function ($join) {
                $join->on('u1.registration_device_id', '=', 'u2.last_seen_device_id')
                    ->whereColumn('u1.id', '!=', 'u2.id');
            })
            ->whereNotNull('u1.registration_device_id')
            ->distinct()
            ->pluck('u1.registration_device_id');

        $crossSeenToReg = DB::table('users as u1')
            ->join('users as u2', function ($join) {
                $join->on('u1.last_seen_device_id', '=', 'u2.registration_device_id')
                    ->whereColumn('u1.id', '!=', 'u2.id');
            })
            ->whereNotNull('u1.last_seen_device_id')
            ->distinct()
            ->pluck('u1.last_seen_device_id');

        return $regDeviceIds
            ->merge($seenDeviceIds)
            ->merge($crossRegToSeen)
            ->merge($crossSeenToReg)
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    protected function getSharedRequestSignatures(): \Illuminate\Support\Collection
    {
        if (! $this->hasRequestSignatureColumns()) {
            return collect();
        }

        $regSignatures = DB::table('users')
            ->select('registration_request_signature as request_signature')
            ->whereNotNull('registration_request_signature')
            ->groupBy('registration_request_signature')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('request_signature');

        $seenSignatures = DB::table('users')
            ->select('last_seen_request_signature as request_signature')
            ->whereNotNull('last_seen_request_signature')
            ->groupBy('last_seen_request_signature')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('request_signature');

        $crossRegToSeen = DB::table('users as u1')
            ->join('users as u2', function ($join) {
                $join->on('u1.registration_request_signature', '=', 'u2.last_seen_request_signature')
                    ->whereColumn('u1.id', '!=', 'u2.id');
            })
            ->whereNotNull('u1.registration_request_signature')
            ->distinct()
            ->pluck('u1.registration_request_signature');

        $crossSeenToReg = DB::table('users as u1')
            ->join('users as u2', function ($join) {
                $join->on('u1.last_seen_request_signature', '=', 'u2.registration_request_signature')
                    ->whereColumn('u1.id', '!=', 'u2.id');
            })
            ->whereNotNull('u1.last_seen_request_signature')
            ->distinct()
            ->pluck('u1.last_seen_request_signature');

        return $regSignatures
            ->merge($seenSignatures)
            ->merge($crossRegToSeen)
            ->merge($crossSeenToReg)
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    protected function getLinkedUsers(User $user): \Illuminate\Support\Collection
    {
        $ips = collect([$user->registration_ip, $user->last_login_ip])
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $deviceIds = collect([$user->registration_device_id, $user->last_seen_device_id])
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $requestSignatures = collect([$user->registration_request_signature, $user->last_seen_request_signature])
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if ($ips === [] && $deviceIds === [] && $requestSignatures === []) {
            return collect();
        }

        return User::query()
            ->where(function ($q) use ($ips, $deviceIds, $requestSignatures) {
                if ($ips !== []) {
                    $q->whereIn('registration_ip', $ips)
                        ->orWhereIn('last_login_ip', $ips);
                }

                if ($this->hasDeviceColumns() && $deviceIds !== []) {
                    $q->orWhereIn('registration_device_id', $deviceIds)
                        ->orWhereIn('last_seen_device_id', $deviceIds);
                }

                if ($this->hasRequestSignatureColumns() && $requestSignatures !== []) {
                    $q->orWhereIn('registration_request_signature', $requestSignatures)
                        ->orWhereIn('last_seen_request_signature', $requestSignatures);
                }
            })
            ->get()
            ->map(function (User $u) {
                $u->setAttribute('demo_chars', (int) DemoVoiceGeneration::where('user_id', $u->id)->sum('billable_chars'));

                return $u;
            });
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Cache::remember('admin:suspicious_users_count', 300, function () {
            $sharedIps = static::collectSharedIpsForBadge();
            $sharedDeviceIds = static::collectSharedDeviceIdsForBadge();
            $sharedRequestSignatures = static::collectSharedRequestSignaturesForBadge();
            $hasDeviceColumns = static::hasDeviceColumnsStatic();
            $hasRequestSignatureColumns = static::hasRequestSignatureColumnsStatic();

            if ($sharedIps->isEmpty() && $sharedDeviceIds->isEmpty() && $sharedRequestSignatures->isEmpty()) {
                return 0;
            }

            return User::query()
                ->where(function ($query) use ($sharedIps, $sharedDeviceIds, $sharedRequestSignatures, $hasDeviceColumns, $hasRequestSignatureColumns) {
                    $query->whereIn('registration_ip', $sharedIps)
                        ->orWhereIn('last_login_ip', $sharedIps);

                    if ($hasDeviceColumns) {
                        $query->orWhereIn('registration_device_id', $sharedDeviceIds)
                            ->orWhereIn('last_seen_device_id', $sharedDeviceIds);
                    }

                    if ($hasRequestSignatureColumns) {
                        $query->orWhereIn('registration_request_signature', $sharedRequestSignatures)
                            ->orWhereIn('last_seen_request_signature', $sharedRequestSignatures);
                    }
                })
                ->count();
        });

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    protected static function collectSharedIpsForBadge(): \Illuminate\Support\Collection
    {
        $sharedRegistrationIps = DB::table('users')
            ->select('registration_ip as ip')
            ->whereNotNull('registration_ip')
            ->groupBy('registration_ip')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('ip');

        $sharedLoginIps = DB::table('users')
            ->select('last_login_ip as ip')
            ->whereNotNull('last_login_ip')
            ->groupBy('last_login_ip')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('ip');

        $crossRegToLoginIps = DB::table('users as u1')
            ->join('users as u2', function ($join) {
                $join->on('u1.registration_ip', '=', 'u2.last_login_ip')
                    ->whereColumn('u1.id', '!=', 'u2.id');
            })
            ->whereNotNull('u1.registration_ip')
            ->distinct()
            ->pluck('u1.registration_ip');

        $crossLoginToRegIps = DB::table('users as u1')
            ->join('users as u2', function ($join) {
                $join->on('u1.last_login_ip', '=', 'u2.registration_ip')
                    ->whereColumn('u1.id', '!=', 'u2.id');
            })
            ->whereNotNull('u1.last_login_ip')
            ->distinct()
            ->pluck('u1.last_login_ip');

        return $sharedRegistrationIps
            ->merge($sharedLoginIps)
            ->merge($crossRegToLoginIps)
            ->merge($crossLoginToRegIps)
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    protected static function collectSharedDeviceIdsForBadge(): \Illuminate\Support\Collection
    {
        if (! static::hasDeviceColumnsStatic()) {
            return collect();
        }

        $sharedRegistrationDeviceIds = DB::table('users')
            ->select('registration_device_id as device_id')
            ->whereNotNull('registration_device_id')
            ->groupBy('registration_device_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('device_id');

        $sharedSeenDeviceIds = DB::table('users')
            ->select('last_seen_device_id as device_id')
            ->whereNotNull('last_seen_device_id')
            ->groupBy('last_seen_device_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('device_id');

        $crossRegToSeenDeviceIds = DB::table('users as u1')
            ->join('users as u2', function ($join) {
                $join->on('u1.registration_device_id', '=', 'u2.last_seen_device_id')
                    ->whereColumn('u1.id', '!=', 'u2.id');
            })
            ->whereNotNull('u1.registration_device_id')
            ->distinct()
            ->pluck('u1.registration_device_id');

        $crossSeenToRegDeviceIds = DB::table('users as u1')
            ->join('users as u2', function ($join) {
                $join->on('u1.last_seen_device_id', '=', 'u2.registration_device_id')
                    ->whereColumn('u1.id', '!=', 'u2.id');
            })
            ->whereNotNull('u1.last_seen_device_id')
            ->distinct()
            ->pluck('u1.last_seen_device_id');

        return $sharedRegistrationDeviceIds
            ->merge($sharedSeenDeviceIds)
            ->merge($crossRegToSeenDeviceIds)
            ->merge($crossSeenToRegDeviceIds)
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    protected static function collectSharedRequestSignaturesForBadge(): \Illuminate\Support\Collection
    {
        if (! static::hasRequestSignatureColumnsStatic()) {
            return collect();
        }

        $sharedRegistrationSignatures = DB::table('users')
            ->select('registration_request_signature as request_signature')
            ->whereNotNull('registration_request_signature')
            ->groupBy('registration_request_signature')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('request_signature');

        $sharedSeenSignatures = DB::table('users')
            ->select('last_seen_request_signature as request_signature')
            ->whereNotNull('last_seen_request_signature')
            ->groupBy('last_seen_request_signature')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('request_signature');

        $crossRegToSeenSignatures = DB::table('users as u1')
            ->join('users as u2', function ($join) {
                $join->on('u1.registration_request_signature', '=', 'u2.last_seen_request_signature')
                    ->whereColumn('u1.id', '!=', 'u2.id');
            })
            ->whereNotNull('u1.registration_request_signature')
            ->distinct()
            ->pluck('u1.registration_request_signature');

        $crossSeenToRegSignatures = DB::table('users as u1')
            ->join('users as u2', function ($join) {
                $join->on('u1.last_seen_request_signature', '=', 'u2.registration_request_signature')
                    ->whereColumn('u1.id', '!=', 'u2.id');
            })
            ->whereNotNull('u1.last_seen_request_signature')
            ->distinct()
            ->pluck('u1.last_seen_request_signature');

        return $sharedRegistrationSignatures
            ->merge($sharedSeenSignatures)
            ->merge($crossRegToSeenSignatures)
            ->merge($crossSeenToRegSignatures)
            ->filter()
            ->unique()
            ->values();
    }

    private function describeSuspicionSignals(User $user): string
    {
        $signals = [];
        $sets = $this->sharedSignalSets();

        if (
            $this->valueInSet($sets['ips'], $user->registration_ip)
            || $this->valueInSet($sets['ips'], $user->last_login_ip)
        ) {
            $signals[] = 'IP';
        }

        if (
            $this->hasDeviceColumns()
            && (
                $this->valueInSet($sets['device_ids'], $user->registration_device_id)
                || $this->valueInSet($sets['device_ids'], $user->last_seen_device_id)
            )
        ) {
            $signals[] = 'Device';
        }

        if (
            $this->hasRequestSignatureColumns()
            && (
                $this->valueInSet($sets['request_signatures'], $user->registration_request_signature)
                || $this->valueInSet($sets['request_signatures'], $user->last_seen_request_signature)
            )
        ) {
            $signals[] = 'Signature';
        }

        return $signals !== [] ? implode(' + ', array_unique($signals)) : 'IP';
    }

    /**
     * @return array{
     *   ips: array<string, true>,
     *   device_ids: array<string, true>,
     *   request_signatures: array<string, true>
     * }
     */
    private function sharedSignalSets(): array
    {
        if ($this->sharedSignalSets !== null) {
            return $this->sharedSignalSets;
        }

        $ips = $this->getSharedIps()
            ->mapWithKeys(fn ($value) => [(string) $value => true])
            ->all();

        $deviceIds = $this->getSharedDeviceIds()
            ->mapWithKeys(fn ($value) => [(string) $value => true])
            ->all();

        $requestSignatures = $this->getSharedRequestSignatures()
            ->mapWithKeys(fn ($value) => [(string) $value => true])
            ->all();

        $this->sharedSignalSets = [
            'ips' => $ips,
            'device_ids' => $deviceIds,
            'request_signatures' => $requestSignatures,
        ];

        return $this->sharedSignalSets;
    }

    private function valueInSet(array $set, mixed $value): bool
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return false;
        }

        return isset($set[$normalized]);
    }

    protected function hasDeviceColumns(): bool
    {
        return static::hasDeviceColumnsStatic();
    }

    protected function hasRequestSignatureColumns(): bool
    {
        return static::hasRequestSignatureColumnsStatic();
    }

    protected static function hasDeviceColumnsStatic(): bool
    {
        return Schema::hasColumn('users', 'registration_device_id')
            && Schema::hasColumn('users', 'last_seen_device_id');
    }

    protected static function hasRequestSignatureColumnsStatic(): bool
    {
        return Schema::hasColumn('users', 'registration_request_signature')
            && Schema::hasColumn('users', 'last_seen_request_signature');
    }
}
