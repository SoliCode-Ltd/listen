<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\BlockedGeneration;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\ContentSafety\TextContentCheck;
use App\Support\HiddenAudio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\InteractsWithDeveloperApi;
use Tests\TestCase;

/**
 * Every text is read for its meaning before a voice is made of it
 * (App\Services\ContentSafety\TextContentCheck): sexual content is refused however it
 * is spelled, for paying members too, with nothing charged and the attempt listed for
 * the admin. When the reader cannot answer the voice is still made, and the text is
 * read again later; a voice that should not have been made is then hidden.
 *
 * The reader and the speech engines are faked: nothing leaves the machine.
 */
class ContentCheckTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    /** What the faked reader answers: a ruling, 'blocked', 'down' or 'nonsense'. */
    private string $reader = 'allow';

    /** How many times the reader was asked, and what it was sent last. */
    private int $readerCalls = 0;

    private string $readerSaw = '';

    protected function setUp(): void
    {
        parent::setUp();

        config(['listen.content_check.enabled' => true]);
        Storage::fake('local');
        $this->setUpDeveloperApi();
    }

    public function test_a_text_the_reader_refuses_makes_no_voice_and_charges_nothing_even_for_a_paying_member(): void
    {
        $this->reader = 'refuse';
        $user = $this->apiMember();
        $voice = $this->premiumVoice();

        $response = $this->studio($user, $voice, 'A text with no listed word in it, but a meaning Listen does not read aloud.');

        $response->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('job.status', 'failed');
        $this->assertSame(trans('ui.backend.generation_content_refused', [], 'en'), $response->json('message'));

        $generation = AudioGeneration::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $generation->status);
        $this->assertSame(TextContentCheck::REFUSED, $generation->content_check);
        $this->assertNull($generation->audio_path);
        $this->assertSame(0, $this->speechCalls->gemini + $this->speechCalls->google, 'no voice was made');
        $this->assertSame(0, $this->creditsSpent($user), 'nothing charged');

        $logged = BlockedGeneration::query()->where('user_id', $user->id)->sole();
        $this->assertSame('Meaning: sexual', $logged->matched_term);
        $this->assertSame('dashboard.queue', $logged->route_name);
        $this->assertSame((int) $generation->id, (int) $logged->metadata['audio_generation_id']);
        $this->assertFalse((bool) $logged->metadata['voice_was_made']);

        // A paying member is not blocked by the rule itself, but every further try is refused too.
        $this->studio($user, $voice, 'The same meaning in other words.')->assertStatus(422);
        $this->assertFalse((bool) $user->fresh()->is_blocked);
        $this->assertSame(2, BlockedGeneration::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, $this->speechCalls->gemini + $this->speechCalls->google);
    }

    public function test_a_member_who_never_paid_is_blocked_after_two_refused_texts(): void
    {
        $this->reader = 'refuse';
        $user = $this->apiMember($this->apiPlan(['slug' => 'free', 'price_cents' => 0]));
        $voice = $this->premiumVoice();

        $this->studio($user, $voice, 'First try.');
        $this->assertFalse((bool) $user->fresh()->is_blocked);
        $this->studio($user, $voice, 'Second try.');

        $this->assertTrue((bool) $user->fresh()->is_blocked);
    }

    public function test_an_ordinary_text_is_read_once_and_its_voice_is_made(): void
    {
        $user = $this->apiMember($this->apiPlan(['custom_prompt_enabled' => true]));

        $this->studio($user, $this->premiumVoice(), 'Welcome to our shop. We open at nine.', ['style_prompt' => 'Warm and unhurried.'])
            ->assertOk()->assertJsonPath('job.status', 'done');

        $generation = AudioGeneration::query()->latest('id')->firstOrFail();
        $this->assertSame(TextContentCheck::ALLOWED, $generation->content_check);
        $this->assertSame(1, $this->readerCalls);
        // The reader is given the text and the member's own direction, as data between boundaries.
        $this->assertStringContainsString('Welcome to our shop. We open at nine.', $this->readerSaw);
        $this->assertStringContainsString('Warm and unhurried.', $this->readerSaw);
        $this->assertSame(0, BlockedGeneration::query()->count());
    }

    public function test_the_provider_refusing_to_read_the_text_at_all_is_a_refusal(): void
    {
        $this->reader = 'blocked';

        $ruling = app(TextContentCheck::class)->check('Some text.');

        $this->assertSame(TextContentCheck::REFUSED, $ruling['verdict']);
    }

    public function test_a_ruling_is_remembered_for_the_same_text(): void
    {
        $this->reader = 'refuse';
        $check = app(TextContentCheck::class);

        $check->check('The same text.', 'The same direction.');
        $check->check('The same text.', 'The same direction.');
        $this->assertSame(1, $this->readerCalls);

        $check->check('The same text.', 'Another direction.');
        $this->assertSame(2, $this->readerCalls);
    }

    public function test_when_the_reader_does_not_answer_the_voice_is_made_and_the_text_is_read_again_later(): void
    {
        $this->reader = 'down';
        $user = $this->apiMember();

        $this->studio($user, $this->premiumVoice(), 'A text read while the reader was down.')
            ->assertOk()->assertJsonPath('job.status', 'done');
        $generation = AudioGeneration::query()->latest('id')->firstOrFail();
        $this->assertSame(TextContentCheck::UNCHECKED, $generation->content_check);
        $path = (string) $generation->audio_path;
        Storage::disk('public')->assertExists($path);

        // Still down: nothing changes, and it waits for the next run.
        $this->artisan('listen:recheck-unchecked-texts')->assertSuccessful();
        $this->assertSame(TextContentCheck::UNCHECKED, $generation->fresh()->content_check);

        // Back, and the text should not have been read aloud: the voice is hidden and listed.
        $this->reader = 'refuse';
        $this->artisan('listen:recheck-unchecked-texts')->assertSuccessful();

        $generation->refresh();
        $this->assertSame(TextContentCheck::REFUSED, $generation->content_check);
        $this->assertTrue(HiddenAudio::isHidden($generation));
        $this->assertNull($generation->hidden_by, 'hidden by the check, not by an admin');
        Storage::disk('public')->assertMissing($path);
        Storage::disk('local')->assertExists(HiddenAudio::privatePath($generation));
        $logged = BlockedGeneration::query()->where('user_id', $user->id)->sole();
        $this->assertTrue((bool) $logged->metadata['voice_was_made']);
    }

    public function test_a_text_that_was_fine_is_marked_so_on_the_later_reading(): void
    {
        $this->reader = 'down';
        $user = $this->apiMember();
        $this->studio($user, $this->premiumVoice(), 'An ordinary text.')->assertOk();
        $generation = AudioGeneration::query()->latest('id')->firstOrFail();

        $this->reader = 'allow';
        $this->artisan('listen:recheck-unchecked-texts')->assertSuccessful();

        $this->assertSame(TextContentCheck::ALLOWED, $generation->fresh()->content_check);
        $this->assertFalse(HiddenAudio::isHidden($generation->fresh()));
        $this->assertSame(0, BlockedGeneration::query()->count());
    }

    public function test_an_answer_that_is_not_a_ruling_counts_as_not_read(): void
    {
        $this->reader = 'nonsense';

        $this->assertSame(TextContentCheck::UNCHECKED, app(TextContentCheck::class)->check('Some text.')['verdict']);
    }

    public function test_the_admin_can_switch_the_reading_off(): void
    {
        $this->reader = 'refuse';
        SiteSetting::set(TextContentCheck::ENABLED_SETTING, '0', 'tts');
        $user = $this->apiMember();

        $this->studio($user, $this->premiumVoice(), 'Any text.')->assertOk()->assertJsonPath('job.status', 'done');

        $this->assertSame(0, $this->readerCalls);
        $this->assertNull(AudioGeneration::query()->latest('id')->firstOrFail()->content_check);
    }

    public function test_the_developer_api_answers_a_refused_text_with_content_policy_violation(): void
    {
        $this->reader = 'refuse';
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];

        $this->assertApiError($this->speak($plain, $this->premiumVoice(), ['text' => 'A meaning Listen does not read aloud.']), 422, 'content_policy_violation', 'invalid_request_error', 'text');

        $this->assertSame(0, $this->creditsSpent($user));
        $this->assertSame(0, $this->speechCalls->gemini);
        // Text sent through the API comes from the member's own users: listed, but it never blocks the account.
        $this->assertSame('v1.text-to-speech', BlockedGeneration::query()->where('user_id', $user->id)->sole()->route_name);
    }

    public function test_a_background_api_request_with_a_refused_text_fails_at_once_and_gives_its_credits_back(): void
    {
        $this->reader = 'refuse';
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];

        $id = (int) $this->speakAsync($plain, $this->premiumVoice(), ['text' => 'A meaning Listen does not read aloud.'])->json('history_item_id');

        $generation = AudioGeneration::query()->findOrFail($id);
        $this->assertSame('failed', $generation->status);
        $this->assertSame(TextContentCheck::REFUSED, $generation->content_check);
        $this->assertSame(0, $this->creditsSpent($user));
    }

    public function test_a_listed_word_in_the_members_own_direction_is_refused_too(): void
    {
        $user = $this->apiMember($this->apiPlan(['custom_prompt_enabled' => true]));

        $this->studio($user, $this->premiumVoice(), 'An ordinary text.', ['style_prompt' => 'Say it like a slut.'])
            ->assertStatus(422)->assertJsonValidationErrors(['style_prompt']);

        $this->assertSame(0, AudioGeneration::query()->count());
        $this->assertSame('style_prompt', BlockedGeneration::query()->where('user_id', $user->id)->sole()->attribute);
    }

    /**
     * The forms a member used to get past the word list (other persons and tenses, a
     * dropped letter): each is refused by the list itself now.
     */
    public function test_the_word_list_knows_the_forms_that_were_used_to_get_past_it(): void
    {
        $filter = app(\App\Services\ProfanityFilter::class);

        foreach (['تتناك', 'تنيك', 'زبزوبك', 'تبعبصها', 'ديث', 'الديث', 'بطيزك'] as $word) {
            $this->assertNotNull($filter->firstMatch('كلام عادي '.$word.' وكلام بعده'), $word);
        }
        // Everyday words that only look close stay allowed.
        foreach (['حديث الصباح', 'نكت مضحكة', 'مؤخرة الجيش', 'الحديث الشريف', 'تنكيت'] as $phrase) {
            $this->assertNull($filter->firstMatch($phrase), $phrase);
        }
    }

    /**
     * The speech engines as the developer API tests fake them, and the text model that
     * reads a text before its voice is made.
     */
    protected function fakeSpeech(): void
    {
        $calls = (object) ['gemini' => 0, 'google' => 0, 'other' => []];
        $this->speechCalls = $calls;

        Http::fake(function ($request) use ($calls) {
            $url = $request->url();
            if (str_contains($url, 'texttospeech.googleapis.com')) {
                $calls->google++;

                return Http::response(['audioContent' => base64_encode(self::mp3Seconds($this->audioSeconds))]);
            }
            if (str_contains($url, 'generativelanguage.googleapis.com') && str_contains($url, '-tts')) {
                $calls->gemini++;

                return Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => [
                    'mimeType' => 'audio/L16;codec=pcm;rate=24000',
                    'data' => base64_encode(str_repeat("\0\0", 24000 * $this->audioSeconds)),
                ]]]]]]]);
            }
            if (str_contains($url, 'generativelanguage.googleapis.com') && str_contains($url, ':generateContent')) {
                $this->readerCalls++;
                $this->readerSaw = (string) data_get($request->data(), 'contents.0.parts.0.text');
                // Its own filters are switched off, so it rules instead of turning the request away.
                $this->assertSame('BLOCK_NONE', data_get($request->data(), 'safetySettings.0.threshold'));

                return match ($this->reader) {
                    'down' => Http::response(['error' => ['message' => 'Unavailable.']], 503),
                    'blocked' => Http::response(['promptFeedback' => ['blockReason' => 'PROHIBITED_CONTENT']]),
                    'nonsense' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'I cannot say.']]]]]]),
                    default => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                        'verdict' => $this->reader,
                        'category' => $this->reader === 'refuse' ? 'sexual' : 'none',
                        'reason' => 'A test ruling.',
                    ])]]]]]]),
                };
            }
            $calls->other[] = $url;

            return Http::response(['error' => ['message' => 'Not available in tests.']], 503);
        });
    }

    /** @param  array<string, mixed>  $more */
    private function studio(User $user, \App\Models\Voice $voice, string $text, array $more = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', $more + [
            'content' => $text,
            'target_language' => 'en',
            'voice_tier' => 'hd',
            'voice_key' => \App\Support\VoiceCatalog::keyFromId((int) $voice->id),
        ]);
    }
}
