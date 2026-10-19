<?php

namespace Tests\Feature\Api;

use App\Models\AudioGeneration;
use App\Models\SiteSetting;
use App\Models\TextItem;
use App\Models\Voice;
use App\Services\Tts\TtsRoutingService;
use App\Support\Api\ApiModels;
use App\Support\AudioFormats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * POST /v1/text-to-speech/{voice_id}, waiting for the file: the answer is
 * the audio itself with the documented headers, the generation is charged
 * 15 credits for each second of the audio it really made (to the account and
 * to the key) and kept in history with the key that asked for it. A request
 * that is refused, for any reason, costs nothing.
 */
class ApiTextToSpeechTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
    }

    /**
     * The body is the stored audio file, described by its headers: what it
     * is, which history item it is, its length and what it cost.
     */
    public function test_a_premium_voice_answers_with_the_audio_and_the_documented_headers(): void
    {
        $plain = $this->issueKey($this->apiMember())['plain'];
        $voice = $this->premiumVoice();

        $response = $this->speak($plain, $voice)->assertOk();

        $generation = AudioGeneration::query()->sole();
        // HD audio is stored as MP3 when the server has ffmpeg (it always does live).
        $format = AudioFormats::serverCanConvert() ? 'mp3' : 'wav';
        $this->assertSame(Storage::disk('public')->get($generation->audio_path), $response->getContent());
        $response->assertHeader('Content-Type', AudioFormats::ALL[$format]['mime'])
            ->assertHeader('X-Output-Format', $format)
            ->assertHeader('Content-Length', (string) strlen($response->getContent()))
            ->assertHeader('Content-Disposition', 'inline; filename="listen-'.$generation->id.'.'.$format.'"')
            ->assertHeader('X-History-Item-Id', (string) $generation->id)
            ->assertHeader('X-Character-Count', '28')
            ->assertHeader('X-Audio-Duration-Seconds', '4')
            ->assertHeader('X-Credits-Used', '60')
            ->assertHeader('X-Credits-Remaining', '89940')
            ->assertHeader('X-RateLimit-Limit', '60')
            ->assertHeader('X-RateLimit-Remaining', '59')
            ->assertHeaderMissing('Idempotent-Replayed');
        $this->assertStringStartsWith('req_', (string) $response->headers->get('X-Request-Id'));
        $this->assertSame(1, $this->speechCalls->gemini, 'one recording was asked for');
        $this->assertSame([], $this->speechCalls->other);
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function audioLengths(): array
    {
        return [
            'shorter than the estimate' => [2, 30],
            'as long as the estimate' => [3, 45],
            'longer than the estimate' => [7, 105],
        ];
    }

    /**
     * Credits are reserved from the text (3 seconds, 45 credits, for this one)
     * and then corrected to the audio really made: 15 credits a second, on the
     * account's usage and on the key's own monthly figures alike.
     */
    #[DataProvider('audioLengths')]
    public function test_the_charge_is_15_credits_for_each_second_of_the_audio_really_made(int $seconds, int $credits): void
    {
        $this->audioSeconds = $seconds;
        $user = $this->apiMember();
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user);

        $this->speak($plain, $this->premiumVoice())
            ->assertOk()
            ->assertHeader('X-Audio-Duration-Seconds', (string) $seconds)
            ->assertHeader('X-Credits-Used', (string) $credits)
            ->assertHeader('X-Credits-Remaining', (string) (90000 - $credits));

        $this->assertSame(45, AudioGeneration::query()->sole()->settings_snapshot['reservation']['credits'], 'reserved from the text');
        $this->assertSame($credits, $this->creditsSpent($user));
        $this->assertSame($seconds, $this->secondsCounted($user));
        $this->assertSame(1, $this->requestsCounted($user));
        $this->assertSame($credits, $this->keyCreditsSpent($key));
        $this->assertSame(1, $this->keyRequestsCounted($key));
    }

    /**
     * Several generations add up, each charged once.
     */
    public function test_each_generation_is_charged_once_and_they_add_up(): void
    {
        $user = $this->apiMember();
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user);
        $voice = $this->premiumVoice();

        $this->speak($plain, $voice, ['text' => 'The first sentence.'])->assertOk();
        $this->speak($plain, $voice, ['text' => 'The second sentence.'])->assertOk()->assertHeader('X-Credits-Remaining', '89880');

        $this->assertSame(2, AudioGeneration::query()->count());
        $this->assertSame([120, 2, 8], [$this->creditsSpent($user), $this->requestsCounted($user), $this->secondsCounted($user)]);
        $this->assertSame([120, 2], [$this->keyCreditsSpent($key), $this->keyRequestsCounted($key)]);
    }

    /**
     * The generation is in the member's history, marked with the key that
     * made it, the text, the voice and the options it was made with.
     */
    public function test_the_generation_is_kept_in_history_with_the_key_that_made_it(): void
    {
        $user = $this->apiMember();
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user);
        $voice = $this->premiumVoice();

        $this->speak($plain, $voice, ['title' => 'Welcome line'])->assertOk();

        $generation = AudioGeneration::query()->sole();
        $this->assertSame($user->id, (int) $generation->user_id);
        $this->assertSame($key->id, $generation->api_key_id);
        $this->assertSame('done', $generation->status);
        $this->assertSame('hd', $generation->voice_tier);
        $this->assertSame('en-Gemini-HD-Kore', $generation->voice_name);
        $this->assertSame('en', $generation->target_language);
        $this->assertSame('Hello there, this is a test.', $generation->input_text);
        $this->assertSame(28, $generation->billable_chars);
        $this->assertSame(4, $generation->duration_seconds);
        $this->assertFalse($generation->billed_to_pack);
        $item = TextItem::query()->sole();
        $this->assertSame(['api', 'Welcome line', 'done'], [$item->source_type, $item->title, $item->status]);
        $this->assertSame($item->id, (int) $generation->text_item_id);
    }

    /**
     * Studio options sent with the request are the ones the generation is
     * made with, and the history item shows them.
     */
    public function test_studio_options_sent_are_used_for_the_generation(): void
    {
        $plain = $this->issueKey($this->apiMember())['plain'];
        $voice = $this->premiumVoice();

        $id = $this->speak($plain, $voice, [
            'model_id' => ApiModels::PREMIUM,
            'language_code' => 'en',
            'dialect' => 'en-GB',
            'style' => 'news',
            'emotion' => 'calm',
            'voice_settings' => ['speed' => 1.25, 'pitch' => -2],
        ])->assertOk()->headers->get('X-History-Item-Id');

        $item = $this->apiGet($plain, '/history/'.$id)->assertOk()->json();
        $this->assertSame(['en', 'en-GB', 'news', 'calm'], [$item['language_code'], $item['dialect'], $item['style'], $item['emotion']]);
        $this->assertEquals(['speed' => 1.25, 'pitch' => -2.0], $item['voice_settings']);
    }

    /**
     * An Arabic voice reads Arabic text in the site's default dialect, style
     * and emotion when none is asked for.
     */
    public function test_an_arabic_voice_reads_arabic_text_with_the_sites_defaults(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->arabicVoice();

        $this->speak($plain, $voice, ['text' => 'مرحبا بكم في هذا الاختبار'])->assertOk()->assertHeader('X-Credits-Used', '60');

        $generation = AudioGeneration::query()->sole();
        $this->assertSame(['ar', 'ar', 'ar-EG', 'promo', 'confident'], [
            $generation->source_language, $generation->target_language, $generation->dialect,
            $generation->performance_style, $generation->emotion,
        ]);
        $this->assertSame(60, $this->creditsSpent($user));
    }

    /**
     * With the site's premium voices on Google, the audio comes from Google
     * and is charged by its real length just the same.
     */
    public function test_with_the_site_on_google_premium_voices_the_audio_comes_from_google(): void
    {
        SiteSetting::set('tts_mode', TtsRoutingService::MODE_GOOGLE, 'tts');
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = Voice::query()->create([
            'language' => 'en', 'gender' => 'male', 'tier' => 'hd', 'display_name' => 'Charlie',
            'internal_voice_id' => 'en-US-Chirp3-HD-Charon', 'provider' => 'google_cloud',
            'provider_voice_id' => 'en-US-Chirp3-HD-Charon', 'is_active' => true,
        ]);

        $response = $this->speak($plain, $voice)->assertOk();

        $response->assertHeader('Content-Type', 'audio/mpeg')
            ->assertHeader('X-Output-Format', 'mp3')
            ->assertHeader('X-Audio-Duration-Seconds', '4')
            ->assertHeader('X-Credits-Used', '60');
        $this->assertSame(self::mp3Seconds(4), $response->getContent());
        $this->assertSame([1, 0], [$this->speechCalls->google, $this->speechCalls->gemini]);
        $this->assertSame(60, $this->creditsSpent($user));
    }

    /**
     * output_format can be given in the query string or in the body.
     */
    public function test_the_output_format_can_be_given_in_the_query_or_the_body(): void
    {
        $plain = $this->issueKey($this->apiMember())['plain'];
        $voice = $this->premiumVoice();

        $this->apiPost($plain, '/text-to-speech/'.self::voiceId($voice).'?output_format=mp3', ['text' => 'From the query string.'])
            ->assertOk()->assertHeader('X-Output-Format', AudioFormats::serverCanConvert() ? 'mp3' : 'wav');
        $this->speak($plain, $voice, ['text' => 'From the body.', 'output_format' => 'mp3'])
            ->assertOk()->assertHeader('X-Output-Format', AudioFormats::serverCanConvert() ? 'mp3' : 'wav');
    }

    /**
     * A plan that includes WAV gets WAV when it asks for it, charged as usual.
     */
    public function test_a_plan_with_wav_gets_wav_audio(): void
    {
        if (! AudioFormats::serverCanConvert()) {
            $this->markTestSkipped('ffmpeg is not installed here.');
        }
        $user = $this->apiMember($this->apiPlan(['download_formats' => ['mp3', 'wav']]));
        $plain = $this->issueKey($user)['plain'];

        $response = $this->speak($plain, $this->premiumVoice(), ['output_format' => 'wav'])->assertOk();

        $response->assertHeader('Content-Type', 'audio/wav')->assertHeader('X-Output-Format', 'wav');
        $this->assertStringStartsWith('RIFF', $response->getContent());
        $this->assertSame(60, $this->creditsSpent($user));
    }

    /**
     * A plan with custom direction may send style_prompt, and it is used.
     */
    public function test_a_plan_with_custom_direction_may_send_a_style_prompt(): void
    {
        $plain = $this->issueKey($this->apiMember($this->apiPlan(['custom_prompt_enabled' => true])))['plain'];

        $this->speak($plain, $this->premiumVoice(), ['style_prompt' => 'Like a late-night radio host.'])->assertOk();

        $this->assertSame('Like a late-night radio host.', AudioGeneration::query()->sole()->style_prompt);
    }

    /**
     * On a plan with dialogue, several studio voices speak in one recording,
     * made and charged like any other generation.
     */
    public function test_a_plan_with_dialogue_makes_a_dialogue_and_charges_it_like_any_generation(): void
    {
        $user = $this->apiMember($this->apiPlan(['dialogue_enabled' => true]));
        $plain = $this->issueKey($user)['plain'];
        [$maya, $omar] = [$this->premiumVoice(), $this->secondEnglishVoice()];

        $id = $this->speak($plain, $maya, $this->dialogueBody($maya, $omar))
            ->assertOk()
            ->assertHeader('X-Credits-Used', '60')
            ->headers->get('X-History-Item-Id');

        $generation = AudioGeneration::query()->sole();
        $this->assertTrue($generation->is_dialogue);
        $this->assertSame([['name' => 'Sara', 'voice' => self::voiceId($maya)], ['name' => 'Omar', 'voice' => self::voiceId($omar)]], $generation->dialogue_metadata['speakers']);
        $this->assertSame(60, $this->creditsSpent($user));
        $this->apiGet($plain, '/history/'.$id)->assertJsonPath('dialogue', true);
    }

    /**
     * A dialogue needs two to six studio voices of the text's language and
     * lines that start with their names, and carries up to 2,500 characters;
     * otherwise it is refused before anything is charged.
     */
    public function test_a_dialogue_that_does_not_fit_together_is_refused_and_not_charged(): void
    {
        $user = $this->apiMember($this->apiPlan(['dialogue_enabled' => true]));
        $plain = $this->issueKey($user)['plain'];
        [$maya, $omar] = [$this->premiumVoice(), $this->secondEnglishVoice()];
        $arabic = $this->arabicVoice();
        $speak = fn (array $changes) => $this->speak($plain, $maya, array_replace_recursive($this->dialogueBody($maya, $omar), $changes));

        $this->assertApiError($speak(['dialogue' => ['speakers' => [1 => ['voice_id' => 'voice_999999']]]]), 422, 'invalid_dialogue', 'invalid_request_error', 'dialogue.speakers.1.voice_id');
        $this->assertApiError($speak(['dialogue' => ['speakers' => [1 => ['voice_id' => self::voiceId($arabic)]]]]), 422, 'invalid_dialogue', null, 'dialogue.speakers.1.voice_id');
        $this->assertApiError($speak(['text' => 'Nobody is named in this text.']), 422, 'invalid_dialogue', null, 'text');
        $this->assertApiError($speak(['text' => "Sara: ".str_repeat('a', 2600)]), 422, 'text_too_long', null, 'text')
            ->assertJsonPath('error.max_characters', 2500);
        $this->assertApiError($this->speak($plain, $maya, [
            'text' => 'Sara: Alone.',
            'dialogue' => ['speakers' => [['name' => 'Sara', 'voice_id' => self::voiceId($maya)]]],
        ]), 422, 'validation_failed', null, 'dialogue.speakers');
        $this->assertNothingCharged($user);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: int, 2: string, 3: string}>
     */
    public static function invalidRequests(): array
    {
        return [
            'empty text' => [['text' => ''], 422, 'validation_failed', 'text'],
            'text that is not a string' => [['text' => ['Hello']], 422, 'validation_failed', 'text'],
            'only sound tags, no words' => [['text' => '<laugh> <sigh>'], 422, 'text_has_no_words', 'text'],
            'another model' => [['model_id' => 'some_other_model_v2'], 422, 'validation_failed', 'model_id'],
            'an unknown language' => [['language_code' => 'fr'], 422, 'validation_failed', 'language_code'],
            'a language the voice does not speak' => [['language_code' => 'ar'], 422, 'language_mismatch', 'language_code'],
            'speed above 2' => [['voice_settings' => ['speed' => 2.5]], 422, 'validation_failed', 'voice_settings.speed'],
            'speed below 0.5' => [['voice_settings' => ['speed' => 0.4]], 422, 'validation_failed', 'voice_settings.speed'],
            'speed that is not a number' => [['voice_settings' => ['speed' => 'fast']], 422, 'validation_failed', 'voice_settings.speed'],
            'pitch above 10' => [['voice_settings' => ['pitch' => 11]], 422, 'validation_failed', 'voice_settings.pitch'],
            'an unknown output format' => [['output_format' => 'aiff'], 422, 'validation_failed', 'output_format'],
            'an unknown dialect' => [['dialect' => 'en-ZZ'], 422, 'invalid_dialect', 'dialect'],
            'an unknown style' => [['style' => 'shouting-ish'], 422, 'invalid_style', 'style'],
            'an unknown emotion' => [['emotion' => 'grumpy-ish'], 422, 'invalid_emotion', 'emotion'],
            'an unknown sound environment' => [['audio_effect' => 'moon'], 422, 'invalid_audio_effect', 'audio_effect'],
            'a title over 255 characters' => [['title' => str_repeat('t', 256)], 422, 'validation_failed', 'title'],
        ];
    }

    /**
     * Each invalid request gets the documented status, code and param (with
     * the rate-limit headers, as every answer to a valid key), and nothing is
     * generated or charged.
     *
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('invalidRequests')]
    public function test_an_invalid_request_is_refused_and_not_charged(array $body, int $status, string $code, string $param): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];

        $this->assertApiError($this->speak($plain, $this->premiumVoice(), $body), $status, $code, 'invalid_request_error', $param)
            ->assertHeader('X-RateLimit-Limit', '60')
            ->assertHeader('X-RateLimit-Remaining', '59');
        $this->assertNothingCharged($user);
    }

    /**
     * A request with no text at all is told the text is required.
     */
    public function test_a_request_without_text_is_refused_with_422_and_not_charged(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];

        $response = $this->apiPost($plain, '/text-to-speech/'.self::voiceId($this->premiumVoice()), ['title' => 'No text']);

        $this->assertApiError($response, 422, 'validation_failed', 'invalid_request_error', 'text')
            ->assertJsonPath('error.message', 'The text field is required.')
            ->assertJsonPath('error.errors.text.0', 'The text field is required.');
        $this->assertNothingCharged($user);
    }

    /**
     * A voice id the member cannot use is 404 voice_not_found, whatever it
     * is: unknown, a standard voice (the API offers the premium ones only),
     * switched off, or not a voice id at all.
     */
    public function test_an_unknown_voice_is_refused_with_404_and_not_charged(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $standard = Voice::query()->create([
            'language' => 'en', 'gender' => 'female', 'tier' => 'standard', 'display_name' => 'Emma',
            'internal_voice_id' => 'en-US-Wavenet-C', 'provider' => 'google_cloud',
            'provider_voice_id' => 'en-US-Wavenet-C', 'is_active' => true,
        ]);
        $off = $this->premiumVoice(['is_active' => false]);

        foreach (['voice_999999', self::voiceId($standard), self::voiceId($off), 'Kore', 'voice_abc'] as $voiceId) {
            $this->assertApiError($this->speak($plain, $voiceId), 404, 'voice_not_found', 'invalid_request_error', 'voice_id');
        }
        $this->assertNothingCharged($user);
    }

    /**
     * A waiting request carries up to 5,000 characters; a longer one is
     * pointed to the background endpoint, with both limits, and not charged.
     */
    public function test_a_text_over_the_waiting_limit_is_refused_with_422_text_too_long(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];

        $response = $this->speak($plain, $this->premiumVoice(), ['text' => str_repeat('a', 5001)]);

        $this->assertApiError($response, 422, 'text_too_long', 'invalid_request_error', 'text')
            ->assertJsonPath('error.max_characters', 5000)
            ->assertJsonPath('error.max_characters_async', 10000);
        $this->assertStringContainsString('/async', (string) $response->json('error.message'));
        $this->assertNothingCharged($user);
    }

    /**
     * Exactly 5,000 characters is still accepted, and charged by its audio.
     */
    public function test_a_text_of_exactly_the_waiting_limit_is_accepted(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $text = trim(str_repeat('Every word here is read aloud. ', 162)).' The end of it.';
        $text = mb_substr($text, 0, 5000);
        $this->assertSame(5000, mb_strlen(trim($text)));

        $this->speak($plain, $this->premiumVoice(), ['text' => $text])->assertOk();

        $generation = AudioGeneration::query()->sole();
        $this->assertSame(5000, $generation->billable_chars);
        $this->assertSame($generation->duration_seconds * 15, $this->creditsSpent($user));
    }

    /**
     * A plan's own lower limit applies to the API too.
     */
    public function test_a_text_over_the_plans_own_limit_is_refused_with_422_text_too_long(): void
    {
        $user = $this->apiMember($this->apiPlan(['max_chars_per_request' => 1000]));
        $plain = $this->issueKey($user)['plain'];

        $response = $this->speak($plain, $this->premiumVoice(), ['text' => str_repeat('b', 1001)]);

        $this->assertApiError($response, 422, 'text_too_long', null, 'text')
            ->assertJsonPath('error.max_characters', 1000);
        $this->assertNull($response->json('error.max_characters_async'), 'the background endpoint would not take it either');
        $this->assertNothingCharged($user);
    }

    /**
     * Nothing over the API's absolute maximum is taken, waiting or not.
     */
    public function test_a_text_over_the_apis_maximum_is_refused_with_422(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();

        $this->assertApiError($this->speak($plain, $voice, ['text' => str_repeat('c', 50001)]), 422, 'validation_failed', null, 'text');
        $this->assertApiError($this->speakAsync($plain, $voice, ['text' => str_repeat('c', 50001)]), 422, 'validation_failed', null, 'text');
        $this->assertNothingCharged($user);
    }

    /**
     * A body that is not JSON is 400 invalid_json, and not charged.
     */
    public function test_a_body_that_is_not_json_is_refused_with_400_invalid_json(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];

        $response = $this->call('POST', '/v1/text-to-speech/'.self::voiceId($this->premiumVoice()), [], [], [], [
            'HTTP_X_LISTEN_API_KEY' => $plain,
            'CONTENT_TYPE' => 'application/json',
        ], '{"text": "Hello"');

        $this->assertApiError($response, 400, 'invalid_json', 'invalid_request_error');
        $this->assertNothingCharged($user);
    }

    /**
     * Text with words that are not allowed is refused before anything is
     * reserved, and the attempt is kept for the admin.
     */
    public function test_text_with_prohibited_words_is_refused_with_content_policy_violation(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];

        $this->assertApiError($this->speak($plain, $this->arabicVoice(), ['text' => 'هذا نص شرموطة غير مقبول']), 422, 'content_policy_violation', 'invalid_request_error', 'text');

        $this->assertNothingCharged($user);
        $this->assertDatabaseHas('blocked_generations', ['user_id' => $user->id, 'attribute' => 'text']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string}>
     */
    public static function optionsNotInPlan(): array
    {
        return [
            'custom direction' => [['style_prompt' => 'Whisper it.'], ['custom_prompt_enabled' => true], 'custom_direction', 'style_prompt'],
            'a sound environment' => [['audio_effect' => 'hall'], ['sound_effects_enabled' => true], 'sound_environments', 'audio_effect'],
            'dialogue' => [[
                'text' => "Sara: Welcome back!\nOmar: Thanks, it is great to be here.",
                'dialogue' => ['speakers' => [['name' => 'Sara', 'voice_id' => 'voice_1'], ['name' => 'Omar', 'voice_id' => 'voice_1']]],
            ], ['dialogue_enabled' => true], 'dialogue', 'dialogue'],
            'the wav format' => [['output_format' => 'wav'], ['download_formats' => ['mp3', 'wav']], 'output_format_wav', 'output_format'],
        ];
    }

    /**
     * An option the plan does not include is refused with 403
     * feature_not_in_plan, naming the plans that include it, before anything
     * is charged.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $richerPlan
     */
    #[DataProvider('optionsNotInPlan')]
    public function test_an_option_the_plan_does_not_include_is_refused_with_403_feature_not_in_plan(array $body, array $richerPlan, string $feature, string $param): void
    {
        if ($feature === 'output_format_wav' && ! AudioFormats::serverCanConvert()) {
            $this->markTestSkipped('No plan can offer wav without ffmpeg.');
        }
        $this->apiPlan(['slug' => 'studio', 'name' => 'Studio', 'price_cents' => 9900] + $richerPlan);
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];

        $response = $this->speak($plain, $this->premiumVoice(), $body);

        $this->assertApiError($response, 403, 'feature_not_in_plan', 'permission_error', $param)
            ->assertJsonPath('error.required_feature', $feature)
            ->assertJsonPath('error.available_on_plans', ['studio']);
        $this->assertStringEndsWith('/pricing', (string) $response->json('error.upgrade_url'));
        $this->assertStringContainsString('Studio', (string) $response->json('error.message'));
        $this->assertNothingCharged($user);
    }

    /**
     * When the generation itself fails (here the translator it needs is
     * down, before any voice is asked for), the answer is 502
     * generation_failed with the history item, and every credit it reserved
     * is given back.
     */
    public function test_a_generation_that_fails_answers_502_and_gives_every_credit_back(): void
    {
        $user = $this->apiMember();
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user);

        $response = $this->speak($plain, $this->arabicVoice(), ['text' => 'Please translate this sentence.', 'translate' => true]);

        $generation = AudioGeneration::query()->sole();
        $this->assertApiError($response, 502, 'generation_failed', 'api_error')
            ->assertHeader('X-History-Item-Id', (string) $generation->id)
            ->assertJsonPath('error.history_item_id', $generation->id);
        $this->assertStringEndsWith('No credits were charged.', (string) $response->json('error.message'));
        $this->assertSame('failed', $generation->status);
        $this->assertSame([0, 0, 0], [$this->creditsSpent($user), $this->requestsCounted($user), $this->secondsCounted($user)]);
        $this->assertSame([0, 0], [$this->keyCreditsSpent($key), $this->keyRequestsCounted($key)]);
        $this->assertSame(0, $this->speechCalls->gemini + $this->speechCalls->google, 'no voice was asked for');

        $item = $this->apiGet($plain, '/history/'.$generation->id)->assertOk()->json();
        $this->assertSame(['failed', 0, null, null], [$item['status'], $item['credits_used'], $item['duration_seconds'], $item['audio_url']]);
        $this->assertNotEmpty($item['error']['message']);
    }

    private function arabicVoice(): Voice
    {
        return $this->premiumVoice([
            'language' => 'ar', 'gender' => 'male', 'display_name' => 'Othman',
            'internal_voice_id' => 'ar-Gemini-HD-Charon', 'provider_voice_id' => 'Charon',
        ]);
    }

    private function secondEnglishVoice(): Voice
    {
        return $this->premiumVoice([
            'gender' => 'male', 'display_name' => 'Omar',
            'internal_voice_id' => 'en-Gemini-HD-Puck', 'provider_voice_id' => 'Puck',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dialogueBody(Voice $first, Voice $second): array
    {
        return [
            'text' => "Sara: Welcome back to the show!\nOmar: Thanks, it is great to be here.",
            'dialogue' => ['speakers' => [
                ['name' => 'Sara', 'voice_id' => self::voiceId($first)],
                ['name' => 'Omar', 'voice_id' => self::voiceId($second)],
            ]],
        ];
    }
}
