<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the privacy policy, the terms and the cookie policy say matches what
 * the site does with members' data, in English and Arabic.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_privacy_policy_says_what_is_kept_and_where_it_goes(): void
    {
        $this->page('en', 'legal.privacy')
            ->assertSee('Effective date: October 19, 2026')
            ->assertSee('The text you convert is kept with its audio in your history until you delete it.')
            ->assertSee('Your generated audio stays in your history until you delete it.')
            ->assertSee('The text you convert goes to our speech and translation processing providers, and the recordings you make for voice cloning (your voice sample and your consent recording) go to our speech provider, only to make your audio.')
            ->assertSee('Your phone number, if you sign in with it')
            ->assertSee('Payment proof images you upload when you pay manually')
            ->assertSee('A device cookie and a fingerprint of your device')
            ->assertSee('developer API request logs are kept for '.ApiRequestLog::KEEP_DAYS.' days')
            ->assertSee('To access, update or delete your personal information, contact us at')
            ->assertDontSee('not stored permanently')
            ->assertDontSee('according to your plan settings')
            ->assertDontSee('through your dashboard');

        $this->page('ar', 'legal.privacy')
            ->assertSee('تاريخ السريان: 19 أكتوبر 2026')
            ->assertSee('يُحفظ النص الذي تحوّله مع صوته في سجلك إلى أن تحذفه.')
            ->assertSee('يبقى الصوت الذي تنشئه في سجلك إلى أن تحذفه.')
            ->assertSee('يُرسَل النص الذي تحوّله إلى مزودي معالجة الكلام والترجمة لدينا')
            ->assertSee('رقم هاتفك إن سجّلت الدخول به')
            ->assertSee('صور إثبات الدفع التي ترفعها عند الدفع اليدوي')
            ->assertSee('ملف تعريف ارتباط للجهاز وبصمة لجهازك')
            ->assertSee('سجلات طلبات الواجهة البرمجية للمطورين '.ApiRequestLog::KEEP_DAYS.' يوماً')
            ->assertSee('للوصول إلى معلوماتك الشخصية أو تحديثها أو حذفها، تواصل معنا عبر')
            ->assertDontSee('ولا يُخزّن بشكل دائم')
            ->assertDontSee('وفقاً لإعدادات خطتك')
            ->assertDontSee('من خلال لوحة التحكم');
    }

    public function test_the_privacy_policy_names_no_provider(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $policy = $this->policyText($this->page($locale, 'legal.privacy')->getContent());
            $this->assertDoesNotMatchRegularExpression('/gemini|google|firebase|stripe|instapay|vodafone|جوجل|جيميني/iu', $policy, $locale);
        }
    }

    private function page(string $locale, string $route): \Illuminate\Testing\TestResponse
    {
        app()->setLocale($locale);

        return $this->getLocalizedRoute($route)->assertOk();
    }

    /** The policy itself, without the site's header and footer. */
    private function policyText(string $html): string
    {
        preg_match('/<article class="legal-shell">(.*?)<\/article>/s', $html, $article);

        return strip_tags($article[1] ?? '');
    }
}
