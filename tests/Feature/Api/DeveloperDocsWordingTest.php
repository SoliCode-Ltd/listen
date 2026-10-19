<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What the developer docs (/developers) say about rate-limit headers, errors
 * and audio formats matches what the API does, in English and Arabic.
 */
class DeveloperDocsWordingTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDeveloperApi();
    }

    private function docs(string $locale): \Illuminate\Testing\TestResponse
    {
        app()->setLocale($locale);

        return $this->getLocalizedRoute('developers')->assertOk();
    }

    public function test_the_rate_limit_is_for_the_whole_account_not_each_key(): void
    {
        $this->docs('en')
            ->assertSee('Requests your account may send a minute, all its keys together (generations and other requests are counted separately).')
            ->assertDontSee('Requests this key may send a minute.');
        $this->docs('ar')
            ->assertSee('عدد الطلبات المسموح بها لحسابك في الدقيقة، لكل مفاتيحه معًا')
            ->assertDontSee('لهذا المفتاح في الدقيقة');

        // Two keys of one member count against the same minute.
        $member = $this->apiMember();
        $first = $this->issueKey($member, null, null, 'First')['plain'];
        $second = $this->issueKey($member, null, null, 'Second')['plain'];
        $before = (int) $this->apiGet($first, '/voices')->assertOk()->headers->get('X-RateLimit-Remaining');
        $this->apiGet($second, '/voices')->assertOk()->assertHeader('X-RateLimit-Remaining', (string) ($before - 1));
    }

    public function test_the_docs_say_which_answers_carry_the_rate_limit_headers(): void
    {
        $this->docs('en')
            ->assertSee('Answers to requests that pass the key check carry <code dir="ltr">X-RateLimit-Limit</code> and <code dir="ltr">X-RateLimit-Remaining</code>, errors included; answers refused at the key check', false)
            ->assertDontSee('Every answer carries the headers below.');
        $this->docs('ar')
            ->assertSee('أما الاستجابات المرفوضة عند فحص المفتاح')
            ->assertDontSee('وتحمل كل استجابة الترويسات التالية');

        // An error after the key check carries them; an answer refused at the check does not.
        $plain = $this->issueKey($this->apiMember())['plain'];
        $this->assertApiError($this->apiGet($plain, '/voices/voice_999999'), 404, 'voice_not_found')
            ->assertHeader('X-RateLimit-Limit')
            ->assertHeader('X-RateLimit-Remaining');
        $this->assertApiError($this->apiGet('lk_live_not_a_real_key', '/voices'), 401, 'invalid_api_key')
            ->assertHeaderMissing('X-RateLimit-Limit')
            ->assertHeaderMissing('X-RateLimit-Remaining')
            ->assertHeader('X-Request-Id');
    }

    public function test_the_error_table_lists_an_unknown_output_format(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $this->docs($locale)->assertSee('<code dir="ltr">invalid_output_format</code>', false);
        }

        $member = $this->apiMember();
        $plain = $this->issueKey($member)['plain'];
        $generation = $this->storedGeneration($member);
        $this->assertApiError($this->apiGet($plain, '/history/'.$generation->id.'/audio?output_format=aiff'), 422, 'invalid_output_format', 'invalid_request_error', 'output_format');
    }

    public function test_mp3_is_always_accepted_but_may_come_back_as_wav(): void
    {
        $this->docs('en')
            ->assertSee('If the server\'s audio converter is unavailable, the audio may come back as WAV')
            ->assertDontSee('always works');
        $this->docs('ar')->assertSee('فقد يصلك الصوت بصيغة WAV');

        // A voice kept as WAV that cannot be turned into MP3 comes back as it was made, and says so.
        $member = $this->apiMember();
        $plain = $this->issueKey($member)['plain'];
        $generation = $this->storedGeneration($member);
        $path = 'audio/kept-as-wav.wav';
        Storage::disk('public')->put($path, 'RIFF'.pack('V', 4).'WAVE');
        $generation->update(['audio_path' => $path]);

        $response = $this->apiGet($plain, '/history/'.$generation->id.'/audio?output_format=mp3')->assertOk();
        $response->assertHeader('X-Output-Format', 'wav')->assertHeader('Content-Type', 'audio/wav');
        $this->assertStringStartsWith('RIFF', $response->getContent());
    }
}
