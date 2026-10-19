<?php

namespace Tests\Feature;

use App\Jobs\GenerateAudioJob;
use App\Models\AudioGeneration;
use App\Models\ClonedVoice;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voice;
use App\Services\Billing\MemberUsageMeter;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\TtsRoutingService;
use App\Services\VoiceCloningService;
use App\Support\CreditPeriod;
use App\Support\GeminiTtsModel;
use App\Support\GenerationMessage;
use App\Support\VoiceCatalog;
use App\Support\VoiceCloningAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A premium or cloned voice that can't be made because of a limit on our side
 * that lasts (the provider's spending cap, every key out for the day, the
 * site's daily ceiling) is said plainly, with no wait promised, and every
 * credit comes back once. A passing overload still says busy. Speech is faked.
 */
class VoiceLimitOnOurSideTest extends TestCase
{
    use RefreshDatabase;

    private const SPENDING_CAP = ['error' => ['code' => 429, 'message' => 'Your project has exceeded its monthly spending cap.', 'status' => 'RESOURCE_EXHAUSTED']];

    private const DAILY = ['error' => ['code' => 429, 'message' => 'Rate limit exceeded for model gemini-3.8-flash-tts (limit: 100 requests per day on Tier 1).', 'status' => 'RESOURCE_EXHAUSTED']];

    /** An ordinary per-minute limit: it mentions billing too. */
    private const PER_MINUTE = ['error' => ['code' => 429, 'message' => 'You exceeded your current quota, please check your plan and billing details. Please retry in 20s.', 'status' => 'RESOURCE_EXHAUSTED']];

    /** What the member had already used this month, so a second refund would show. */
    private const USED_CREDITS = 3000;

    private const USED_SECONDS = 200;

    /** @var array<int, string> the key each premium request went out on */
    private array $premiumCalls = [];

    private int $standardCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        config([
            'services.gemini_tts.api_key' => '',
            'services.gemini_tts.retry_pause_ms' => 0,
            'services.gemini_tts.voice_retry_pause' => false,
            'services.google_tts.api_key' => 'test-google-key',
            'services.google_tts.endpoint' => 'https://texttospeech.googleapis.com/v1/text:synthesize',
        ]);
        SiteSetting::set('tts_mode', TtsRoutingService::MODE_HYBRID, 'tts');

        // The site's two keys; everything else (setting keys aside) is the real manager.
        $manager = \Mockery::mock(GeminiKeyManager::class)->makePartial();
        $manager->shouldReceive('getAllKeys')->andReturn([
            ['key' => 'k1', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1],
            ['key' => 'k2', 'name' => 'GEMINI_TTS_API_KEY_2', 'index' => 2],
        ]);
        $this->app->instance(GeminiKeyManager::class, $manager);
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_a_spending_cap_is_said_plainly_and_every_credit_comes_back_once(): void
    {
        $this->premiumReplies(self::SPENDING_CAP, 429);
        $user = $this->member();

        $message = $this->generate($user, $this->premiumVoiceKey())
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('job.status', 'failed')
            ->json('message');

        $this->assertSame(trans('ui.backend.premium_voices_our_limit', [], 'en'), $message);
        $this->assertStringNotContainsStringIgnoringCase('minute', $message);
        $generation = AudioGeneration::query()->latest('id')->firstOrFail();
        $this->assertSame(GenerationMessage::stored('ui.backend.premium_voices_our_limit'), $generation->error_message);
        $this->assertSame(['k1', 'k2'], $this->premiumCalls, 'each key once, not sent again');
        $this->assertSame(0, $this->standardCalls, 'never read by a standard voice');

        // A capped key is set aside on every model, cloned voices' too.
        $keys = app(GeminiKeyManager::class);
        foreach (['GEMINI_TTS_API_KEY', 'GEMINI_TTS_API_KEY_2'] as $name) {
            $this->assertTrue($keys->isOutForToday($name, GeminiTtsModel::resolve()));
            $this->assertTrue($keys->isOutForToday($name, VoiceCloningService::MODEL));
        }

        $this->assertCreditsGivenBackOnce($user, $generation);
    }

    public function test_the_member_is_told_in_arabic_with_no_wait_promised(): void
    {
        $this->premiumReplies(self::SPENDING_CAP, 429);
        $user = $this->member();
        app()->setLocale('ar');

        $message = $this->generate($user, $this->premiumVoiceKey())
            ->assertStatus(422)
            ->assertJsonPath('job.error_message', trans('ui.backend.premium_voices_our_limit', [], 'ar'))
            ->json('message');

        $this->assertSame(trans('ui.backend.premium_voices_our_limit', [], 'ar'), $message);
        $this->assertStringNotContainsString('دقيقة', $message);
        $this->assertStringNotContainsString('دقائق', $message);
    }

    public function test_every_key_out_for_the_day_and_the_sites_daily_ceiling_say_the_same(): void
    {
        $this->premiumReplies(self::DAILY, 429);
        $user = $this->member();

        $this->generate($user, $this->premiumVoiceKey())
            ->assertStatus(422)
            ->assertJsonPath('message', trans('ui.backend.premium_voices_our_limit', [], 'en'));

        // Over the site's own daily ceiling nothing is sent at all.
        $this->premiumCalls = [];
        config(['listen.cost_guardrails.daily_gemini_char_limit' => 5]);
        $this->generate($user, $this->premiumVoiceKey())
            ->assertStatus(422)
            ->assertJsonPath('message', trans('ui.backend.premium_voices_our_limit', [], 'en'));

        $this->assertSame([], $this->premiumCalls);
        $this->assertSame(self::USED_CREDITS, (int) CreditPeriod::counter((int) $user->id)->total_chars, 'both given back');
    }

    public function test_a_passing_overload_still_says_busy_and_no_key_is_set_aside(): void
    {
        foreach ([[self::PER_MINUTE, 429], [['error' => ['message' => 'The model is overloaded.']], 503]] as [$body, $status]) {
            $this->premiumReplies($body, $status);
            $user = $this->member();

            $this->generate($user, $this->premiumVoiceKey())
                ->assertStatus(422)
                ->assertJsonPath('message', trans('ui.backend.premium_voices_busy', [], 'en'));

            $this->assertSame(self::USED_CREDITS, (int) CreditPeriod::counter((int) $user->id)->total_chars, $status.': credits given back');
            $this->assertFalse(app(GeminiKeyManager::class)->isOutForToday('GEMINI_TTS_API_KEY', GeminiTtsModel::resolve()), $status.': only for the minute');
        }
    }

    public function test_a_cloned_voice_stopped_by_the_spending_cap_says_so_in_both_languages(): void
    {
        $this->premiumReplies(self::SPENDING_CAP, 429);
        $user = $this->member();
        $voice = $this->clonedVoice($user);

        $message = $this->generate($user, 'cloned_'.$voice->id, 'ar')
            ->assertStatus(422)
            ->assertJsonPath('job.status', 'failed')
            ->json('message');

        $this->assertSame(trans('ui.backend.cloned_voices_our_limit', [], 'en'), $message);
        $this->assertStringNotContainsStringIgnoringCase('minute', $message);
        $this->assertStringNotContainsStringIgnoringCase('studio voices', $message, 'the stock voices are held back too');
        $generation = AudioGeneration::query()->latest('id')->firstOrFail();
        $this->assertSame(GenerationMessage::stored('ui.backend.cloned_voices_our_limit'), $generation->error_message);
        $this->assertCreditsGivenBackOnce($user, $generation);

        app()->setLocale('ar');
        $arabic = $this->generate($user, 'cloned_'.$voice->id, 'ar')->assertStatus(422)->json('message');
        $this->assertSame(trans('ui.backend.cloned_voices_our_limit', [], 'ar'), $arabic);
        $this->assertStringNotContainsString('دقيقة', $arabic);
        $this->assertStringNotContainsString('دقائق', $arabic);
    }

    public function test_a_cloned_voice_on_a_busy_minute_still_says_busy(): void
    {
        $this->premiumReplies(self::PER_MINUTE, 429);
        $user = $this->member();
        $voice = $this->clonedVoice($user);

        $this->generate($user, 'cloned_'.$voice->id, 'ar')
            ->assertStatus(422)
            ->assertJsonPath('message', trans('ui.backend.premium_voices_busy', [], 'en'));

        $this->assertSame(self::USED_CREDITS, (int) CreditPeriod::counter((int) $user->id)->total_chars);
        $this->assertFalse(app(GeminiKeyManager::class)->isOutForToday('GEMINI_TTS_API_KEY', VoiceCloningService::MODEL));
    }

    /**
     * The credits are back to what the member had used before, and failing the
     * generation again (the job's failed() hook, the request, the sweep) gives
     * nothing back a second time.
     */
    private function assertCreditsGivenBackOnce(User $user, AudioGeneration $generation): void
    {
        $this->assertFalse(app(MemberUsageMeter::class)->failAndRefund((int) $generation->id, 'Failed again.'));
        (new GenerateAudioJob((int) $generation->id))->failed(new \RuntimeException('A late failure.'));

        $counter = CreditPeriod::counter((int) $user->id);
        $this->assertSame(self::USED_CREDITS, (int) $counter->total_chars, 'credits given back once');
        $this->assertSame(1, (int) $counter->total_requests, 'the generation given back once');
        $this->assertSame(self::USED_SECONDS, (int) $counter->total_seconds, 'seconds given back once');
    }

    /**
     * Every premium request (stock voices, cloned voices and their copies) gets
     * this reply; a standard voice is counted, never expected.
     *
     * @param  array<string, mixed>  $body
     */
    private function premiumReplies(array $body, int $status): void
    {
        $this->premiumCalls = [];
        Http::fake(function ($request) use ($body, $status) {
            if (str_contains($request->url(), 'texttospeech')) {
                $this->standardCalls++;

                return Http::response(['audioContent' => base64_encode('standard-audio')]);
            }
            $this->premiumCalls[] = $request->header('x-goog-api-key')[0] ?? '';

            return Http::response($body, $status);
        });
    }

    private function generate(User $user, string $voiceKey, string $language = 'en'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => $language === 'ar' ? 'مرحبا بكم في المنصة' : 'Hello there',
            'target_language' => $language,
            'voice_tier' => 'hd',
            'voice_key' => $voiceKey,
        ]);
    }

    /** A paid member with premium voices and voice cloning, who has used some credits this month. */
    private function member(): User
    {
        $plan = Plan::query()->where('slug', 'studio-hd')->first() ?? Plan::factory()->create([
            'slug' => 'studio-hd',
            'price_cents' => 1999,
            'voice_tier' => 'hd',
            'monthly_chars' => 100000,
            'monthly_requests' => 0,
            'max_chars_per_request' => 5000,
            'voice_cloning_enabled' => true,
            'voice_cloning_max_voices' => 5,
            'is_active' => true,
        ]);
        $user = User::query()->firstOrCreate(
            ['email' => VoiceCloningAccess::PREVIEW_EMAIL],
            User::factory()->raw(['email' => VoiceCloningAccess::PREVIEW_EMAIL, 'subscription_status_checked_at' => now()]),
        );
        Subscription::query()->firstOrCreate(['user_id' => $user->id], ['plan_id' => $plan->id, 'status' => 'active']);

        $counter = CreditPeriod::counter((int) $user->id);
        $counter->update(['total_chars' => self::USED_CREDITS, 'total_requests' => 1, 'total_seconds' => 0, 'total_minutes' => 0]);
        $counter->addSeconds(self::USED_SECONDS);

        return $user;
    }

    private function premiumVoiceKey(): string
    {
        $voice = Voice::query()->firstOrCreate(['internal_voice_id' => 'en-Gemini-HD-Kore'], [
            'language' => 'en', 'gender' => 'female', 'tier' => 'hd', 'display_name' => 'Daisy',
            'provider' => 'gemini', 'provider_voice_id' => 'Kore', 'is_active' => true,
        ]);

        return VoiceCatalog::keyFromId((int) $voice->id);
    }

    /** A finished cloned voice made on the first key, with its recordings kept for copies. */
    private function clonedVoice(User $user): ClonedVoice
    {
        Storage::disk('local')->put('voices/source.wav', 'RIFF....WAVEsource');
        Storage::disk('local')->put('voices/consent.wav', 'RIFF....WAVEconsent');

        return ClonedVoice::factory()->ready()->forUser($user)->create([
            'provider' => 'gemini',
            'provider_voice_key' => 'voice_x',
            'language_code' => 'ar-XA',
            'metadata' => ['key_name' => 'GEMINI_TTS_API_KEY'],
            'source_audio_path' => 'voices/source.wav',
            'consent_audio_path' => 'voices/consent.wav',
        ]);
    }
}
