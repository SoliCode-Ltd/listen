<?php

namespace Tests\Feature\Agents;

use App\Services\Agents\SiteReader;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reading a business's website for its agent: the words a visitor reads,
 * from the page given and its pages about prices, services and contact,
 * and never anything but a public address.
 */
class SiteReaderTest extends TestCase
{
    private function reader(): SiteReader
    {
        // The test pages live on a made-up public host, at a documentation address.
        return new class extends SiteReader
        {
            protected function addressFor(string $host): ?string
            {
                return $host === 'clinic.example' ? '203.0.113.7' : null;
            }
        };
    }

    public function test_it_keeps_what_a_visitor_reads_and_drops_scripts_menus_and_footers(): void
    {
        $text = SiteReader::textOf('<html><head><style>.x{}</style><script>alert(1)</script></head><body>'
            .'<nav><a href="/">Home</a></nav><h1>Cairo Smiles</h1><p>Teeth whitening: 1,500 EGP</p>'
            .'<ul><li>Open 10am&nbsp;to 10pm</li></ul><footer>© 2026</footer></body></html>');

        $this->assertStringContainsString('Cairo Smiles', $text);
        $this->assertStringContainsString('Teeth whitening: 1,500 EGP', $text);
        $this->assertStringContainsString('- Open 10am to 10pm', $text);
        $this->assertStringNotContainsString('alert', $text);
        $this->assertStringNotContainsString('Home', $text);
        $this->assertStringNotContainsString('©', $text);
    }

