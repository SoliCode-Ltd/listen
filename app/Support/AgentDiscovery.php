<?php

namespace App\Support;

use App\Services\Api\ApiSpeechService;
use App\Support\Api\ApiScopes;
use Illuminate\Support\Facades\Route;

/**
 * What the site publishes so AI agents can find and use the developer API:
 *
 * - the API catalog at /.well-known/api-catalog (RFC 9727): where the API is,
 *   its OpenAPI description, its guide and its health check;
 * - an agent skill at /.well-known/agent-skills/ (Agent Skills Discovery
 *   v0.2.0): short instructions an agent loads to make speech with the API,
 *   listed in an index with its SHA-256 digest;
 * - the ARD catalog at /.well-known/ai-catalog.json: every one of these,
 *   with the questions each answers;
 * - /auth.md: how an agent gets a key. A person makes it in the dashboard;
 *   there is no OAuth server and no self-registration, and nothing here says
 *   otherwise;
 * - the MCP server at /mcp (routes/ai.php), once laravel/mcp is installed:
 *   in each of the above, with its server card (App\Mcp\ServerCard).
 *
 * Kept in step with routes/v1.php and App\Support\Api\OpenApiSpec.
 */
class AgentDiscovery
{
    /** Whether the MCP server is running here (its package is installed and its routes loaded). */
    public static function mcpAvailable(): bool
    {
        return Route::has('mcp');
    }

    public const SKILL = 'listen-text-to-speech';

    public const SKILLS_SCHEMA = 'https://schemas.agentskills.io/discovery/0.2.0/schema.json';

    public const CATALOG_PROFILE = 'https://www.rfc-editor.org/info/rfc9727';

    /** The ARD catalog's data model version (ai-catalog), not the ARD draft's. */
    public const AI_CATALOG_VERSION = '1.0';

    /** @return array{linkset: list<array<string, mixed>>} */
    public static function apiCatalog(): array
    {
        $linkset = [[
            'anchor' => url('/v1'),
            'service-desc' => [['href' => url('/v1/openapi.json'), 'type' => 'application/vnd.oai.openapi+json']],
            'service-doc' => [
                ['href' => url('/en/developers'), 'type' => 'text/html', 'hreflang' => 'en'],
                ['href' => url('/ar/developers'), 'type' => 'text/html', 'hreflang' => 'ar'],
            ],
            'status' => [['href' => url('/up'), 'type' => 'text/html']],
        ]];

        if (self::mcpAvailable()) {
            $linkset[] = [
                'anchor' => url('/mcp'),
                'service-desc' => [['href' => url('/mcp/server-card'), 'type' => 'application/mcp-server-card+json']],
                'service-doc' => [
                    ['href' => url('/en/developers').'#mcp', 'type' => 'text/html', 'hreflang' => 'en'],
                    ['href' => url('/ar/developers').'#mcp', 'type' => 'text/html', 'hreflang' => 'ar'],
                ],
                'status' => [['href' => url('/up'), 'type' => 'text/html']],
            ];
        }

        return ['linkset' => $linkset];
    }

    /**
     * The ARD (Agentic Resource Discovery) catalog: what an agent can use here,
     * each with the kind of request it serves.
     *
     * @return array{specVersion: string, host: array<string, string>, entries: list<array<string, mixed>>}
     */
    public static function aiCatalog(): array
    {
        $domain = (string) parse_url(url('/'), PHP_URL_HOST);
        $entry = static fn (string $kind, string $name, string $title, string $type, string $url, string $description, array $queries): array => [
            'identifier' => "urn:air:{$domain}:{$kind}:{$name}",
            'displayName' => $title,
            'type' => $type,
            'url' => $url,
            'description' => $description,
            'representativeQueries' => $queries,
        ];

        return [
            'specVersion' => self::AI_CATALOG_VERSION,
            'host' => [
                'displayName' => SeoHelper::siteName('en'),
                'identifier' => rtrim(url('/'), '/'),
            ],
            'entries' => [
                $entry('api', 'text-to-speech', 'Listen text to speech API', 'application/vnd.oai.openapi+json', url('/v1/openapi.json'),
                    'Arabic and English text to speech over HTTP: voices, Arabic dialects, English accents, emotions, styles and two-voice dialogue. Needs an API key from a Listen account.',
                    ['turn Arabic text into speech in the Egyptian dialect', 'make an English voiceover with a British accent', 'list Arabic voices and dialects', 'generate a two-voice dialogue as audio']),
                ...(self::mcpAvailable() ? [
                    $entry('mcp', 'listen', 'Listen MCP server', 'application/mcp-server-card+json', url('/mcp/server-card'),
                        'An MCP server for AI apps: list voices and dialects, make Arabic or English speech and get a link to play it. Signed in with a Listen API key.',
                        ['make an Arabic voiceover from this script', 'read this text aloud in the Saudi dialect', 'how many Listen credits do I have left']),
                ] : []),
                $entry('skill', self::SKILL, 'Listen text to speech skill', 'text/markdown', url('/.well-known/agent-skills/'.self::SKILL.'/SKILL.md'),
                    self::skillDescription(),
                    ['how do I make Arabic speech with the Listen API', 'which headers does the Listen API need', 'how do I generate long Arabic audio in the background']),
                $entry('catalog', 'api', 'Listen API catalog', 'application/linkset+json', url('/.well-known/api-catalog'),
                    'Where the Listen API is, its OpenAPI description, its guide in English and Arabic, and its health check (RFC 9727).',
                    ['where is the Listen API documentation', 'is the Listen API up']),
                $entry('docs', 'llms', 'About Listen for AI assistants', 'text/plain', url('/llms.txt'),
                    'What the Listen voice studio does, its official pages, its Arabic dialects and English accents.',
                    ['what is Listen', 'which Arabic dialects does Listen support', 'how much does Listen cost']),
            ],
        ];
    }

