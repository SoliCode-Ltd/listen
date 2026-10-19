<?php

namespace Tests\Feature\Api;

use App\Models\AudioGeneration;
use App\Models\Voice;
use App\Support\Credits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Over a mix of API requests, the member's counters and the key's own
 * figures always equal the audio delivered: retries are never charged
 * twice, failures and stuck background requests give their credits back
 * once (however often the sweep runs, and even if a worker picks up the
 * stuck job later), and deleting an item never gives back what it cost.
 */
class ApiCreditLedgerTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
        config(['queue.default' => 'database']);
    }

    public function test_the_counters_hold_exactly_what_was_delivered(): void
    {
        $user = $this->apiMember();
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user);
        $voice = $this->premiumVoice();

        // Two takes, and a third retried twice with one Idempotency-Key.
        $first = $this->speak($plain, $voice, ['text' => 'The first take.'])->assertOk();
        $this->speak($plain, $voice, ['text' => 'The second take.'])->assertOk();
        $this->speak($plain, $voice, ['text' => 'The third take.'], ['Idempotency-Key' => 'ledger-3'])->assertOk();
        $this->speak($plain, $voice, ['text' => 'The third take.'], ['Idempotency-Key' => 'ledger-3'])->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->speak($plain, $voice, ['text' => 'The third take.'], ['Idempotency-Key' => 'ledger-3'])->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        // A take that fails (the translator is down) gives its credits back.
        $this->assertApiError($this->speak($plain, $this->arabicVoice(), ['text' => 'Please translate this sentence.', 'translate' => true]), 502, 'generation_failed');

        // A background take, made by a worker.
        $this->speakAsync($plain, $voice, ['text' => 'The fourth take.'])->assertStatus(202);
        $this->assertSame(0, Artisan::call('queue:work', ['--once' => true, '--queue' => 'high,default']));

        // A background take no worker picks up: the sweep (run twice) fails it
        // and gives its credits back once; a worker that reaches it later makes nothing.
        $this->speakAsync($plain, $voice, ['text' => 'A take that gets stuck.'])->assertStatus(202);
        $this->travel(61)->minutes();
        $this->artisan('api:expire-stuck-generations')->assertSuccessful();
        $this->artisan('api:expire-stuck-generations')->assertSuccessful();
        $this->assertSame(1, DB::table('jobs')->count(), 'the stuck take\'s job is still waiting');
        $this->assertSame(0, Artisan::call('queue:work', ['--once' => true, '--queue' => 'high,default']));
        $this->assertSame(0, DB::table('jobs')->count(), 'a worker reached it');

        // Deleting a finished take keeps what it cost.
        $this->apiDelete($plain, '/history/'.$first->headers->get('X-History-Item-Id'))->assertOk();

        $delivered = 4;
        $this->assertSame($delivered * Credits::forSeconds($this->audioSeconds), $this->creditsSpent($user), 'credits: the four takes delivered');
        $this->assertSame($delivered * $this->audioSeconds, $this->secondsCounted($user), 'seconds: their real length');
        $this->assertSame($delivered, $this->requestsCounted($user), 'requests: failures and the stuck take gave theirs back');
        $this->assertSame([$this->creditsSpent($user), $delivered], [$this->keyCreditsSpent($key), $this->keyRequestsCounted($key)], 'the key\'s own figures agree');
        $this->assertSame(4, $this->speechCalls->gemini, 'each delivered take was spoken once; retries, the failure and the stuck take spoke nothing');
        $this->assertSame(['done', 'done', 'failed', 'done', 'failed'], AudioGeneration::query()->orderBy('id')->pluck('status')->all());
    }

    private function arabicVoice(): Voice
    {
        return $this->premiumVoice([
            'language' => 'ar', 'gender' => 'male', 'display_name' => 'Othman',
            'internal_voice_id' => 'ar-Gemini-HD-Charon', 'provider_voice_id' => 'Charon',
        ]);
    }
}
