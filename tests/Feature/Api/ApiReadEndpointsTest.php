<?php

namespace Tests\Feature\Api;

use App\Models\ClonedVoice;
use App\Models\SiteSetting;
use App\Models\Voice;
use App\Services\Tts\TtsRoutingService;
use App\Support\Api\ApiModels;
use App\Support\Api\OpenApiSpec;
use App\Support\Credits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The read endpoints clients build on: voices, voice options, models, the
 * account and its plan, usage and limits, and the OpenAPI description. They
 * answer with the documented fields, show only what the member's plan and
 * account allow, and never expose the speech engine's own voice names.
 */
class ApiReadEndpointsTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    /** The fields of a voice, as documented (OpenAPI schema Voice). */
    private const VOICE_FIELDS = [
        'voice_id', 'name', 'name_ar', 'category', 'language', 'gender', 'accent',
        'model_id', 'featured', 'available_on_plan', 'preview_url', 'avatar_url',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
    }

    /**
     * The premium studio voices of the engine the site uses, each with the
     * documented fields only: no standard voices, no switched-off voices, and
     * never an engine's own voice name.
     */
    public function test_voices_lists_the_premium_studio_voices_with_the_documented_fields(): void
    {
        $plain = $this->issueKey($this->apiMember())['plain'];
        $maya = $this->premiumVoice();
        $othman = $this->premiumVoice(['language' => 'ar', 'gender' => 'male', 'display_name' => 'Othman', 'internal_voice_id' => 'ar-Gemini-HD-Charon', 'provider_voice_id' => 'Charon']);
        $standard = $this->standardVoice();
        $off = $this->premiumVoice(['display_name' => 'Nora', 'internal_voice_id' => 'en-Gemini-HD-Leda', 'provider_voice_id' => 'Leda', 'is_active' => false]);
        $google = $this->googlePremiumVoice();

        $response = $this->apiGet($plain, '/voices')->assertOk()->assertJsonPath('total_count', 2);

        $voices = collect($response->json('voices'))->keyBy('voice_id');
        $this->assertEqualsCanonicalizing([self::voiceId($maya), self::voiceId($othman)], $voices->keys()->all());
        foreach ($voices as $voice) {
            $this->assertSame(self::VOICE_FIELDS, array_keys($voice));
            $this->assertSame('premade', $voice['category']);
            $this->assertSame(ApiModels::PREMIUM, $voice['model_id']);
            $this->assertTrue($voice['available_on_plan']);
        }
        $this->assertSame(['Maya', 'en', 'female'], [$voices[self::voiceId($maya)]['name'], $voices[self::voiceId($maya)]['language'], $voices[self::voiceId($maya)]['gender']]);
        $this->assertSame(['Othman', 'عثمان', 'ar', 'male'], [
            $voices[self::voiceId($othman)]['name'], $voices[self::voiceId($othman)]['name_ar'],
            $voices[self::voiceId($othman)]['language'], $voices[self::voiceId($othman)]['gender'],
        ]);
        foreach (['en-Gemini-HD-Kore', 'Kore', 'Charon', '_internal_name', '_tier', self::voiceId($standard), self::voiceId($off), self::voiceId($google)] as $hidden) {
            $this->assertStringNotContainsString($hidden, (string) $response->getContent());
        }
    }

    /**
     * The documented filters narrow the list; a value outside them is a 422.
     */
    public function test_voices_can_be_filtered_by_language_gender_category_and_model(): void
    {
        $plain = $this->issueKey($this->apiMember())['plain'];
        $maya = $this->premiumVoice();
        $othman = $this->premiumVoice(['language' => 'ar', 'gender' => 'male', 'display_name' => 'Othman', 'internal_voice_id' => 'ar-Gemini-HD-Charon', 'provider_voice_id' => 'Charon']);

        $ids = fn (string $query): array => collect($this->apiGet($plain, '/voices?'.$query)->assertOk()->json('voices'))->pluck('voice_id')->all();

        $this->assertSame([self::voiceId($othman)], $ids('language=ar'));
        $this->assertSame([self::voiceId($maya)], $ids('language=en'));
        $this->assertSame([self::voiceId($othman)], $ids('gender=male'));
        $this->assertSame([], $ids('category=cloned'));
        $this->assertCount(2, $ids('category=premade&model_id='.ApiModels::PREMIUM));

        $this->assertApiError($this->apiGet($plain, '/voices?gender=other'), 422, 'validation_failed', 'invalid_request_error', 'gender');
        $this->assertApiError($this->apiGet($plain, '/voices?language=fr'), 422, 'validation_failed', null, 'language');
        $this->assertApiError($this->apiGet($plain, '/voices?model_id=some_other_model_v2'), 422, 'validation_failed', null, 'model_id');
    }

    /**
     * One voice by its id; an id the member cannot use (unknown, standard,
     * switched off, or not a voice id at all) is 404 voice_not_found.
     */
    public function test_a_voice_is_shown_by_its_id_and_an_unknown_id_is_404_voice_not_found(): void
    {
        $plain = $this->issueKey($this->apiMember())['plain'];
        $maya = $this->premiumVoice();
        $standard = $this->standardVoice();
        $off = $this->premiumVoice(['display_name' => 'Nora', 'internal_voice_id' => 'en-Gemini-HD-Leda', 'provider_voice_id' => 'Leda', 'is_active' => false]);

        $voice = $this->apiGet($plain, '/voices/'.self::voiceId($maya))->assertOk()->json();
        $this->assertSame(self::VOICE_FIELDS, array_keys($voice));
        $this->assertSame([self::voiceId($maya), 'Maya'], [$voice['voice_id'], $voice['name']]);

        foreach ([self::voiceId($standard), self::voiceId($off), 'voice_999999', 'Kore', 'en-Gemini-HD-Kore'] as $unknown) {
            $this->assertApiError($this->apiGet($plain, '/voices/'.$unknown), 404, 'voice_not_found', 'invalid_request_error', 'voice_id');
        }
    }

    /**
     * The ids are those of the engine the site currently prefers for premium
     * voices: when the site is switched to Google, its premium voices are
     * listed and the Gemini voices' ids are no longer found.
     */
    public function test_voice_ids_follow_the_engine_the_site_uses_for_premium_voices(): void
    {
        $plain = $this->issueKey($this->apiMember())['plain'];
        $maya = $this->premiumVoice();
        $google = $this->googlePremiumVoice();
        $this->apiGet($plain, '/voices/'.self::voiceId($maya))->assertOk();

        SiteSetting::set('tts_mode', TtsRoutingService::MODE_GOOGLE, 'tts');

        $this->assertSame([self::voiceId($google)], collect($this->apiGet($plain, '/voices')->assertOk()->json('voices'))->pluck('voice_id')->all());
        $this->assertApiError($this->apiGet($plain, '/voices/'.self::voiceId($maya)), 404, 'voice_not_found');
    }

    /**
     * A member's own ready cloned voices are listed for them when their plan
     * includes cloning, and are invisible and unusable to everyone else.
     */
    public function test_cloned_voices_are_listed_only_for_their_owner(): void
    {
        SiteSetting::set('voice_cloning_audience', 'everyone', 'voice_cloning');
        $plan = $this->apiPlan(['voice_cloning_enabled' => true]);
        $owner = $this->apiMember($plan);
        $other = $this->apiMember($plan);
        $ownerKey = $this->issueKey($owner)['plain'];
        $otherKey = $this->issueKey($other)['plain'];
        $this->premiumVoice();
        $ready = ClonedVoice::factory()->ready()->forUser($owner)->create(['name' => 'My Narrator', 'language_code' => 'en-US']);
        ClonedVoice::factory()->processing()->forUser($owner)->create(['name' => 'Still training']);

        $mine = collect($this->apiGet($ownerKey, '/voices?category=cloned')->assertOk()->json('voices'));
        $this->assertSame(['cloned_'.$ready->id], $mine->pluck('voice_id')->all());
        $this->assertSame(['My Narrator', 'cloned', 'en', ApiModels::PREMIUM], [$mine[0]['name'], $mine[0]['category'], $mine[0]['language'], $mine[0]['model_id']]);
        $this->apiGet($ownerKey, '/voices/cloned_'.$ready->id)->assertOk()->assertJsonPath('name', 'My Narrator');

        $this->assertSame([], $this->apiGet($otherKey, '/voices?category=cloned')->assertOk()->json('voices'));
        $this->assertApiError($this->apiGet($otherKey, '/voices/cloned_'.$ready->id), 404, 'voice_not_found');
        $this->assertApiError($this->speak($otherKey, 'cloned_'.$ready->id), 404, 'voice_not_found', null, 'voice_id');
        $this->assertNothingCharged($other);
    }

    /**
     * Without cloning in the plan, cloned voices are not listed and asking for
     * one is refused as an option the plan lacks, before anything is charged.
     */
    public function test_cloned_voices_need_a_plan_with_cloning(): void
    {
        SiteSetting::set('voice_cloning_audience', 'everyone', 'voice_cloning');
        $this->apiPlan(['slug' => 'studio', 'name' => 'Studio', 'price_cents' => 9900, 'voice_cloning_enabled' => true]);
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = ClonedVoice::factory()->ready()->forUser($user)->create();

        $this->assertSame([], $this->apiGet($plain, '/voices?category=cloned')->assertOk()->json('voices'));
        $this->assertApiError($this->speak($plain, 'cloned_'.$voice->id), 403, 'feature_not_in_plan', 'permission_error', 'voice_id')
            ->assertJsonPath('error.required_feature', 'cloned_voices')
            ->assertJsonPath('error.available_on_plans', ['studio']);
        $this->assertNothingCharged($user);
    }

    /**
     * The values text to speech accepts, with the site's defaults and what
     * this account's plan includes; options no plan includes are left out.
     */
    public function test_voice_options_lists_the_accepted_values_defaults_and_plan_features(): void
    {
        $plain = $this->issueKey($this->apiMember())['plain'];

        $options = $this->apiGet($plain, '/voice-options')->assertOk()->json();

        $this->assertSame(['model_id', 'dialects', 'styles', 'emotions', 'defaults', 'voice_settings', 'output_formats', 'features'], array_keys($options));
        $this->assertSame(ApiModels::PREMIUM, $options['model_id']);
        foreach ([$options['dialects']['ar'], $options['dialects']['en'], $options['styles'], $options['emotions']] as $list) {
            $this->assertNotEmpty($list);
            foreach ($list as $option) {
                $this->assertSame(['value', 'name', 'name_ar'], array_keys($option));
            }
        }
        $this->assertContains('ar-EG', array_column($options['dialects']['ar'], 'value'));
        $this->assertContains('en-GB', array_column($options['dialects']['en'], 'value'));
        $this->assertSame(['dialect' => 'ar-EG', 'style' => 'promo', 'emotion' => 'confident'], $options['defaults']['ar']);
        $this->assertSame(['dialect' => null, 'style' => 'neutral', 'emotion' => 'neutral'], $options['defaults']['en']);
        $this->assertEquals(['speed' => ['min' => 0.5, 'max' => 2.0, 'default' => 1.0], 'pitch' => ['min' => -10, 'max' => 10, 'default' => 0]], $options['voice_settings']);
        $this->assertSame(['mp3'], $options['output_formats']);
        $this->assertSame(['style_prompt' => false, 'audio_effect' => false, 'dialogue' => false, 'cloned_voices' => false], $options['features']);
    }

    /**
     * Sound environments and dialogue appear in the options as soon as some
     * plan with the API includes them, with this account's own access shown.
     */
    public function test_voice_options_show_sound_environments_and_dialogue_once_a_plan_includes_them(): void
    {
        $this->apiPlan(['slug' => 'studio', 'name' => 'Studio', 'price_cents' => 9900, 'sound_effects_enabled' => true, 'dialogue_enabled' => true]);
        $plain = $this->issueKey($this->apiMember())['plain'];

        $options = $this->apiGet($plain, '/voice-options')->assertOk()->json();

        $this->assertNotEmpty($options['audio_effects']);
        $this->assertSame(['value', 'name', 'name_ar'], array_keys($options['audio_effects'][0]));
        $this->assertSame(['min_speakers' => 2, 'max_speakers' => 6, 'max_characters' => 2500], $options['dialogue']);
        $this->assertFalse($options['features']['audio_effect'], 'this account\'s own plan still lacks them');
        $this->assertFalse($options['features']['dialogue']);
    }

    /**
     * Values taken from /v1/voice-options are accepted by text to speech, so
     * the options and the validation stay in step.
     */
    public function test_values_from_voice_options_are_accepted_by_text_to_speech(): void
    {
        $plain = $this->issueKey($this->apiMember())['plain'];
        $voice = $this->premiumVoice();
        $options = $this->apiGet($plain, '/voice-options')->json();

        $this->speak($plain, $voice, [
            'dialect' => last($options['dialects']['en'])['value'],
            'style' => last($options['styles'])['value'],
            'emotion' => last($options['emotions'])['value'],
            'voice_settings' => ['speed' => $options['voice_settings']['speed']['max'], 'pitch' => $options['voice_settings']['pitch']['min']],
            'output_format' => $options['output_formats'][0],
        ])->assertOk();
    }

    /**
     * The one model, with the request limits of this account's plan and the
     * options it supports.
     */
    public function test_models_lists_the_one_model_with_this_plans_limits(): void
    {
        $plain = $this->issueKey($this->apiMember($this->apiPlan(['max_chars_per_request' => 8000])))['plain'];

        $models = $this->apiGet($plain, '/models')->assertOk()->json('models');

        $this->assertCount(1, $models);
        $this->assertSame(ApiModels::PREMIUM, $models[0]['model_id']);
        $this->assertSame(ApiModels::NAME, $models[0]['name']);
        $this->assertSame(['ar', 'en'], $models[0]['languages']);
        $this->assertTrue($models[0]['available_on_plan']);
        $this->assertSame(5000, $models[0]['max_characters_per_request'], 'waiting for the file: never over 5,000');
        $this->assertSame(8000, $models[0]['max_characters_per_async_request'], 'in the background: the plan\'s own limit');
        $this->assertSame([
            'dialect' => true, 'style' => true, 'emotion' => true, 'voice_settings' => true, 'inline_tags' => true,
            'style_prompt' => false, 'audio_effect' => false, 'dialogue' => false, 'cloned_voices' => false,
        ], $models[0]['supports']);
    }

    /**
     * The account: who the key belongs to, with its plan and usage.
     */
    public function test_user_shows_the_account_the_key_belongs_to(): void
    {
        $user = $this->apiMember(null, ['name' => 'Sara Ahmed', 'email' => 'sara@example.com']);
        $plain = $this->issueKey($user)['plain'];

        $response = $this->apiGet($plain, '/user')->assertOk();

        $response->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('name', 'Sara Ahmed')
            ->assertJsonPath('email', 'sara@example.com')
            ->assertJsonPath('created_at', '2026-10-14T10:00:00+00:00')
            ->assertJsonPath('subscription.plan.slug', 'creator')
            ->assertJsonPath('subscription.credits.limit', 90000);
        $this->assertSame($this->apiGet($plain, '/user/subscription')->json(), $response->json('subscription'));
    }

    /**
     * Plan, credits, limits and this key's own usage, as documented, before
     * anything was spent.
     */
    public function test_subscription_shows_the_plan_credits_limits_and_key(): void
    {
        $user = $this->apiMember();
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user, null, 5000, 'Production assistant');

        $body = $this->apiGet($plain, '/user/subscription')->assertOk()->json();

        $this->assertSame([
            'slug' => 'creator',
            'name' => 'Creator',
            'models' => [ApiModels::PREMIUM],
            'features' => ['style_prompt' => false, 'audio_effect' => false, 'dialogue' => false, 'cloned_voices' => false],
            'commercial_use_allowed' => true,
            'processing_priority' => 'standard',
        ], $body['plan']);
        $this->assertSame('active', $body['status']);
        $this->assertFalse($body['trial']);
        $this->assertSame('2026-11-14T10:00:00+00:00', $body['current_period_end']);
        $this->assertSame('monthly', $body['billing_mode']);
        $this->assertSame([
            'used' => 0,
            'limit' => 90000,
            'remaining' => 90000,
            'gift' => 0,
            'credits_per_second' => Credits::PER_SECOND,
            'overage_allowed' => false,
            'period' => '2026-10-14',
            'period_starts_at' => '2026-10-14T10:00:00+00:00',
            'resets_at' => '2026-11-14T10:00:00+00:00',
            'resets_at_unix' => 1794650400,
            'expires_at' => null,
        ], $body['credits']);
        $this->assertSame(['used' => 0, 'limit' => 6000], $body['voice_seconds']);
        $this->assertSame(0, $body['requests']['used_this_month']);
        $this->assertNull($body['requests']['monthly_limit']);
        $this->assertNull($body['requests']['daily_limit']);
        $this->assertSame([
            'max_characters_per_request' => 5000,
            'max_characters_per_async_request' => 10000,
            'max_characters_per_dialogue' => 2500,
            'requests_per_minute' => 60,
            'other_requests_per_minute' => 300,
            'concurrent_generations' => 2,
            'background_generations' => 10,
            'output_formats' => ['mp3'],
        ], $body['limits']);
        $this->assertSame([
            'api_key_id' => $key->id,
            'name' => 'Production assistant',
            'monthly_credit_limit' => 5000,
            'credits_used_this_month' => 0,
            'requests_this_month' => 0,
        ], $body['api_key']);
    }

    /**
     * After a generation the account's credits and the key's own figures show
     * what was spent; another key of the same member keeps its own figures.
     */
    public function test_subscription_shows_what_was_spent_on_the_account_and_on_each_key(): void
    {
        $user = $this->apiMember();
        ['key' => $used, 'plain' => $usedPlain] = $this->issueKey($user, null, null, 'Used');
        $idlePlain = $this->issueKey($user, null, null, 'Idle')['plain'];

        $this->speak($usedPlain, $this->premiumVoice())->assertOk();

        $viaUsed = $this->apiGet($usedPlain, '/user/subscription')->json();
        $this->assertSame([60, 89940], [$viaUsed['credits']['used'], $viaUsed['credits']['remaining']]);
        $this->assertSame(['used' => 4, 'limit' => 6000], $viaUsed['voice_seconds']);
        $this->assertSame(1, $viaUsed['requests']['used_this_month']);
        $this->assertSame([$used->id, 60, 1], [$viaUsed['api_key']['api_key_id'], $viaUsed['api_key']['credits_used_this_month'], $viaUsed['api_key']['requests_this_month']]);

        $viaIdle = $this->apiGet($idlePlain, '/user/subscription')->json();
        $this->assertSame(60, $viaIdle['credits']['used'], 'credits are the account\'s, shared by its keys');
        $this->assertSame([0, 0], [$viaIdle['api_key']['credits_used_this_month'], $viaIdle['api_key']['requests_this_month']]);
    }

    /**
     * Limits are read from the plan on each request: its own rate and
     * concurrency, or the defaults for its processing priority.
     */
    public function test_subscription_limits_follow_the_plan(): void
    {
        $own = $this->issueKey($this->apiMember($this->apiPlan(['api_requests_per_minute' => 7, 'api_concurrency' => 3, 'daily_requests' => 40, 'monthly_requests' => 900])))['plain'];
        $high = $this->issueKey($this->apiMember($this->apiPlan(['slug' => 'pro', 'name' => 'Pro', 'processing_priority' => 'high'])))['plain'];

        $limits = $this->apiGet($own, '/user/subscription')->json();
        $this->assertSame([7, 300, 3, 15], [
            $limits['limits']['requests_per_minute'], $limits['limits']['other_requests_per_minute'],
            $limits['limits']['concurrent_generations'], $limits['limits']['background_generations'],
        ]);
        $this->assertSame([900, 40, 0], [$limits['requests']['monthly_limit'], $limits['requests']['daily_limit'], $limits['requests']['used_today']]);

        $limits = $this->apiGet($high, '/user/subscription')->json('limits');
        $this->assertSame([120, 360, 5, 25], [$limits['requests_per_minute'], $limits['other_requests_per_minute'], $limits['concurrent_generations'], $limits['background_generations']]);
    }

    /**
     * The OpenAPI description needs no key, and describes every endpoint the
     * API serves, with its method, so the published contract stays in step
     * with the routes.
     */
    public function test_openapi_json_describes_every_endpoint_without_a_key(): void
    {
        $response = $this->get('/v1/openapi.json')->assertOk();

        $response->assertHeader('Access-Control-Allow-Origin', '*');
        $spec = $response->json();
        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertSame(OpenApiSpec::VERSION, $spec['info']['version']);
        $this->assertStringEndsWith('/v1', $spec['servers'][0]['url']);
        $this->assertSame('X-Listen-Api-Key', $spec['components']['securitySchemes']['ApiKeyHeader']['name']);

        $served = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'v1/') && $route->uri() !== 'v1/openapi.json');
        $this->assertCount(12, $served);
        foreach ($served as $route) {
            $path = strtr(substr($route->uri(), 2), ['{voiceId}' => '{voice_id}', '{historyItemId}' => '{history_item_id}']);
            $method = strtolower(collect($route->methods())->reject(fn (string $m): bool => $m === 'HEAD')->first());
            $this->assertArrayHasKey($path, $spec['paths'], "{$path} is described");
            $this->assertArrayHasKey($method, $spec['paths'][$path], "{$method} {$path} is described");
        }
    }

    /**
     * The OpenAPI description follows the plans: an option no plan with the
     * API includes is left out of the request body, and appears once a plan
     * includes it.
     */
    public function test_openapi_json_describes_only_the_options_some_plan_includes(): void
    {
        $this->apiPlan();
        $body = fn (): array => $this->get('/v1/openapi.json')->assertOk()->json('components.schemas.TextToSpeechRequest.properties');

        $this->assertArrayHasKey('text', $body());
        foreach (['style_prompt', 'audio_effect', 'dialogue'] as $option) {
            $this->assertArrayNotHasKey($option, $body());
        }

        $this->apiPlan(['slug' => 'studio', 'name' => 'Studio', 'price_cents' => 9900, 'custom_prompt_enabled' => true, 'sound_effects_enabled' => true, 'dialogue_enabled' => true]);
        foreach (['style_prompt', 'audio_effect', 'dialogue'] as $option) {
            $this->assertArrayHasKey($option, $body());
        }
    }

    private function standardVoice(): Voice
    {
        return Voice::query()->create([
            'language' => 'en', 'gender' => 'female', 'tier' => 'standard', 'display_name' => 'Emma',
            'internal_voice_id' => 'en-US-Wavenet-C', 'provider' => 'google_cloud',
            'provider_voice_id' => 'en-US-Wavenet-C', 'is_active' => true,
        ]);
    }

    private function googlePremiumVoice(): Voice
    {
        return Voice::query()->create([
            'language' => 'en', 'gender' => 'male', 'tier' => 'hd', 'display_name' => 'Charlie',
            'internal_voice_id' => 'en-US-Chirp3-HD-Charon', 'provider' => 'google_cloud',
            'provider_voice_id' => 'en-US-Chirp3-HD-Charon', 'is_active' => true,
        ]);
    }
}
