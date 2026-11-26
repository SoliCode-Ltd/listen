<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\Api\V1\VoiceController;
use App\Mcp\McpCaller;
use App\Support\Api\ApiScopes;
use App\Support\SpeechLanguages;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The voices the account can use (GET /v1/voices), short enough for an
 * assistant to read: filter by language, gender or a word, a page at a time.
 */
#[Name('list_voices')]
#[Title('List voices')]
#[Description('List the Listen voices this account can use, with their voice_id, language and gender, and a short sample to play. Every voice speaks any dialect or accent of its language (an Arabic voice reads Egyptian, Saudi, Lebanese and the rest): choose a voice here by language and gender, or by name, and give the dialect to text_to_speech. Includes the person\'s own cloned voices on plans with cloning.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ListVoices extends ListenTool
{
    public const PAGE = 40;

    protected function scope(): string
    {
        return ApiScopes::VOICES_READ;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'language' => $schema->string()->enum(SpeechLanguages::codes())
                ->description('Only voices for this language: ar (Arabic) or en (English).'),
            'gender' => $schema->string()->enum(['female', 'male'])
                ->description('Only female or only male voices.'),
            'search' => $schema->string()->max(60)
                ->description('A word to look for in a voice\'s name, for example Sara or Omar. Not a dialect or accent: every voice speaks them all, so leave this out to choose by language and gender.'),
            'offset' => $schema->integer()->min(0)
                ->description('For the next page: how many voices to skip.'),
        ];
    }

    protected function run(Request $request, McpCaller $caller): Response|ResponseFactory
    {
        $input = $request->validate([
            'language' => ['nullable', 'string'],
            'gender' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:60'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);
        $voices = (array) $caller->call(VoiceController::class, 'index', 'GET', '/voices', array_filter([
            'language' => $input['language'] ?? null,
            'gender' => $input['gender'] ?? null,
        ]))['voices'];

        $search = mb_strtolower(trim((string) ($input['search'] ?? '')));
        if ($search !== '') {
            $voices = array_values(array_filter($voices, static fn (array $voice): bool => str_contains(mb_strtolower(implode(' ', [
                $voice['name'] ?? '', $voice['name_ar'] ?? '', $voice['accent'] ?? '',
            ])), $search)));
        }

        $total = count($voices);
        $offset = (int) ($input['offset'] ?? 0);
        $page = array_map(static fn (array $voice): array => [
            'voice_id' => $voice['voice_id'],
            'name' => $voice['name'],
            'language' => $voice['language'],
            'gender' => $voice['gender'],
            'accent' => $voice['accent'] ?? null,
            'category' => $voice['category'],
            'available_on_plan' => $voice['available_on_plan'] ?? true,
            'preview_url' => $voice['preview_url'] ?? null,
        ], array_slice($voices, $offset, self::PAGE));
        $next = $offset + self::PAGE < $total ? $offset + self::PAGE : null;

        $lines = array_map(static fn (array $voice): string => '- '.$voice['voice_id'].': '.$voice['name']
            .' ('.implode(', ', array_filter([$voice['language'], $voice['gender'], $voice['accent'], $voice['category'] === 'cloned' ? 'your cloned voice' : null])).')'
            .($voice['available_on_plan'] ? '' : ' [not on this plan]'), $page);
        // A search for a dialect finds no name: say why, so the assistant does not conclude there is no such voice.
        $text = $page === []
            ? ($search !== ''
                ? 'No voice has "'.$search.'" in its name. Voices are not tied to a dialect or accent: every voice speaks any of its language. Call list_voices again without search (filter by language and gender), choose a voice, and give the dialect code from get_voice_options to text_to_speech.'
                : 'No voices match. Try another language or gender.')
            : $total.' voices'.($total > count($page) ? ', showing '.($offset + 1).' to '.($offset + count($page)) : '').":\n".implode("\n", $lines)
                .($next !== null ? "\nMore: call again with offset = {$next}." : '');

        return Response::make([Response::text($text)])->withStructuredContent([
            'voices' => $page,
            'total_count' => $total,
            'next_offset' => $next,
        ]);
    }
}
