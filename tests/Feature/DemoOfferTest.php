<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Support\DemoOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The free demo shows the Starter plan's offer under a voice it has just made, by itself:
 * there while Starter has an offer running, gone when it ends. Its words are that offer and
 * its last day, and its button opens the checkout for Starter.
 */
class DemoOfferTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::query()->update(['is_active' => false]);
        Plan::factory()->free()->create(['monthly_chars' => 10000, 'voice_tier' => 'standard']);
        foreach (['basic' => 400, 'starter' => 900, 'creator' => 2200] as $slug => $cents) {
            $plan = Plan::query()->firstOrNew(['slug' => $slug]);
            $plan->forceFill(array_merge(Plan::factory()->raw(), [
                'slug' => $slug, 'name' => ucfirst($slug), 'kind' => Plan::KIND_SUBSCRIPTION, 'is_active' => true,
                'price_cents' => $cents, 'monthly_chars' => $cents * 100, 'discount_percentage' => null, 'discount_ends_at' => null,
            ]))->save();
        }
        $this->member = User::factory()->create(['email_verified_at' => now()]);
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_no_offer_running_shows_nothing(): void
    {
        $this->assertNull(DemoOffer::current());
        $this->demo('en')->assertDontSee('id="demoOffer"', false)->assertDontSee('Like the voice?');
    }

    public function test_starters_offer_shows_under_the_result_with_its_last_day_and_a_button_to_its_checkout(): void
    {
        $this->travelTo('2026-11-12 10:00:00');
        $this->offer(['basic'], 25, '2026-11-16 00:00:00');
        $this->offer(['starter', 'creator'], 50, '2026-11-16 00:00:00');

        $checkout = route('billing.page', ['plan' => 'starter', 'billing_cycle' => 'monthly']);
        $this->assertStringEndsWith('/billing?plan=starter&billing_cycle=monthly', $checkout);
        $html = (string) $this->demo('en')
            ->assertSee('Like the voice?')
            ->assertSee('Subscribe now and get <b>50% off your first month</b>. The offer ends 15 November.', false)
            ->assertSee('<a class="demo-offer-btn" href="'.e($checkout).'">Get the offer</a>', false)
            ->getContent();
        // Inside the result card, so it is seen only once a voice has been made.
        $card = substr($html, (int) strpos($html, 'id="audioWrap"'), (int) strpos($html, 'id="outputPlaceholder"') - (int) strpos($html, 'id="audioWrap"'));
        $this->assertStringContainsString('id="demoOffer"', $card);

        $this->demo('ar')
            ->assertSee('أعجبك الصوت؟')
            ->assertSee('اشترك الآن واحصل على <b>خصم 50% على أول شهر</b>. ينتهي العرض 15 نوفمبر.', false)
            ->assertSee('احصل على الخصم');
    }

    public function test_an_offer_with_no_end_date_says_no_last_day(): void
    {
        $this->offer(['starter'], 30);

        $this->demo('en')->assertSee('Subscribe now and get <b>30% off your first month</b>.', false)->assertDontSee('The offer ends');
    }

    public function test_an_offer_on_other_plans_only_shows_nothing_as_the_button_opens_starter(): void
    {
        $this->offer(['basic', 'creator'], 50);

        $this->assertNull(DemoOffer::current());
        $this->demo('en')->assertDontSee('Like the voice?');
    }

    public function test_it_goes_by_itself_when_the_offer_ends(): void
    {
        $this->travelTo('2026-11-12 10:00:00');
        $this->offer(['starter'], 50, '2026-11-13 00:00:00');
        $this->demo('en')->assertSee('Like the voice?');

        $this->travelTo('2026-11-13 00:00:01');
        $this->demo('en')->assertDontSee('Like the voice?');
    }

    private function demo(string $locale): \Illuminate\Testing\TestResponse
    {
        app()->setLocale($locale);

        return $this->actingAs($this->member)->getLocalizedRoute('demo.page')->assertOk();
    }

    /**
     * @param  array<int, string>  $slugs
     */
    private function offer(array $slugs, int $percent, ?string $until = null): void
    {
        Plan::query()->whereIn('slug', $slugs)->update(['discount_percentage' => $percent, 'discount_ends_at' => $until, 'discount_duration' => Plan::DISCOUNT_FIRST_PAYMENT]);
    }
}
