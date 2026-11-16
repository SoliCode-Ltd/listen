<?php

namespace App\Filament\Pages;

use App\Filament\Resources\UserResource;
use App\Models\DemoVoiceGeneration;
use App\Models\User;
use App\Services\BlockedUserService;
use App\Services\DemoSamePerson;
use App\Services\DemoSuspects;
use App\Services\MultiAccountEnforcementService;
use App\Services\UserDeletionService;
use App\Support\DemoAllowance;
use App\Support\PaidMember;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * The accounts the free demo counts as one person with at least one other
 * account (App\Services\DemoSuspects), with the numbers the demo itself uses
 * (App\Services\DemoSamePerson): how many accounts share this one's demo and
 * what that whole group has used of it.
 */
class SuspiciousUsers extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static string|\UnitEnum|null $navigationGroup = 'Demo';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.suspicious-users';

    /** @var array<int, array{ids: list<int>, chars: int}> the shared demo of each account shown, worked out once a page */
    private array $groups = [];

    /** @var array<int, list<string>>|null */
    private ?array $suspects = null;

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
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('ui.admin.suspicious.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('ui.admin.suspicious.email'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('phone_number')
                    ->label(__('ui.admin.suspicious.phone'))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('auth_method_display')
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
                TextColumn::make('suspicion_signals')
                    ->label(__('ui.admin.suspicious.suspicion_signals'))
                    ->badge()
                    ->getStateUsing(fn (User $record): string => $this->describeSuspicionSignals($record))
                    ->color(fn (User $record): string => $this->strongestLink($record) ? 'danger' : 'warning'),
                TextColumn::make('registration_ip')
                    ->label(__('ui.admin.suspicious.registration_ip'))
                    ->searchable()
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('last_login_ip')
                    ->label(__('ui.admin.suspicious.last_login_ip'))
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('registration_device_id')
                    ->label(__('ui.admin.suspicious.registration_device_id'))
                    ->searchable()
                    ->copyable()
                    ->placeholder('-')
                    ->visible(fn () => $this->hasDeviceColumns())
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_seen_device_id')
                    ->label(__('ui.admin.suspicious.last_seen_device_id'))
                    ->searchable()
                    ->copyable()
                    ->placeholder('-')
                    ->visible(fn () => $this->hasDeviceColumns())
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('linked_count')
                    ->label(__('ui.admin.suspicious.linked_accounts'))
                    ->badge()
                    ->getStateUsing(fn (User $record): int => count($this->groupOf($record)['ids']) - 1)
                    ->color(fn ($state): string => match (true) {
                        ((int) $state) >= 5 => 'danger',
                        ((int) $state) >= 2 => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('demo_chars_used')
                    ->label(__('ui.admin.suspicious.demo_chars'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('ip_group_total_chars')
                    ->label(__('ui.admin.suspicious.group_total_chars'))
                    ->numeric()
                    ->badge()
                    ->getStateUsing(fn (User $record): int => $this->groupOf($record)['chars'])
                    ->color(fn ($state): string => ((int) $state) >= DemoAllowance::chars() ? 'danger' : 'success'),
                TextColumn::make('blocked_status')
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
                TextColumn::make('created_at')
                    ->label(__('ui.admin.suspicious.registered_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('linked_by')
                    ->label(__('ui.admin.suspicious.linked_by_filter'))
                    ->options(collect(DemoSuspects::KINDS)->mapWithKeys(fn (string $kind): array => [$kind => __('ui.admin.suspicious.link_'.$kind)])->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereIn('users.id', $this->suspectIdsLinkedBy((string) $data['value']))
                        : $query),
                Filter::make('has_demo_usage')
                    ->label(__('ui.admin.suspicious.has_demo_usage'))
                    ->query(fn (Builder $query) => $query->whereExists(fn ($sub) => $sub->selectRaw('1')
                        ->from('demo_voice_generations')
                        ->whereColumn('demo_voice_generations.user_id', 'users.id')
                        ->where('demo_voice_generations.billable_chars', '>', 0))),
                TernaryFilter::make('is_blocked')
                    ->label(__('ui.admin.suspicious.blocked_filter'))
                    ->queries(
                        true: fn (Builder $query) => $query->where('users.is_blocked', true),
                        false: fn (Builder $query) => $query->where(fn ($q) => $q->where('users.is_blocked', false)->orWhereNull('users.is_blocked')),
                    ),
                TernaryFilter::make('multi_account_whitelisted')
                    ->label(__('ui.admin.suspicious.whitelisted_filter'))
                    ->queries(
                        true: fn (Builder $query) => $query->where('users.multi_account_whitelisted', true),
                        false: fn (Builder $query) => $query->where(fn ($q) => $q->where('users.multi_account_whitelisted', false)->orWhereNull('users.multi_account_whitelisted')),
                    ),
            ])
            ->recordActions([
                Action::make('deleteUser')
                    ->label(__('ui.admin.suspicious.delete_user'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (User $record) => ! $record->is_admin && auth()->id() !== $record->id)
                    ->requiresConfirmation()
                    ->modalHeading(__('ui.admin.suspicious.delete_confirm_title'))
                    ->modalDescription(__('ui.admin.suspicious.delete_confirm_description'))
                    ->action(function (User $record): void {
                        // Checked before anything is removed: a member who would still be charged is kept.
                        $refusal = app(UserDeletionService::class)->delete($record, auth()->user(), withDemoHistory: true);
                        app(DemoSuspects::class)->forget();
                        if ($refusal !== null) {
                            Notification::make()
                                ->danger()
                                ->title(__('ui.admin.users.delete_refused_title'))
                                ->body($refusal)
                                ->persistent()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('ui.admin.suspicious.delete_success'))
                            ->send();
                    }),
                Action::make('blockUser')
                    ->label(__('ui.admin.users.block'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (User $record) => ! $record->isBlocked() && ! $record->is_admin)
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        $enforcement = app(MultiAccountEnforcementService::class);
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
                        app(DemoSuspects::class)->forget();

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
                    ->action(function (User $record): void {
                        app(BlockedUserService::class)->unblock($record);
                        app(DemoSuspects::class)->forget();

                        Notification::make()
                            ->success()
                            ->title(__('ui.admin.users.unblocked_success'))
                            ->send();
                    }),
                ActionGroup::make([
                    Action::make('viewUser')
                        ->label(__('ui.admin.suspicious.view_user'))
                        ->icon('heroicon-o-eye')
                        ->url(fn (User $record): string => UserResource::getUrl('view', ['record' => $record]))
                        ->openUrlInNewTab(),
                    Action::make('viewLinked')
                        ->label(__('ui.admin.suspicious.view_linked'))
                        ->icon('heroicon-o-link')
                        ->color('warning')
                        ->modalHeading(fn (User $record): string => __('ui.admin.suspicious.linked_modal_title', ['name' => $record->name]))
                        ->modalContent(fn (User $record) => view('filament.pages.partials.linked-users-modal', [
                            'users' => $this->getLinkedUsers($record),
                            'sourceUser' => $record,
                            'groupChars' => $this->groupOf($record)['chars'],
                            'charAllowance' => DemoAllowance::chars(),
                        ]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('ui.admin.suspicious.close')),
                    Action::make('resetDemoChars')
                        ->label(__('ui.admin.users.reset_demo_chars'))
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading(__('ui.admin.users.reset_demo_confirm_title'))
                        ->modalDescription(__('ui.admin.users.reset_demo_confirm_description'))
                        // From the row's own query: no extra lookup for each row.
                        ->visible(fn (User $record) => (int) $record->demo_chars_used > 0)
                        ->action(function (User $record): void {
                            DemoVoiceGeneration::where('user_id', $record->id)->delete();
                            Cache::forget("demo:ip_linked_users:{$record->id}");
                            app(DemoSuspects::class)->forget();

                            Notification::make()
                                ->success()
                                ->title(__('ui.admin.users.reset_demo_success'))
                                ->send();
                        }),
                ]),
            ])
            ->toolbarActions([
                BulkAction::make('deleteSelected')
                    ->label(__('ui.admin.suspicious.bulk_delete'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('ui.admin.suspicious.bulk_delete_confirm_title'))
                    ->modalDescription(__('ui.admin.suspicious.bulk_delete_confirm_description'))
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (EloquentCollection $records) => $this->deleteSelected($records)),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->paginated([10, 25, 50]);
    }

    /**
     * Deletes the chosen accounts one by one, as Delete User does, and says what was
     * kept: admins and the admin's own account, members who pay or have paid (each
     * one is the admin's own decision, from their row), and members still charged.
     */
    public function deleteSelected(EloquentCollection $records): void
    {
        $deletion = app(UserDeletionService::class);
        $deleted = 0;
        $kept = [];

        foreach ($records as $record) {
            if ($record->is_admin || (int) auth()->id() === (int) $record->id) {
                $kept[] = __('ui.admin.suspicious.bulk_kept_admin', ['email' => $record->email]);

                continue;
            }
            if (PaidMember::is($record)) {
                $kept[] = __('ui.admin.suspicious.bulk_kept_paid', ['email' => $record->email]);

                continue;
            }
            $refusal = $deletion->delete($record, auth()->user(), withDemoHistory: true);
            if ($refusal !== null) {
                $kept[] = $record->email.': '.$refusal;

                continue;
            }
            $deleted++;
        }
        app(DemoSuspects::class)->forget();

        $note = Notification::make()
            ->title(trans_choice('ui.admin.suspicious.bulk_deleted', $deleted, ['count' => $deleted]));
        if ($kept === []) {
            $note->success();
        } else {
            $note->warning()->body(implode("\n", $kept))->persistent();
        }
        $note->send();
    }

    protected function getSuspiciousUsersQuery(): Builder
    {
        $ids = array_keys($this->suspects());

        $query = User::query()
            ->select('users.*')
            ->selectSub(
                DemoVoiceGeneration::query()
                    ->selectRaw('COALESCE(SUM(billable_chars), 0)')
                    ->whereColumn('user_id', 'users.id'),
                'demo_chars_used'
            );

        return $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn('users.id', $ids);
    }

    /**
     * The accounts that share this one's free demo (it included), as the demo counts
     * them, each with its own demo characters.
     *
     * @return Collection<int, User>
     */
    protected function getLinkedUsers(User $user): Collection
    {
        $ids = $this->groupOf($user)['ids'];
        if (count($ids) < 2) {
            return collect();
        }

        $chars = DemoVoiceGeneration::query()->whereIn('user_id', $ids)
            ->groupBy('user_id')->selectRaw('user_id, COALESCE(SUM(billable_chars), 0) as chars')
            ->pluck('chars', 'user_id');

        return User::query()->whereIn('id', $ids)->get()
            ->map(function (User $u) use ($chars): User {
                $u->setAttribute('demo_chars', (int) ($chars[$u->id] ?? 0));

                return $u;
            });
    }

    public static function getNavigationBadge(): ?string
    {
        $count = app(DemoSuspects::class)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * This account's shared demo, as the demo itself counts it: the accounts that are
     * the same person (deleted ones' use included) and what they have used together.
     *
     * @return array{ids: list<int>, chars: int}
     */
    private function groupOf(User $user): array
    {
        if (! isset($this->groups[$user->id])) {
            $this->groups[$user->id] = app(DemoSamePerson::class)->demoUsage($user);
        }

        return $this->groups[$user->id];
    }

    /** @return array<int, list<string>> */
    private function suspects(): array
    {
        return $this->suspects ??= app(DemoSuspects::class)->all();
    }

    /** @return list<int> */
    private function suspectIdsLinkedBy(string $kind): array
    {
        $ids = array_keys(array_filter($this->suspects(), fn (array $kinds): bool => in_array($kind, $kinds, true)));

        return $ids === [] ? [0] : $ids;
    }

    private function describeSuspicionSignals(User $user): string
    {
        $kinds = $this->suspects()[$user->id] ?? [];

        return $kinds === []
            ? '-'
            : implode(' + ', array_map(fn (string $kind): string => __('ui.admin.suspicious.link_'.$kind), $kinds));
    }

    /** Linked by a browser, a device or an inbox: the same person beyond doubt. */
    private function strongestLink(User $user): bool
    {
        return array_intersect($this->suspects()[$user->id] ?? [], [DemoSuspects::BROWSER, DemoSuspects::DEVICE, DemoSuspects::INBOX]) !== [];
    }

    protected function hasDeviceColumns(): bool
    {
        return static::hasDeviceColumnsStatic();
    }

    protected static function hasDeviceColumnsStatic(): bool
    {
        return once(fn (): bool => Schema::hasColumn('users', 'registration_device_id')
            && Schema::hasColumn('users', 'last_seen_device_id'));
    }
}