    /** /auth.md: how an agent gets and uses credentials for the API, all true today. */
    public static function authMarkdown(): string
    {
        $base = url('/v1');
        $register = url('/en/register');
        $guide = url('/en/developers');
        $skill = url('/.well-known/agent-skills/'.self::SKILL.'/SKILL.md');
        $scopes = implode("\n", array_map(static fn (string $scope): string => '  - `'.$scope.'`', ApiScopes::all()));
        $mcp = self::mcpAvailable() ? "\n## MCP server\n\nAI apps that support MCP can connect to `".url('/mcp').'` (Streamable HTTP) with the same key, sent as `Authorization: Bearer <key>`. '
            .'The tools a key shows are the ones its permissions allow. Server card: '.url('/mcp/server-card')."\n" : '';

        return <<<MD
# Listen auth.md

How AI agents and the developers who run them get access to the Listen text to speech API at `{$base}`.

## Who registers

A person, not the agent. There is no endpoint where an agent can create an account or a key for itself, and no OAuth sign-in. Ask the person you work for to:

1. Create a Listen account at {$register} and choose a plan that includes the API.
2. Open the dashboard's Developer API section and create an API key. Each key can be limited to some permissions and to a monthly number of credits.
3. Give you the key in a safe way, such as an environment variable named `LISTEN_API_KEY`.

## Credentials

- Type: API key. Keys start with `lsk_live_`.
- Send it as `X-Listen-Api-Key: <key>` or `Authorization: Bearer <key>` on every request. Nothing else is needed: no sessions, cookies or token exchange.
- Permissions (scopes) a key can have:
{$scopes}
- A request the key is not allowed to make is refused with 403; a missing, wrong or revoked key gets 401.
{$mcp}
## Keeping it safe

- Never print, log or commit the key.
- The person can revoke a key in the dashboard at any time; make a new one there to rotate it.
- Every generation is charged to the account's credits, the same balance as the web studio.

## More

- Every endpoint and field: {$base}/openapi.json
- Guide: {$guide}
- Agent skill: {$skill}

MD;
    }

    public static function skillDescription(): string
    {
        // No ": " in it: it is a plain YAML value in the skill's front matter.
        return 'Turn Arabic and English text into natural speech with the Listen API (listenai.studio). Choose a voice, '
            .'an Arabic dialect or English accent, an emotion and a delivery style, then save the audio. Use when someone '
            .'wants an Arabic voiceover, speech in a particular Arabic dialect, English narration or a two-voice dialogue made with Listen.';
    }

    /** @return array{"$schema": string, skills: list<array<string, string>>} */
    public static function skillsIndex(): array
    {
        return [
            '$schema' => self::SKILLS_SCHEMA,
            'skills' => [[
                'name' => self::SKILL,
                'type' => 'skill-md',
                'description' => self::skillDescription(),
                'url' => '/.well-known/agent-skills/'.self::SKILL.'/SKILL.md',
                'digest' => 'sha256:'.hash('sha256', self::skillMarkdown()),
            ]],
        ];
    }

