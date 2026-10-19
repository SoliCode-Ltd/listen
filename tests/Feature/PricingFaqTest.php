<?php

namespace Tests\Feature;

use App\Support\DialectCounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pricing page's answers say what the site does: a plan change applies
 * at once, up or down (BillingController::planChangeDetails), and the usage
 * goes on with it (CreditPeriod::carryOverPlanChange); generation stops at
 * the limit and overage is never billed; the accents are the catalog's; and
 * the free demo needs a confirmed email or phone, once per device.
 */
class PricingFaqTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_answers_are_on_the_page_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $page = $this->getLocalizedRoute('pricing')->assertOk();
            foreach ([1, 2, 3, 4] as $number) {
                $page->assertSee(__('ui.pricing.faq'.$number.'_a'));
            }
        }
    }

    public function test_the_answers_promise_only_what_the_site_does(): void
    {
        $en = fn (int $number): string => __('ui.pricing.faq'.$number.'_a', [], 'en');
        $ar = fn (int $number): string => __('ui.pricing.faq'.$number.'_a', [], 'ar');

        // A downgrade applies at once too, with the unused time kept as account credit.
        $this->assertStringNotContainsString('next billing cycle', $en(1));
        $this->assertStringContainsString('takes effect right away', $en(1));
        $this->assertStringContainsString('account credit', $en(1));
        $this->assertStringNotContainsString('دورة الفوترة التالية', $ar(1));
        $this->assertStringContainsString('يسري التغيير فوراً', $ar(1));

        // No notification is sent: the studio says why, and nothing is billed over the limit.
        $this->assertStringNotContainsString('notification', $en(2));
        $this->assertStringContainsString('overage is never billed', $en(2));
        $this->assertStringNotContainsString('إشعار', $ar(2));
        $this->assertStringContainsString('لا نحاسب على أي استخدام زائد', $ar(2));

        // The catalog's number of accents, not "more than" it.
        $this->assertStringContainsString(' '.DialectCounts::arabic().' regional accents', $en(3));
        $this->assertStringContainsString(' '.DialectCounts::arabic().' لكنة محلية', $ar(3));
        $this->assertStringNotContainsString('more than', $en(3));
        $this->assertStringNotContainsString('أكثر من', $ar(3));

        // The demo, once, for a confirmed account, shared by the accounts on one device.
        $this->assertStringNotContainsString('Every new account', $en(4));
        $this->assertStringContainsString('after confirming their email address or phone number', $en(4));
        $this->assertStringContainsString('same device share one free demo', $en(4));
        $this->assertStringContainsString('بعد تأكيد البريد الإلكتروني أو رقم الهاتف', $ar(4));
        $this->assertStringContainsString('الجهاز نفسه', $ar(4));
    }
}
