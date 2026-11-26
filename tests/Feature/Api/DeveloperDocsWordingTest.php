<?php

namespace Tests\Feature\Api;

use App\Support\Api\ApiCodeSamples;
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

    public function test_quota_exceeded_also_answers_the_monthly_request_limit(): void
    {
        $this->docs('en')->assertSee('You do not have enough credits left this month, or your plan\'s monthly generation limit is reached.');
        $this->docs('ar')->assertSee('لا تكفي أرصدتك المتبقية هذا الشهر، أو بلغت باقتك حدها الشهري من عمليات التوليد.');
        $spec = (string) json_encode($this->getJson('/v1/openapi.json')->assertOk()->json());
        $this->assertStringContainsString("or the plan's monthly generation limit is reached.", $spec);

        // With credits left, the plan's last generation of the month is the last one.
        $plain = $this->issueKey($this->apiMember($this->apiPlan(['monthly_requests' => 1])))['plain'];
        $voice = $this->premiumVoice();
        $this->speak($plain, $voice, ['text' => 'First of the month.'])->assertOk();
        $this->assertApiError($this->speak($plain, $voice, ['text' => 'Second of the month.']), 402, 'quota_exceeded', 'quota_error');
    }

    /** Claude is named on this page only, by the owner's choice (CLAUDE.md): the steps to connect Listen in it. */
    public function test_the_page_says_how_to_connect_in_claude_in_both_languages(): void
    {
        $address = url('/mcp');
        $this->docs('en')
            ->assertSee('Connect in Claude')
            ->assertSee('<strong class="docs-ui" dir="auto">Add custom connector</strong>', false)
            ->assertSee('<strong class="docs-ui" dir="auto">Register automatically</strong>', false)
            ->assertSeeText('claude mcp add --transport http listen')
            ->assertSee('paste <code dir="ltr">'.$address.'</code> as the address', false)
            ->assertSee('If something does not work');
        $this->docs('ar')
            ->assertSee('الربط في Claude')
            // Arabic first, then the button's name as Claude's screen shows it.
            ->assertSee('<strong class="docs-ui" dir="auto">إضافة موصّل مخصص</strong> <span class="docs-ui-as" dir="ltr">(Add custom connector)</span>', false)
            ->assertSee('<strong class="docs-ui" dir="auto">إسمع</strong>', false)
            ->assertSee('الربط في Claude Code')
            ->assertSee('إذا لم يعمل شيء');

        // Nowhere else: the home page and the pricing page never name it.
        foreach (['home', 'pricing'] as $route) {
            $this->assertStringNotContainsString('Claude', (string) $this->getLocalizedRoute($route)->assertOk()->getContent());
        }
    }

    /** What the page says about waiting matches the answers: a failed generation's audio, and the refusals that pass. */
    public function test_the_answers_that_waiting_solves_say_how_long_and_a_failed_generation_says_so(): void
    {
        $this->docs('en')
            ->assertSee('audio_failed')
            ->assertSee('A <code dir="ltr">409</code> answer means something is still running', false)
            ->assertDontSee('Requests a month');
        $this->docs('ar')->assertSee('audio_failed');
        // The plans table's monthly row counts generations (shared with the studio), not requests.
        $this->assertSame('Generations a month', __('developers.docs.plans.row_monthly_requests', [], 'en'));
        $this->assertSame('عمليات التوليد في الشهر', __('developers.docs.plans.row_monthly_requests', [], 'ar'));

        $busy = \App\Support\Transcription\TranscriptionApi::error(new \App\Services\Transcription\TranscriptionRefused('busy', 'Busy.', 429));
        $this->assertSame('too_many_transcriptions', $busy->errorCode);
        $this->assertSame(['Retry-After' => '30'], $busy->headers);
        $site = \App\Support\Transcription\TranscriptionApi::error(new \App\Services\Transcription\TranscriptionRefused('site_busy', 'Busy.', 503));
        $this->assertSame(['Retry-After' => '30'], $site->headers);
        // A limit that waiting a little does not lift sends none.
        $limit = \App\Support\Transcription\TranscriptionApi::error(new \App\Services\Transcription\TranscriptionRefused('translation_limit', 'Limit.', 429));
        $this->assertSame([], $limit->headers);
    }

    public function test_the_javascript_samples_say_to_save_them_as_an_es_module(): void
    {
        // They use top-level await (and import, when they save the audio): a plain .js file runs neither by default.
        foreach ([ApiCodeSamples::all('GET', 'https://listen.example/v1/voices'), ApiCodeSamples::all('POST', 'https://listen.example/v1/text-to-speech/voice_1', ['text' => 'Hi'], 'speech.mp3')] as $samples) {
            $this->assertStringStartsWith("// Node.js 20 or later (fetch is built in). Save it as a .mjs file: it is an ES module.\n", $samples['javascript']);
            $this->assertStringContainsString('const response = await fetch(', $samples['javascript']);
        }
        $this->assertStringNotContainsString('Node.js 18', (string) $this->docs('en')->getContent());
        $this->docs('en')->assertSee('Save it as a .mjs file: it is an ES module.');
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

    public function test_the_machine_readable_spec_says_the_same(): void
    {
        $spec = (string) json_encode($this->getJson('/v1/openapi.json')->assertOk()->json());

        $this->assertStringContainsString('Requests your account may send a minute, all its keys together', $spec);
        $this->assertStringNotContainsString('Requests allowed a minute.', $spec);
        $this->assertStringContainsString('the audio may come back as WAV', $spec);
        $this->assertStringNotContainsString('mp3 is always available', $spec);
    }
}
