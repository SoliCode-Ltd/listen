<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\User;
use App\Models\Voice;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\InteractsWithDeveloperApi;
use Tests\TestCase;

/**
 * The same text made again in the studio is a new reading: members press
 * Regenerate because they want a different one, and they pay for each take,
 * so it is never answered from the saved copy of the last one. A developer
 * API request may still be answered from the saved copy.
 */
class FreshTakeTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
    }

    public function test_the_same_text_made_again_in_the_studio_is_a_new_take(): void
    {
        $user = $this->apiMember();
        $voice = $this->premiumVoice();

        $this->studio($user, $voice)->assertOk();
        $this->studio($user, $voice)->assertOk();

        $this->assertSame(2, $this->speechCalls->gemini, 'each take is read by the engine');
        $takes = AudioGeneration::query()->where('user_id', $user->id)->orderBy('id')->get();
        $this->assertSame(['done', 'done'], $takes->pluck('status')->all());
        $this->assertNotSame($takes[0]->audio_path, $takes[1]->audio_path, 'the second take is its own file');
    }

    public function test_an_identical_developer_api_request_may_use_the_saved_copy(): void
    {
        $user = $this->apiMember();
        ['plain' => $plain] = $this->issueKey($user);
        $voice = $this->premiumVoice();

        $this->speak($plain, $voice, ['text' => 'The same words, sent twice.'])->assertOk();
        $this->speak($plain, $voice, ['text' => 'The same words, sent twice.'])->assertOk();

        $this->assertSame(1, $this->speechCalls->gemini);
    }

    private function studio(User $user, Voice $voice): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'The same words, read again.',
            'target_language' => 'en',
            'voice_tier' => 'hd',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
        ]);
    }
}
