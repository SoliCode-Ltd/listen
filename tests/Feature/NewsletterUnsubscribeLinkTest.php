<?php

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Services\Newsletter\NewsletterRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Unsubscribe links are signed for the site's https address. Behind the host's
 * proxy the request can reach PHP as http: the link must still work, and a
 * changed or expired link must still be refused.
 */
class NewsletterUnsubscribeLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_https_link_works_when_the_request_arrives_as_http(): void
    {
        $link = $this->httpsLink('reader@example.com');
        $this->assertStringStartsWith('https://listen.test/', $link);

        $this->get($this->overHttp($link))->assertOk()->assertSee('You are unsubscribed');

        $this->assertDatabaseHas('newsletter_suppressions', ['email' => 'reader@example.com']);
    }

    public function test_an_https_link_still_works_over_https(): void
    {
        $this->get($this->httpsLink('reader@example.com'))->assertOk();

        $this->assertDatabaseHas('newsletter_suppressions', ['email' => 'reader@example.com']);
    }

    public function test_a_changed_link_is_refused_over_http_and_https(): void
    {
        $link = str_replace('reader%40example.com', 'someone-else%40example.com', $this->httpsLink('reader@example.com'));
        $this->assertStringContainsString('someone-else', $link);

        $this->get($link)->assertForbidden();
        $this->get($this->overHttp($link))->assertForbidden();
        $this->get($this->overHttp($this->httpsLink('reader@example.com')).'&lang=ar')->assertForbidden();

        $this->assertDatabaseCount('newsletter_suppressions', 0);
    }

    public function test_an_expired_link_is_refused_over_http_and_https(): void
    {
        URL::forceRootUrl('https://listen.test');
        URL::forceScheme('https');
        $link = URL::temporarySignedRoute('newsletter.unsubscribe', now()->subMinute(), ['email' => 'reader@example.com', 'lang' => 'en']);

        $this->get($link)->assertForbidden();
        $this->get($this->overHttp($link))->assertForbidden();

        $this->assertDatabaseCount('newsletter_suppressions', 0);
    }

    /** A link as the newsletters build it on the live site (APP_URL is https). */
    private function httpsLink(string $email): string
    {
        URL::forceRootUrl('https://listen.test');
        URL::forceScheme('https');

        return app(NewsletterRenderer::class)->unsubscribeUrl($email, Newsletter::LANGUAGE_EN);
    }

    private function overHttp(string $link): string
    {
        return 'http://'.substr($link, strlen('https://'));
    }
}
