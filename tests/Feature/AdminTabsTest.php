<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every tab of the admin opens for an admin, and the tabs nobody uses are gone:
 * Source Accounts was a list for a feature that was never built (nothing in the
 * site makes or reads one).
 */
class AdminTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_admin_tab_opens_for_an_admin(): void
    {
        Plan::factory()->free()->create();
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);

        $uris = collect(Route::getRoutes())
            ->filter(fn ($route) => in_array('GET', $route->methods(), true))
            ->map(fn ($route) => $route->uri())
            ->filter(fn (string $uri) => str_starts_with($uri, 'admin/') || $uri === 'admin')
            ->reject(fn (string $uri) => str_contains($uri, '{') || in_array($uri, ['admin/login', 'admin/logout'], true))
            ->values();
        $this->assertGreaterThan(40, $uris->count());

        foreach ($uris as $uri) {
            $status = $this->actingAs($admin)->get('/'.$uri)->getStatusCode();
            // A list whose records only the site makes (contact messages, say) has no create page.
            $allowed = str_ends_with($uri, '/create') ? [200, 403] : [200];
            $this->assertContains($status, $allowed, "/{$uri} answered {$status}");
        }
    }

    public function test_the_source_accounts_tab_is_gone(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->assertFalse(Route::has('filament.admin.resources.source-accounts.index'));
        $this->actingAs($admin)->get('/admin/source-accounts')->assertNotFound();
        $this->actingAs($admin)->get('/admin')->assertOk()->assertDontSee('Source Accounts');
    }
}
