<?php

namespace Tests\Feature\Api;

use App\Models\AudioGeneration;
use App\Models\TextItem;
use App\Models\Voice;
use App\Support\Api\ApiModels;
use App\Support\Api\OpenApiSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * GET /v1/history (cursor pages and filters), GET and DELETE
 * /v1/history/{id} and GET /v1/history/{id}/audio: a member sees and
 * downloads every generation of their account (API, dashboard and
 * extension, never demo clips), and nothing of anyone else's. Deleting
 * needs the history_write permission and never gives credits back.
 */
class ApiHistoryTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
    }

    /**
     * Newest first, page by page: next_cursor passed as before gives the
     * next page, and the last page says there is no more.
     */
    public function test_history_lists_the_members_generations_newest_first_in_cursor_pages(): void
    {
        $user = $this->apiMember();
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user);
        $ids = [];
        foreach (range(1, 5) as $n) {
            $ids[] = $this->storedGeneration($user, ['api_key_id' => $n % 2 ? $key->id : null, 'input_text' => "Item {$n}"])->id;
        }

        $page = $this->apiGet($plain, '/history?page_size=2')->assertOk()->json();
        $this->assertSame([$ids[4], $ids[3]], array_column($page['history'], 'history_item_id'));
        $this->assertSame([true, $ids[3]], [$page['has_more'], $page['next_cursor']]);

        $page = $this->apiGet($plain, '/history?page_size=2&before='.$page['next_cursor'])->assertOk()->json();
        $this->assertSame([$ids[2], $ids[1]], array_column($page['history'], 'history_item_id'));
        $this->assertSame([true, $ids[1]], [$page['has_more'], $page['next_cursor']]);

        $page = $this->apiGet($plain, '/history?page_size=2&before='.$page['next_cursor'])->assertOk()->json();
        $this->assertSame([$ids[0]], array_column($page['history'], 'history_item_id'));
        $this->assertSame([false, null], [$page['has_more'], $page['next_cursor']]);

        $all = $this->apiGet($plain, '/history')->assertOk()->json();
        $this->assertSame(array_reverse($ids), array_column($all['history'], 'history_item_id'), '30 a page by default');
        $this->assertFalse($all['has_more']);
    }

    /**
     * The source, status and voice filters narrow the list.
     */
    public function test_history_can_be_filtered_by_source_status_and_voice(): void
    {
        $user = $this->apiMember();
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user);
        $maya = $this->premiumVoice();
        $omar = $this->premiumVoice(['display_name' => 'Omar', 'gender' => 'male', 'internal_voice_id' => 'en-Gemini-HD-Puck', 'provider_voice_id' => 'Puck']);
        $apiDone = $this->storedGeneration($user, ['api_key_id' => $key->id])->id;
        $apiFailed = $this->storedGeneration($user, ['api_key_id' => $key->id, 'status' => 'failed', 'audio_path' => null, 'voice_name' => 'en-Gemini-HD-Puck'])->id;
        $appQueued = $this->storedGeneration($user, ['status' => 'queued', 'audio_path' => null])->id;

        $ids = fn (string $query): array => array_column($this->apiGet($plain, '/history?'.$query)->assertOk()->json('history'), 'history_item_id');

        $this->assertSame([$apiFailed, $apiDone], $ids('source=api'));
        $this->assertSame([$appQueued], $ids('source=app'));
        $this->assertSame([$apiDone], $ids('status=completed'));
        $this->assertSame([$apiFailed], $ids('status=failed'));
        $this->assertSame([$appQueued], $ids('status=queued'));
        $this->assertSame([$apiFailed], $ids('voice_id='.self::voiceId($omar)));
        $this->assertSame([$appQueued, $apiDone], $ids('voice_id='.self::voiceId($maya)));
        $this->assertSame([], $ids('voice_id=voice_999999'));
        $this->assertSame([$apiDone], $ids('source=api&status=completed'));
    }

    /**
     * Filter values outside the documented ones are a 422.
     */
    public function test_invalid_history_filters_are_refused_with_422(): void
    {
        $plain = $this->issueKey($this->apiMember())['plain'];

        foreach (['page_size=0' => 'page_size', 'page_size=101' => 'page_size', 'page_size=ten' => 'page_size',
            'before=0' => 'before', 'source=web' => 'source', 'status=done' => 'status'] as $query => $param) {
            $this->assertApiError($this->apiGet($plain, '/history?'.$query), 422, 'validation_failed', 'invalid_request_error', $param);
        }
    }

    /**
     * Demo clips are not part of a member's history.
     */
    public function test_demo_clips_are_left_out_of_history(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $mine = $this->storedGeneration($user);
        $demoItem = TextItem::query()->create(['user_id' => $user->id, 'source_type' => 'demo', 'content' => 'Demo', 'language' => 'en', 'status' => 'done']);
        $demo = $this->storedGeneration($user, ['text_item_id' => $demoItem->id]);

        $this->assertSame([$mine->id], array_column($this->apiGet($plain, '/history')->json('history'), 'history_item_id'));
        $this->assertApiError($this->apiGet($plain, '/history/'.$demo->id), 404, 'history_item_not_found');
    }

    /**
     * A history item has exactly the fields the OpenAPI description lists,
     * with the generation's real values, and the public voice id instead of
     * the engine's voice name.
     */
    public function test_a_history_item_shows_the_documented_fields(): void
    {
        $user = $this->apiMember();
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user);
        $voice = $this->premiumVoice();
        $id = (int) $this->speak($plain, $voice, ['title' => 'Greeting'])->assertOk()->headers->get('X-History-Item-Id');

        $response = $this->apiGet($plain, '/history/'.$id)->assertOk();

        $item = $response->json();
        $this->assertSame(array_keys(OpenApiSpec::build()['components']['schemas']['HistoryItem']['properties']), array_keys($item));
        $this->assertSame([
            'history_item_id' => $id,
            'status' => 'completed',
            'request_source' => 'api',
            'api_key_id' => $key->id,
            'voice_id' => self::voiceId($voice),
            'voice_name' => 'Maya',
            'model_id' => ApiModels::PREMIUM,
            'language_code' => 'en',
            'dialect' => null,
            'style' => 'neutral',
            'emotion' => 'neutral',
            'audio_effect' => null,
            'dialogue' => false,
            'title' => 'Greeting',
            'text' => 'Hello there, this is a test.',
            'character_count' => 28,
            'credits_used' => 60,
            'duration_seconds' => 4,
            'audio_url' => url('/v1/history/'.$id.'/audio'),
            'error' => null,
            'created_at' => '2026-10-14T10:00:00+00:00',
            'created_at_unix' => 1791972000,
        ], array_diff_key($item, ['voice_settings' => true]));
        $this->assertEquals(['speed' => 1.0, 'pitch' => 0.0], $item['voice_settings']);
        $this->assertStringNotContainsString('en-Gemini-HD-Kore', (string) $response->getContent());
    }

    /**
     * Generations made in the dashboard or the extension are listed too, as
     * "app" items without a key.
     */
    public function test_dashboard_generations_are_listed_as_app_items(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $standard = Voice::query()->create([
            'language' => 'en', 'gender' => 'female', 'tier' => 'standard', 'display_name' => 'Emma',
            'internal_voice_id' => 'en-US-Wavenet-C', 'provider' => 'google_cloud',
            'provider_voice_id' => 'en-US-Wavenet-C', 'is_active' => true,
        ]);
        $generation = $this->storedGeneration($user, ['voice_tier' => 'standard', 'voice_name' => 'en-US-Wavenet-C', 'dialect' => 'en-GB', 'performance_style' => 'news']);

        $item = $this->apiGet($plain, '/history/'.$generation->id)->assertOk()->json();

        $this->assertSame(['app', null, null], [$item['request_source'], $item['api_key_id'], $item['model_id']]);
        $this->assertSame([self::voiceId($standard), 'Emma'], [$item['voice_id'], $item['voice_name']]);
        $this->assertSame([null, null], [$item['dialect'], $item['style']], 'standard voices have no studio options');
    }

    /**
     * The audio of a finished generation downloads as the stored file.
     */
    public function test_history_audio_downloads_the_stored_file(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $generation = $this->storedGeneration($user);

        $response = $this->apiGet($plain, '/history/'.$generation->id.'/audio')->assertOk();

        $this->assertSame(Storage::disk('public')->get($generation->audio_path), $response->getContent());
        $response->assertHeader('Content-Type', 'audio/mpeg')
            ->assertHeader('X-Output-Format', 'mp3')
            ->assertHeader('X-History-Item-Id', (string) $generation->id)
            ->assertHeader('Content-Length', (string) strlen($response->getContent()))
            ->assertHeader('Content-Disposition', 'inline; filename="listen-'.$generation->id.'.mp3"');
    }

    /**
     * A format the API does not have, or one the plan does not include, is
     * refused for downloads too.
     */
    public function test_history_audio_refuses_formats_the_plan_does_not_include(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $generation = $this->storedGeneration($user);

        $this->assertApiError($this->apiGet($plain, '/history/'.$generation->id.'/audio?output_format=aiff'), 422, 'invalid_output_format', 'invalid_request_error', 'output_format');
        $this->assertApiError($this->apiGet($plain, '/history/'.$generation->id.'/audio?output_format=wav'), 403, 'feature_not_in_plan', 'permission_error', 'output_format');
    }

    /**
     * Audio of a generation that is not finished, or that failed, is 409
     * audio_not_ready.
     */
    public function test_audio_of_an_unfinished_or_failed_generation_is_409_audio_not_ready(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $queued = $this->storedGeneration($user, ['status' => 'queued', 'audio_path' => null]);
        $failed = $this->storedGeneration($user, ['status' => 'failed', 'audio_path' => null]);

        $this->assertApiError($this->apiGet($plain, '/history/'.$queued->id.'/audio'), 409, 'audio_not_ready', 'invalid_request_error', 'history_item_id');
        $this->assertStringContainsString('failed', (string) $this->assertApiError($this->apiGet($plain, '/history/'.$failed->id.'/audio'), 409, 'audio_not_ready')->json('error.message'));
    }

    /**
     * Audio that is no longer stored is 410 audio_expired.
     */
    public function test_audio_no_longer_stored_is_410_audio_expired(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $generation = $this->storedGeneration($user);
        Storage::disk('public')->delete($generation->audio_path);

        $this->assertApiError($this->apiGet($plain, '/history/'.$generation->id.'/audio'), 410, 'audio_expired', 'invalid_request_error', 'history_item_id');
    }

    /**
     * Another member's generation does not exist for this key: not listed,
     * and 404 history_item_not_found to read, download or delete (it stays).
     */
    public function test_another_members_history_item_is_404_everywhere(): void
    {
        $plan = $this->apiPlan();
        $owner = $this->apiMember($plan);
        $theirs = $this->storedGeneration($owner, ['api_key_id' => $this->issueKey($owner)['key']->id]);
        $plain = $this->issueKey($this->apiMember($plan))['plain'];

        $this->assertSame([], $this->apiGet($plain, '/history')->assertOk()->json('history'));
        $this->assertApiError($this->apiGet($plain, '/history/'.$theirs->id), 404, 'history_item_not_found', 'invalid_request_error', 'history_item_id');
        $this->assertApiError($this->apiGet($plain, '/history/'.$theirs->id.'/audio'), 404, 'history_item_not_found');
        $this->assertApiError($this->apiDelete($plain, '/history/'.$theirs->id), 404, 'history_item_not_found');
        $this->assertNotNull($theirs->fresh(), 'it is not deleted');

        foreach (['abc', '1.5', '-1', '0x1'] as $notAnId) {
            $this->assertApiError($this->apiGet($plain, '/history/'.$notAnId), 404, 'history_item_not_found');
        }
    }

    /**
     * Deleting takes the item out of the history; the credits it used stay
     * spent, as the docs say.
     */
    public function test_deleting_a_history_item_removes_it_and_keeps_its_credits_spent(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $id = (int) $this->speak($plain, $this->premiumVoice())->assertOk()->headers->get('X-History-Item-Id');

        $this->apiDelete($plain, '/history/'.$id)->assertOk()->assertExactJson(['history_item_id' => $id, 'deleted' => true]);

        $this->assertApiError($this->apiGet($plain, '/history/'.$id), 404, 'history_item_not_found');
        $this->assertApiError($this->apiGet($plain, '/history/'.$id.'/audio'), 404, 'history_item_not_found');
        $this->assertSame([], $this->apiGet($plain, '/history')->json('history'));
        $this->assertSame(60, $this->creditsSpent($user), 'no credits come back');
    }

    /**
     * A generation still queued or running cannot be deleted (409
     * history_item_in_progress).
     */
    public function test_a_generation_still_running_cannot_be_deleted(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];

        foreach (['queued', 'processing'] as $status) {
            $generation = $this->storedGeneration($user, ['status' => $status, 'audio_path' => null]);
            $this->assertApiError($this->apiDelete($plain, '/history/'.$generation->id), 409, 'history_item_in_progress', 'invalid_request_error', 'history_item_id');
            $this->assertNotNull($generation->fresh());
        }
    }

    /**
     * History belongs to the account, not to a key: any of the member's keys
     * lists, reads, downloads and (with history_write) deletes what another
     * of their keys made, and each item says which key made it.
     */
    public function test_history_is_shared_by_all_of_a_members_keys(): void
    {
        $user = $this->apiMember();
        ['key' => $maker, 'plain' => $makerPlain] = $this->issueKey($user, null, null, 'Maker');
        $readerPlain = $this->issueKey($user, ['history_read', 'history_write'], null, 'Reader')['plain'];
        $id = (int) $this->speak($makerPlain, $this->premiumVoice())->assertOk()->headers->get('X-History-Item-Id');

        $this->assertSame([$id], array_column($this->apiGet($readerPlain, '/history')->json('history'), 'history_item_id'));
        $this->apiGet($readerPlain, '/history/'.$id)->assertOk()->assertJsonPath('api_key_id', $maker->id);
        $this->apiGet($readerPlain, '/history/'.$id.'/audio')->assertOk();
        $this->apiDelete($readerPlain, '/history/'.$id)->assertOk();
        $this->assertNull(AudioGeneration::query()->find($id));
    }
}
