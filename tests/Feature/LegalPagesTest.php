<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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
            ->assertSee('Effective date: October 28, 2026')
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
            ->assertSee('تاريخ السريان: 28 أكتوبر 2026')
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

    public function test_the_cookie_policy_names_the_free_demos_device_cookie_and_fingerprint(): void
    {
        $this->page('en', 'legal.cookies')
            ->assertSee('Effective date: October 28, 2026')
            ->assertSee('including a device cookie and a fingerprint of your device (kept in a cookie and in your browser&#039;s storage), so the free demo is used only once per device', false);
        $this->page('ar', 'legal.cookies')
            ->assertSee('تاريخ السريان: 28 أكتوبر 2026')
            ->assertSee('ومنها ملف تعريف ارتباط للجهاز وبصمة لجهازك (تُحفظ في ملف تعريف ارتباط وفي مساحة التخزين بمتصفحك)');
    }

    public function test_the_policies_say_what_an_ad_click_is_kept_for_and_where_it_is_not(): void
    {
        $this->page('en', 'legal.privacy')
            ->assertSee('If you arrive from one of our ads, the ad click ID from the link you clicked, kept for up to 90 days')
            ->assertSee('we share that ad click ID, with the time and amount of the payment, with our advertising partner. We never share your name, email or phone number for this, and we do not keep or share the ad click ID of visitors from the United Kingdom, the European Economic Area or Switzerland')
            ->assertSee('We may use third-party services for analytics, advertising, sign-in and payment processing.');
        $this->page('en', 'legal.cookies')
            ->assertSee('Advertising Cookies')
            ->assertSee('We do not set it for visitors from the United Kingdom, the European Economic Area or Switzerland.')
            ->assertSee('Advertising partners may set cookies to measure how our ads perform.');

        $this->page('ar', 'legal.privacy')
            ->assertSee('إن وصلت إلينا من أحد إعلاناتنا، معرّف النقرة على الإعلان من الرابط الذي نقرت عليه، ويُحفظ مدة أقصاها 90 يوماً')
            ->assertSee('ولا نحفظ معرّف النقرة ولا نشاركه لزوار المملكة المتحدة والمنطقة الاقتصادية الأوروبية وسويسرا')
            ->assertSee('قد نستخدم خدمات طرف ثالث للتحليلات والإعلانات وتسجيل الدخول ومعالجة الدفع.');
        $this->page('ar', 'legal.cookies')
            ->assertSee('ملفات تعريف الارتباط الإعلانية')
            ->assertSee('لا نضعه لزوار المملكة المتحدة والمنطقة الاقتصادية الأوروبية وسويسرا.')
            ->assertSee('قد يضع شركاؤنا الإعلانيون ملفات تعريف ارتباط لقياس أداء إعلاناتنا.');
    }

    public function test_the_legal_pages_give_the_sites_own_mailbox(): void
    {
        foreach (['legal.privacy', 'legal.terms', 'legal.cookies'] as $route) {
            foreach (['en', 'ar'] as $locale) {
                $this->page($locale, $route)
                    ->assertSee('<a href="mailto:help@listenai.studio">help@listenai.studio</a>', false)
                    ->assertDontSee('support@listenai.studio');
            }
        }

        // The developer docs and their machine-readable spec give the same mailbox.
        $this->page('en', 'developers')
            ->assertSee('<a href="mailto:help@listenai.studio" dir="ltr">help@listenai.studio</a>', false)
            ->assertDontSee('support@listenai.studio');
        $this->getJson('/v1/openapi.json')->assertOk()->assertJsonPath('info.contact.email', 'help@listenai.studio');

        // The address set in Admin → Site Settings, as on the contact page.
        SiteSetting::set('contact_email', 'team@example.com', 'contact');
        $this->page('en', 'legal.terms')->assertSee('<a href="mailto:team@example.com">team@example.com</a>', false);
    }

    public function test_the_privacy_policy_names_no_provider(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $policy = $this->policyText($this->page($locale, 'legal.privacy')->getContent());
            $this->assertDoesNotMatchRegularExpression('/gemini|google|firebase|stripe|instapay|vodafone|جوجل|جيميني/iu', $policy, $locale);
        }
    }

    private function page(string $locale, string $route): TestResponse
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
