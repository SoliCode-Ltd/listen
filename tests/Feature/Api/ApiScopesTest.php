<?php

namespace Tests\Feature\Api;

use App\Models\AudioGeneration;
use App\Models\Voice;
use App\Support\Api\ApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Each endpoint needs one permission, and a key holds only the permissions
 * it was given: a key without an endpoint's permission is refused with 403
 * missing_permission (naming it) and nothing is generated or charged; a key
 * with only that permission is let through.
 */
class ApiScopesTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: int}>
     */
    public static function endpoints(): array
    {
        return [
            'convert text to speech' => ['POST', '/text-to-speech/{voice}', ApiScopes::TEXT_TO_SPEECH, 200],
            'convert in the background' => ['POST', '/text-to-speech/{voice}/async', ApiScopes::TEXT_TO_SPEECH, 202],
            'list voices' => ['GET', '/voices', ApiScopes::VOICES_READ, 200],
            'get a voice' => ['GET', '/voices/{voice}', ApiScopes::VOICES_READ, 200],
            'list voice options' => ['GET', '/voice-options', ApiScopes::VOICES_READ, 200],
            'list models' => ['GET', '/models', ApiScopes::VOICES_READ, 200],
            'list history' => ['GET', '/history', ApiScopes::HISTORY_READ, 200],
            'get a history item' => ['GET', '/history/{item}', ApiScopes::HISTORY_READ, 200],
            'download history audio' => ['GET', '/history/{item}/audio', ApiScopes::HISTORY_READ, 200],
            'delete a history item' => ['DELETE', '/history/{item}', ApiScopes::HISTORY_WRITE, 200],
            'get your account' => ['GET', '/user', ApiScopes::USER_READ, 200],
            'get plan, usage and limits' => ['GET', '/user/subscription', ApiScopes::USER_READ, 200],
        ];
    }

    /**
     * A key holding every permission but the endpoint's own is refused, and
     * the refusal says which permission is missing.
     */
    #[DataProvider('endpoints')]
    public function test_a_key_without_the_endpoints_permission_is_refused(string $method, string $path, string $scope, int $okStatus): void
    {
        $user = $this->apiMember();
        $item = $this->storedGeneration($user);
        $others = array_values(array_diff(ApiScopes::all(), [$scope]));
        $plain = $this->issueKey($user, $others)['plain'];

        $response = $this->callEndpoint($method, $plain, $this->path($path, $item, $this->premiumVoice()));

        $this->assertApiError($response, 403, 'missing_permission', 'permission_error')
            ->assertJsonPath('error.required_permission', $scope)
            ->assertJsonPath('error.param', null);
        $this->assertStringContainsString('"'.$scope.'"', (string) $response->json('error.message'));
        $this->assertSame(1, AudioGeneration::query()->count(), 'nothing is generated or deleted');
        $this->assertSame(0, $this->creditsSpent($user), 'nothing is charged');
        $this->assertSame(0, $this->speechCalls->gemini + $this->speechCalls->google);
    }

    /**
     * A key holding only the endpoint's permission is let through.
     */
    #[DataProvider('endpoints')]
    public function test_a_key_with_only_the_endpoints_permission_is_let_through(string $method, string $path, string $scope, int $okStatus): void
    {
        $user = $this->apiMember();
        $item = $this->storedGeneration($user);
        $plain = $this->issueKey($user, [$scope])['plain'];

        $this->callEndpoint($method, $plain, $this->path($path, $item, $this->premiumVoice()))->assertStatus($okStatus);
    }

    /**
     * A key made without a list of permissions holds them all.
     */
    public function test_a_key_made_without_a_permission_list_holds_every_permission(): void
    {
        $user = $this->apiMember();
        $item = $this->storedGeneration($user);
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user);
        $this->assertNull($key->scopes);
        $voice = $this->premiumVoice();

        foreach (self::endpoints() as [$method, $path, , $okStatus]) {
            $this->callEndpoint($method, $plain, $this->path($path, $item, $voice))->assertStatus($okStatus);
        }
    }

    /**
     * Permissions are kept in a fixed, known list: unknown names are dropped
     * and names are matched without regard to case or spaces.
     */
    public function test_only_known_permissions_are_kept_on_a_key(): void
    {
        ['key' => $key] = $this->issueKey($this->apiMember(), [' USER_READ ', 'admin', 'text_to_speech', 'text_to_speech']);

        $this->assertSame([ApiScopes::TEXT_TO_SPEECH, ApiScopes::USER_READ], $key->fresh()->scopes);
    }

    private function path(string $template, AudioGeneration $item, Voice $voice): string
    {
        return strtr($template, [
            '{voice}' => self::voiceId($voice),
            '{item}' => (string) $item->id,
        ]);
    }

    private function callEndpoint(string $method, string $plainKey, string $path): TestResponse
    {
        return match ($method) {
            'POST' => $this->apiPost($plainKey, $path, ['text' => 'Hello there, this is a test.']),
            'DELETE' => $this->apiDelete($plainKey, $path),
            default => $this->apiGet($plainKey, $path),
        };
    }
}
