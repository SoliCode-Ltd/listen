<?php

namespace Tests\Feature\Api;

use App\Support\Api\ApiDocs;
use App\Support\Api\SampleWaveform;
use App\Support\AudioFormats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * How the developer docs (/developers) are laid out: four parts, the API
 * reference by resource (a resource with nothing to show is left out), every
 * old link still landing, and the request at the top with its true answer:
 * the recording's own length, credits and waveform, the rest of the page
 * agreeing with it.
 */
class DeveloperDocsLayoutTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDeveloperApi();
    }

    private function docs(string $locale): TestResponse
    {
        app()->setLocale($locale);

        return $this->getLocalizedRoute('developers')->assertOk();
    }

    public function test_the_page_reads_in_four_parts_and_the_reference_by_resource(): void
    {
        $page = $this->docs('en');
        $page->assertSeeInOrder(['Getting started', 'Guides', 'API reference', 'Errors, limits and support']);
        $page->assertSeeInOrder(['Text to speech', 'Voices and models', 'History', 'Account']);
        foreach (['api-speech', 'api-voices', 'api-history', 'api-account'] as $id) {
            $page->assertSee('id="'.$id.'"', false);
        }

        $this->docs('ar')->assertSeeInOrder(['البداية', 'الأدلة', 'مرجع الواجهة', 'الأخطاء والحدود والدعم']);
    }

    public function test_every_old_link_into_the_page_still_lands(): void
    {
        $html = (string) $this->docs('en')->getContent();
        foreach (['intro', 'quickstart', 'authentication', 'plans', 'credits', 'dialects', 'styles', 'plan-features', 'long-texts', 'formats',
            'safe-retries', 'retries', 'best-practices', 'text-to-speech', 'text-to-speech-async', 'list-voices', 'get-voice', 'voice-options',
            'list-models', 'list-history', 'get-history-item', 'get-history-audio', 'delete-history-item', 'get-subscription', 'get-user',
            'errors', 'rate-limits', 'versioning', 'support'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html, "#{$id} is still on the page");
        }
        preg_match_all('/href="#([a-z0-9-]+)"/', $html, $links);
        foreach (array_unique($links[1]) as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html, "the link to #{$id} lands");
        }
    }

    public function test_a_long_path_breaks_only_before_its_slashes(): void
    {
        $html = (string) $this->docs('en')->getContent();

        // In its resource's index and on the endpoint itself: a phone wraps it there, never cuts it.
        $this->assertSame(2, substr_count($html, '<code dir="ltr">/v1<wbr>/history<wbr>/{history_item_id}<wbr>/audio</code>')
            + substr_count($html, '<code>/v1<wbr>/history<wbr>/{history_item_id}<wbr>/audio</code>'));
        $this->assertStringNotContainsString('<wbr>/v1', $html, 'never before its first slash');
    }

    public function test_a_resource_with_no_endpoint_on_the_page_is_left_out(): void
    {
        $endpoints = [
            ['key' => 'tts', 'id' => 'text-to-speech'],
            ['key' => 'user', 'id' => 'get-user'],
            ['key' => 'subscription', 'id' => 'get-subscription'],
            ['key' => 'something_new', 'id' => 'something-new'],
        ];

        $groups = ApiDocs::resources($endpoints);

        $this->assertSame(['speech', 'account', 'other'], array_column($groups, 'key'));
        $this->assertSame(['get-subscription', 'get-user'], array_column($groups[1]['endpoints'], 'id'), 'in the order of RESOURCES');
        $this->assertSame(['something-new'], array_column($groups[2]['endpoints'], 'id'), 'an endpoint of no resource is never lost');
    }

    public function test_the_answer_at_the_top_is_the_recordings_own_and_the_page_agrees(): void
    {
        $voice = ['voice_id' => 'voice_7', 'name' => 'Maya', 'language' => 'en'];
        $hero = ['voice_id' => 'voice_7', 'body' => ['text' => 'Hi there, welcome back!', 'dialect' => 'en-US'], 'audio_url' => null, 'seconds' => 5, 'bytes' => 106605];

        $docs = ApiDocs::build('en', url('/v1'), $voice, null, $hero);
        $answer = strip_tags(html_entity_decode((string) $docs['hero_response']));

        $this->assertStringContainsString('Content-Length: 106605', $answer);
        $this->assertStringContainsString('X-Credits-Used: 75', $answer, '15 credits a second, 5 seconds');
        $this->assertStringContainsString('X-Audio-Duration-Seconds: 5', $answer);
        $this->assertStringNotContainsString('<audio/mpeg>', $answer, 'the player beside it is the body');
        $tts = collect($docs['endpoints'])->firstWhere('key', 'tts');
        $this->assertStringContainsString('X-Credits-Used: 75', strip_tags(html_entity_decode((string) $tts['response_html'])), 'the reference reads the same sentence the same way');

        // Without the recording's length: the reference's own answer, up to its body.
        $plain = strip_tags(html_entity_decode((string) ApiDocs::build('en', url('/v1'), $voice)['hero_response']));
        $this->assertStringContainsString('HTTP/1.1 200 OK', $plain);
        $this->assertStringNotContainsString('<audio/mpeg>', $plain);
    }

    public function test_the_waveform_is_the_recordings_own(): void
    {
        if (AudioFormats::ffmpegPath() === null) {
            $this->markTestSkipped('ffmpeg is not installed here.');
        }

        $bars = SampleWaveform::bars(public_path('audio/developers/ar-othman-egyptian.mp3'), 64);

        $this->assertIsArray($bars);
        $this->assertCount(64, $bars);
        $this->assertSame(8, min($bars), 'its pauses fall to the floor');
        $this->assertGreaterThanOrEqual(90, max($bars), 'its loudest words reach the top');
        $this->assertNull(SampleWaveform::bars(public_path('audio/developers/missing.mp3')));
    }
}