    /** The skill: what an agent needs to make speech with the API, and where the rest is. */
    public static function skillMarkdown(): string
    {
        $skillDescription = self::skillDescription();
        $base = url('/v1');
        $guide = url('/en/developers');
        $sync = number_format(ApiSpeechService::SYNC_MAX_TEXT);
        $async = number_format(ApiSpeechService::ASYNC_MAX_TEXT);
        $dialogue = number_format(ApiSpeechService::DIALOGUE_MAX_TEXT);
        $perSecond = Credits::PER_SECOND;
        $voices = StudioFacts::voices();
        $range = $voices > 0
            ? $voices.' voices, '.StudioFacts::arabicDialects().' Arabic dialects and '.StudioFacts::englishAccents().' English accents'
            : 'studio voices, Arabic dialects and English accents';
        $mcp = self::mcpAvailable()
            ? '- An app that supports MCP can connect to `'.url('/mcp').'` with the same key instead, sent as `Authorization: Bearer <key>`: its tools list voices, make speech and return a link to play it.'."\n"
            : '';

        return <<<MD
---
name: listen-text-to-speech
description: {$skillDescription}
---

# Listen text to speech

Listen turns text into natural Arabic and English speech: {$range}, with emotions, delivery styles and two-voice dialogue.

## Before you start

- The person needs a Listen account on a plan that includes the API, and an API key from the dashboard's Developer API section. Keys start with `lsk_live_`.
- Read the key from an environment variable such as `LISTEN_API_KEY`. Never print it, log it or write it into code.
- Every generation is charged to the account's credits, the same balance as the web studio: {$perSecond} credits per second of audio. Check what is left with `GET /v1/user/subscription` before a long job.

## Connect

- Base URL: `{$base}`
- Send the key as `X-Listen-Api-Key: <key>` or `Authorization: Bearer <key>`.
- Every endpoint and field: `{$base}/openapi.json` (no key needed). Guide: {$guide}
{$mcp}
## 1. Choose a voice

```bash
curl -s {$base}/voices -H "X-Listen-Api-Key: \$LISTEN_API_KEY"
curl -s {$base}/voice-options -H "X-Listen-Api-Key: \$LISTEN_API_KEY"
```

`/voices` lists each voice's `voice_id`, name, language and gender. `/voice-options` lists the dialects and accents (such as `ar-EG`, `ar-SA`, `ar-LB`, `en-US`, `en-GB`), the styles and the emotions.

## 2. Make speech

Up to {$sync} characters: wait for the file.

```bash
curl -s -X POST "{$base}/text-to-speech/VOICE_ID?output_format=mp3" \
  -H "X-Listen-Api-Key: \$LISTEN_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"text": "أهلاً بكم في إسمع", "dialect": "ar-EG", "emotion": "warm", "style": "narrative"}' \
  --output speech.mp3
```

- Only `text` is required. `dialect` picks the Arabic dialect or English accent; `emotion` and `style` shape the delivery; with `"translate": true` the text is first translated into `language_code`.
- The answer is the audio itself. Its headers say what it cost and where to find it again: `X-Credits-Used`, `X-Credits-Remaining`, `X-Audio-Duration-Seconds` and `X-History-Item-Id`.
- `mp3` is always accepted; other formats only on plans that include them.
- To retry safely, send an `Idempotency-Key` header of your own: the same key and body within 24 hours return the first result and are not charged again.

## 3. Long texts

Up to {$async} characters: make it in the background.

1. `POST /v1/text-to-speech/VOICE_ID/async` with the same body. It answers at once (202) with a history item.
2. Poll `GET /v1/history/{id}` every few seconds until `status` is `completed` or `failed`.
3. Download it with `GET /v1/history/{id}/audio`.

For a longer text, split it at paragraph ends and join the files.

## Dialogue

On plans that include it, up to {$dialogue} characters: start each line with a speaker's name and a colon, and give each speaker a voice.

```json
{"text": "Sara: Welcome back!\\nOmar: Thanks, it's great to be here.", "dialogue": {"speakers": [{"name": "Sara", "voice_id": "VOICE_ID"}, {"name": "Omar", "voice_id": "OTHER_VOICE_ID"}]}}
```

## When something goes wrong

Errors are JSON: `{"error": {"type": "...", "code": "...", "message": "...", "param": null, "request_id": "..."}}`.

- 401: the key is missing, wrong or revoked.
- 402: not enough credits, or the plan's monthly request limit is reached. Tell the person; they can upgrade or wait for the next period.
- 403 `feature_not_in_plan`: the option needs another plan, and the message names the plans that include it. Leave the option out or tell the person.
- 422: a field is not valid; `param` names it.
- 429: too many requests or background jobs at once. Wait as long as `Retry-After` says, then try again.
- 502: the generation failed and nothing was charged; 503: briefly unavailable. Try again later with the same `Idempotency-Key`.

MD;
    }
}
