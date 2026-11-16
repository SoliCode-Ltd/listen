<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\Api\V1\TranscriptionController;
use App\Mcp\McpCaller;
use App\Support\Api\ApiScopes;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
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
 * The member's transcripts, newest first (GET /v1/transcriptions): the ones
 * made here, through the API and on the Listen page.
 */
#[Name('list_transcriptions')]
#[Title('List transcriptions')]
#[Description('List the person\'s speech to text transcripts, newest first, with their status and the minutes each used. Includes those made on the Listen website. Use get_transcription for the words of one.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ListTranscriptions extends ListenTool
{
    public const MAX = 20;

    protected function scope(): string
    {
        return ApiScopes::SPEECH_TO_TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->min(1)->max(self::MAX)
                ->description('How many to list, up to '.self::MAX.'. Default 10.'),
            'before' => $schema->string()
                ->description('For the next page: the next_cursor from the previous answer.'),
        ];
    }

    protected function run(Request $request, McpCaller $caller): Response|ResponseFactory
    {
        $input = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX],
            'before' => ['nullable', 'string', 'max:40'],
        ]);
        $page = $caller->call(TranscriptionController::class, 'index', 'GET', '/transcriptions', array_filter([
            'page_size' => (int) ($input['limit'] ?? 10),
            'before' => $input['before'] ?? null,
        ]));
        $caller->log($this->name());

        $items = (array) $page['transcriptions'];
        $lines = array_map(static fn (array $item): string => '- '.$item['transcription_id'].' '.($item['created_at'] ?? '').' '.$item['status']
            .', '.round((float) $item['duration_seconds']).' s'
            .($item['language_code'] !== null ? ', '.$item['language_code'] : '')
            .': "'.Str::limit((string) $item['title'], 60).'"', $items);

        $text = $items === []
            ? 'No transcripts yet.'
            : implode("\n", $lines).($page['has_more'] ? "\nMore: call again with before = ".$page['next_cursor'].'.' : '');

        return Response::make([Response::text($text)])->withStructuredContent([
            'transcriptions' => $items,
            'has_more' => (bool) $page['has_more'],
            'next_cursor' => $page['next_cursor'],
        ]);
    }
}
