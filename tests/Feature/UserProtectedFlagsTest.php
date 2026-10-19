<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use App\Services\BlockedUserService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin flag, blocking and the multi-account whitelist cannot be set by
 * mass assignment (a future $user->update($request->all()) must never make
 * someone an admin); the admin panel and the block service still set them.
 */
class UserProtectedFlagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mass_assignment_cannot_set_the_admin_or_block_flags(): void
    {
        $member = User::factory()->create();

        $member->update([
            'name' => 'Renamed',
            'is_admin' => true,
            'is_blocked' => true,
            'multi_account_whitelisted' => true,
            'blocked_at' => now(),
            'blocked_reason' => 'x',
            'blocked_reason_ar' => 'x',
            'blocked_reason_en' => 'x',
            'blocked_by' => $member->id,
        ]);
        $member->refresh();

        $this->assertSame('Renamed', $member->name, 'ordinary fields still save');
        $this->assertFalse($member->is_admin);
        $this->assertFalse($member->is_blocked);
        $this->assertFalse((bool) $member->multi_account_whitelisted);
        $this->assertNull($member->blocked_at);
        $this->assertNull($member->blocked_reason);
        $this->assertNull($member->blocked_reason_ar);
        $this->assertNull($member->blocked_reason_en);
        $this->assertNull($member->blocked_by);

        $created = User::query()->create([
            'name' => 'Sign-up',
            'email' => 'signup@example.com',
            'password' => 'secret-password',
            'is_admin' => true,
        ]);
        $this->assertFalse((bool) $created->fresh()->is_admin);
    }

    public function test_factories_and_the_block_service_still_set_them(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'multi_account_whitelisted' => true]);
        $this->assertTrue($admin->fresh()->is_admin);
        $this->assertTrue((bool) $admin->fresh()->multi_account_whitelisted);

        $member = User::factory()->create();
        app(BlockedUserService::class)->block($member, 'Abuse', $admin);
        $this->assertTrue($member->fresh()->is_blocked);
        $this->assertSame($admin->id, (int) $member->fresh()->blocked_by);

        app(BlockedUserService::class)->unblock($member->fresh());
        $this->assertFalse($member->fresh()->is_blocked);
    }

    public function test_the_admin_user_form_still_saves_the_admin_toggle(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create(['name' => 'Member']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $member->getKey()])
            ->fillForm(['name' => 'Member Two', 'is_admin' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($member->fresh()->is_admin);
        $this->assertSame('Member Two', $member->fresh()->name);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $member->getKey()])
            ->fillForm(['is_admin' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($member->fresh()->is_admin);

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Second Admin',
                'email' => 'second-admin@example.com',
                'password' => 'a-long-password',
                'is_admin' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue((bool) User::query()->where('email', 'second-admin@example.com')->value('is_admin'));
    }
}
