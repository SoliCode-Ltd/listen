<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The old server-to-server reader key (LISTEN_API_KEY) acts as the owner's
 * account, so it is accepted in a header only, never in a link, where it
 * would end up in browser history and server logs.
 */
class LegacyReaderKeyTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY = 'legacy-reader-key-for-tests-only';

    protected function setUp(): void
    {
        parent::setUp();

        putenv('LISTEN_API_KEY='.self::LEGACY);
        $_ENV['LISTEN_API_KEY'] = $_SERVER['LISTEN_API_KEY'] = self::LEGACY;
    }

    protected function tearDown(): void
    {
        putenv('LISTEN_API_KEY');
        unset($_ENV['LISTEN_API_KEY'], $_SERVER['LISTEN_API_KEY']);

        parent::tearDown();
    }

    public function test_the_legacy_key_works_in_a_header_only(): void
    {
        User::factory()->create();

        $this->getJson('/api/reader/profile?api_key='.self::LEGACY)->assertUnauthorized();
        $this->postJson('/api/reader/queue', ['api_key' => self::LEGACY, 'text' => 'Hello'])->assertUnauthorized();

        // (Headers set here stay on the requests after it, so this one goes last.)
        $this->withHeaders(['X-Listen-Key' => self::LEGACY])->getJson('/api/reader/profile')->assertOk();
    }

    public function test_a_members_own_key_still_works_in_the_link(): void
    {
        User::factory()->create();
        $member = User::factory()->create();
        $key = $member->issueApiKey();

        $this->getJson('/api/reader/profile?api_key='.$key)->assertOk();
    }
}