    public function test_it_reads_the_page_and_its_prices_and_contact_pages_once_each(): void
    {
        Http::fake([
            'https://clinic.example/' => Http::response('<title>Cairo Smiles</title><body><p>Dental clinic in Nasr City</p>'
                .'<a href="/pricing">Prices</a> <a href="/contact-us">Contact</a> <a href="/blog/post-1">Blog</a>'
                .'<a href="https://other.example/pricing">Elsewhere</a> <a href="/brochure.pdf">PDF</a></body>', 200, ['Content-Type' => 'text/html; charset=utf-8']),
            'https://clinic.example/pricing' => Http::response('<title>Prices</title><p>Whitening 1,500 EGP</p><p>Dental clinic in Nasr City</p>', 200, ['Content-Type' => 'text/html']),
            'https://clinic.example/contact-us' => Http::response('<title>Contact</title><p>Call 0100 000 0000</p>', 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('nope', 404),
        ]);

        $result = $this->reader()->read('clinic.example/');

        $this->assertTrue($result['ok']);
        $this->assertCount(3, $result['pages']);
        $this->assertStringContainsString('Whitening 1,500 EGP', $result['text']);
        $this->assertStringContainsString('Call 0100 000 0000', $result['text']);
        // A line repeated on two pages is kept once.
        $this->assertSame(1, substr_count($result['text'], 'Dental clinic in Nasr City'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'other.example') || str_contains($r->url(), '.pdf') || str_contains($r->url(), '/blog'));
    }

    public function test_it_never_reads_this_server_or_a_private_network(): void
    {
        foreach (['localhost', '127.0.0.1', '10.0.0.5', '192.168.1.1', '169.254.169.254', '[::1]', 'printer.local'] as $host) {
            $this->assertFalse(SiteReader::isPublicHost($host), $host);
        }
        Http::fake();
        $result = (new SiteReader)->read('http://169.254.169.254/latest/meta-data');
        $this->assertFalse($result['ok']);
        Http::assertNothingSent();
    }

    public function test_the_connection_goes_to_the_address_that_was_checked(): void
    {
        // A name that answers a public address to the check and a private one to a second lookup
        // (DNS rebinding) cannot send the request anywhere else: curl is told the checked address.
        $this->assertSame([CURLOPT_RESOLVE => ['clinic.example:443:203.0.113.7']], SiteReader::options('https://clinic.example/menu', '203.0.113.7')['curl']);
        $this->assertSame([CURLOPT_RESOLVE => ['clinic.example:8080:[2001:db8::7]']], SiteReader::options('http://clinic.example:8080/', '2001:db8::7')['curl']);
        $this->assertSame([CURLOPT_RESOLVE => ['clinic.example:80:203.0.113.7']], SiteReader::options('http://Clinic.example/', '203.0.113.7')['curl']);
        $this->assertFalse(SiteReader::options('https://clinic.example/', '203.0.113.7')['allow_redirects']);
        // An address typed as the host needs no pin.
        $this->assertArrayNotHasKey('curl', SiteReader::options('https://203.0.113.7/', '203.0.113.7'));
    }

    public function test_shared_and_reserved_ranges_are_not_public(): void
    {
        foreach (['100.64.0.1', '0.0.0.0', '::ffff:127.0.0.1', 'fe80::1', 'fd00::1', '192.0.0.8', '198.18.0.1'] as $ip) {
            $this->assertFalse(SiteReader::isPublicIp($ip), $ip);
            $this->assertNull(SiteReader::publicAddress($ip), $ip);
        }
        $this->assertTrue(SiteReader::isPublicIp('8.8.8.8'));
        $this->assertSame('8.8.8.8', SiteReader::publicAddress('8.8.8.8'));
    }

    public function test_a_redirect_to_a_private_address_is_not_followed(): void
    {
        Http::fake([
            'https://clinic.example/' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin']),
            '*' => Http::response('<p>secret</p>', 200, ['Content-Type' => 'text/html']),
        ]);

        $result = $this->reader()->read('https://clinic.example/');

        $this->assertFalse($result['ok']);
        Http::assertSentCount(1);
    }

    public function test_only_web_addresses_without_passwords_are_accepted(): void
    {
        $this->assertSame('https://clinic.example', SiteReader::normalizeUrl('clinic.example'));
        $this->assertNull(SiteReader::normalizeUrl('ftp://clinic.example'));
        $this->assertNull(SiteReader::normalizeUrl('https://user:pass@clinic.example'));
        $this->assertNull(SiteReader::normalizeUrl('   '));
    }

    public function test_a_page_with_a_megabyte_of_scripts_before_its_words_is_read(): void
    {
        // A page builder's page (a medical society's fees page): one style block of 1.4 MB, then the
        // words. The old pattern gave up on a block that long and the page had no words.
        $html = '<html><head><style>'.str_repeat('.e{color:red}', 110_000).'</style></head><body>'
            .'<script>var data = "'.str_repeat('x', 20_000).'";</script>'
            .'<nav><a href="/ar/">الرئيسية</a></nav><h2>رسوم العضوية</h2><ul><li>استشاري: 600 ريال سعودي</li><li>زميل / مقيم: 200 ريال سعودي</li></ul>'
            .'<svg><text>icon</text></svg><!-- '.str_repeat('c', 50_000).' --><footer>© 2026</footer></body></html>';
        $this->assertGreaterThan(1_500_000, strlen($html));

        $text = SiteReader::textOf($html);

        $this->assertStringContainsString('رسوم العضوية', $text);
        $this->assertStringContainsString('- استشاري: 600 ريال سعودي', $text);
        $this->assertStringContainsString('- زميل / مقيم: 200 ريال سعودي', $text);
        foreach (['color:red', 'xxxx', 'icon', 'cccc', 'الرئيسية', '©'] as $gone) {
            $this->assertStringNotContainsString($gone, $text);
        }
    }

    public function test_a_page_cut_at_its_size_limit_is_never_cut_inside_a_letter(): void
    {
        // Cut in the middle of an Arabic letter, the rest of the page could not be read at all.
        Http::fake(['https://clinic.example/*' => Http::response('<p>عربي</p>', 200, ['Content-Type' => 'text/html; charset=utf-8'])]);

        // 6 bytes end in the middle of the second letter (each takes two).
        $page = $this->reader()->download('https://clinic.example/fees', ['html'], 'text/html', 'ar', 6);

        $this->assertTrue(mb_check_encoding($page['body'], 'UTF-8'));
        $this->assertSame('<p>ع', $page['body']);
        $this->assertSame('ع', SiteReader::textOf($page['body']));
    }

    public function test_a_page_in_an_older_arabic_character_set_is_read_as_arabic(): void
    {
        $cp1256 = iconv('UTF-8', 'WINDOWS-1256', '<p>مواعيد العيادة</p>');
        Http::fake(['https://clinic.example/*' => Http::response($cp1256, 200, ['Content-Type' => 'text/html; charset=windows-1256'])]);

        $page = $this->reader()->download('https://clinic.example/hours');

        $this->assertSame('مواعيد العيادة', SiteReader::textOf($page['body']));
    }
}
