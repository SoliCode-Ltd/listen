<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\Api\V1\TranscriptionController;
use App\Mcp\McpCaller;
use App\Services\Transcription\TranscriptEditor;
use App\Services\Transcription\TranscriptExport;
use App\Support\Api\ApiScopes;
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
 * One transcript (GET /v1/transcriptions/{id}): whether it is ready, and its
 * words as text with the speakers, or as SRT or VTT subtitles, in its own
 * language or translated. A long one comes in pieces (from_character).
 */
#[Name('get_transcription')]
#[Title('Get transcription')]
#[Description('Check on a transcript by its transcription_id: whether it is still being written out, and once completed its text with who said what. Ask for format "srt" or "vtt" for subtitles with times, and translate_to (such as "en" or "ar") for it in another language (translating uses no minutes).')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetTranscription extends ListenTool
{
    /** The most characters one answer carries; the rest comes with from_character. */
    public const PIECE = 60000;

    protected function scope(): string
    {
        return ApiScopes::SPEECH_TO_TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'transcription_id' => $schema->string()->required()
                ->description('The transcription_id that transcribe_audio or list_transcriptions returned (it starts with "tr_").'),
            'format' => $schema->string()->enum(['text', 'srt', 'vtt'])
                ->description('"text" (default): paragraphs with the speakers. "srt" or "vtt": subtitles with times.'),
            'translate_to' => $schema->string()
                ->description('A language code to have it in another language, such as "en", "ar" or "fr". Leave it out for the language spoken.'),
            'from_character' => $schema->integer()->min(0)
                ->description('For a long transcript: where the next piece starts, as the previous answer said.'),
        ];
    }

    protected function run(Request $request, McpCaller $caller): Response|ResponseFactory
    {
        $input = $request->validate([
            'transcription_id' => ['required', 'string', 'max:40'],
            'format' => ['nullable', 'string', 'in:text,srt,vtt'],
            'translate_to' => ['nullable', 'string', 'max:8'],
            'from_character' => ['nullable', 'integer', 'min:0'],
        ]);
        $id = trim((string) $input['transcription_id']);
        $item = $caller->call(TranscriptionController::class, 'show', 'GET', '/transcriptions/'.rawurlencode($id), [], [$id]);
        $caller->log($this->name());

        if ($item['status'] === 'failed') {
            return Response::make([Response::text('This transcript failed: '.($item['error']['message'] ?? 'it could not be written out.').' It used none of the minutes.')])
                ->withStructuredContent($item);
        }
        if ($item['status'] !== 'completed') {
            return Response::make([Response::text('Still being written out ('.$item['status'].', '.$item['progress'].'%). Check again in a minute.')])
                ->withStructuredContent(array_intersect_key($item, array_flip(['transcription_id', 'title', 'status', 'progress', 'duration_seconds'])));
        }

        $format = (string) ($input['format'] ?? 'text');
        $translate = strtolower(trim((string) ($input['translate_to'] ?? '')));
        if ($format === 'text' && $translate === '') {
            $words = (string) $item['text'];
        } else {
            $query = array_filter(['format' => $format === 'text' ? 'txt' : $format, 'translate_to' => $translate], static fn ($v): bool => $v !== '');
            $file = app(TranscriptionController::class)->export($caller->request('GET', '/transcriptions/'.rawurlencode($id).'/export', $query), $id,
                app(TranscriptExport::class), app(TranscriptEditor::class));
            $words = rtrim(preg_replace('/^\x{FEFF}/u', '', (string) $file->getContent()) ?? '');
        }

        $from = min((int) ($input['from_character'] ?? 0), mb_strlen($words));
        $piece = mb_substr($words, $from, self::PIECE);
        $next = $from + mb_strlen($piece) < mb_strlen($words) ? $from + mb_strlen($piece) : null;
        $head = '"'.$item['title'].'": '.($item['language_code'] ? 'language '.$item['language_code'].', ' : '')
            .$item['speaker_count'].' '.($item['speaker_count'] === 1 ? 'speaker' : 'speakers').', '.round((float) $item['duration_seconds']).' s.'
            .($translate !== '' ? ' Translated to '.$translate.'.' : '');
        $tail = $next !== null ? "\n\n[Cut here: call again with from_character = {$next} for the rest.]" : '';

        unset($item['text'], $item['paragraphs'], $item['download_urls']);

        return Response::make([Response::text($head."\n\n".$piece.$tail)])
            ->withStructuredContent($item + ['format' => $format, 'from_character' => $from, 'next_from_character' => $next]);
    }
}
