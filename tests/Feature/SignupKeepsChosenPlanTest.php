<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * A visitor who picks a paid plan on the pricing page signs up first, then
 * lands on that plan's checkout, not on the free demo.
 */
class SignupKeepsChosenPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::factory()->free()->create(['monthly_chars' => 10000, 'voice_tier' => 'standard']);
        Plan::factory()->create(['slug' => 'creator', 'name' => 'Creator', 'price_cents' => 2200, 'is_active' => true, 'monthly_chars' => 450000]);
    }

    public function test_a_visitors_plan_buttons_carry_the_plan_to_sign_up(): void
    {
        $this->getLocalizedRoute('pricing')
            ->assertOk()
            ->assertSee(e(route('register', ['plan' => 'creator', 'billing_cycle' => 'monthly'])), false)
            ->assertSee(e(route('register', ['plan' => 'creator', 'billing_cycle' => 'yearly'])), false);
    }

    public function test_signing_up_after_picking_a_plan_lands_on_its_checkout(): void
    {
        $this->openSignUp('plan=creator&billing_cycle=yearly')->assertOk();
        $billing = route('billing.page', ['plan' => 'creator', 'billing_cycle' => 'yearly']);
        $this->assertSame($billing, session('url.intended'));

        $this->signUp('picked@example.test')->assertRedirect($billing);
    }

    public function test_signing_in_instead_also_lands_on_the_checkout(): void
    {
        $member = User::factory()->create(['email' => 'back@example.test', 'password' => bcrypt('password123')]);
        $this->openSignUp('plan=creator')->assertOk();

        $this->postLocalizedRoute('login.submit', ['email' => $member->email, 'password' => 'password123'])
            ->assertRedirect(route('billing.page', ['plan' => 'creator', 'billing_cycle' => 'monthly']));
    }

    public function test_an_unknown_or_free_plan_is_not_remembered(): void
    {
        foreach (['nope', 'free', ''] as $slug) {
            $this->openSignUp('plan='.$slug)->assertOk();
            $this->assertNull(session('url.intended'), "plan '{$slug}'");
        }

        $this->signUp('plain@example.test')->assertRedirect(route('demo.page'));
    }

    private function openSignUp(string $query): TestResponse
    {
        return $this->withoutMiddleware([
            LocaleSessionRedirect::class,
            LaravelLocalizationRedirectFilter::class,
            LaravelLocalizationViewPath::class,
        ])->get($this->localizedRoute('register').'?'.$query);
    }

    private function signUp(string $email): TestResponse
    {
        return $this->postLocalizedRoute('register.submit', [
            'name' => 'New Member',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
    }
}
