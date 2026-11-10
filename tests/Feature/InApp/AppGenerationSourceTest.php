<?php

namespace Tests\Feature\InApp;

use App\Models\AudioGeneration;
use App\Support\GenerationSource;
use App\Support\InApp\InApp;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\InteractsWithDeveloperApi;
use Tests\TestCase;

/**
 * A voice made in the studio inside the Android app is recorded as the app's
 * ("Mobile app" in the admin), not as the website's studio.
 */
class AppGenerationSourceTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    private const APP_UA = 'Mozilla/5.0 (Linux; Android 14; SM-A546E Build/UP1A; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/130.0.0.0 Mobile Safari/537.36 ListenApp/1.0.0';

    private const BROWSER_UA = 'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
    }

    public function test_a_voice_made_in_the_app_is_recorded_as_the_apps_and_one_made_on_the_website_as_the_studios(): void
    {
        $member = $this->apiMember($this->apiPlan(['max_chars_per_request' => 1000]));
        $voiceKey = VoiceCatalog::keyFromId((int) $this->premiumVoice()->id);
        $source = function (array $headers, array $cookies = []) use ($member, $voiceKey): ?string {
            $this->actingAs($member)->withHeaders($headers)->withCredentials()->withUnencryptedCookies($cookies)->postJsonLocalizedRoute('dashboard.queue', [
                'content' => 'Hello from the studio.',
                'target_language' => 'en',
                'voice_tier' => 'hd',
                'voice_key' => $voiceKey,
            ])->assertOk();
            $this->flushHeaders();
            $this->defaultCookies = [];
            $this->unencryptedCookies = [];

            return AudioGeneration::query()->where('user_id', $member->id)->latest('id')->firstOrFail()->textItem?->source_type;
        };

        $this->assertSame(GenerationSource::APP, $source(['User-Agent' => self::APP_UA]));
        // A request of the app's that does not carry its name is still the app's (InApp::COOKIE).
        $this->assertSame(GenerationSource::APP, $source(['User-Agent' => self::BROWSER_UA], [InApp::COOKIE => '1']));
        $this->assertSame(GenerationSource::STUDIO, $source(['User-Agent' => self::BROWSER_UA]));
    }

    public function test_the_admin_names_the_app_as_its_own_source(): void
    {
        $this->assertSame('Mobile app', GenerationSource::label(GenerationSource::APP));
        $this->assertSame('Studio', GenerationSource::label(GenerationSource::STUDIO));
        $this->assertArrayHasKey(GenerationSource::APP, GenerationSource::labels(), 'the admin can filter by it');
        $this->assertTrue(GenerationSource::isStudio(GenerationSource::APP));
        $this->assertFalse(GenerationSource::isStudio(GenerationSource::EXTENSION));
    }
}
