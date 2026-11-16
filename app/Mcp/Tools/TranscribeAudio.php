<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\Api\V1\TranscriptionController;
use App\Mcp\McpCaller;
use App\Support\Api\ApiError;
use App\Support\Api\ApiPlanAccess;
use App\Support\Api\ApiScopes;
use App\Support\Transcription\Minutes;
use App\Support\Transcription\TranscriptionSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Speech to text from a link to an audio or video file (POST /v1/transcriptions):
 * the same plan rules and minutes as the page. It is written out in the
 * background; get_transcription gives the words once it is completed.
 */
#[Name('transcribe_audio')]
#[Title('Transcribe audio')]
#[Description('Turn the speech in an audio or video file into text, with who said what. Give a public link to the file (MP3, WAV, M4A, OGG, FLAC, WEBM or MP4). It uses the person\'s speech to text minutes for every second of the file and is written out in the background: call get_transcription with the transcription_id it returns until its status is completed.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld]
class TranscribeAudio extends ListenTool
{
    protected function scope(): string
    {
        return ApiScopes::SPEECH_TO_TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'audio_url' => $schema->string()->required()
                ->description('A public http(s) link straight to the audio or video file (not a web page that plays it).'),
            'language_code' => $schema->string()
                ->description('The language spoken, such as "ar" or "en". Leave it out to detect it: '.implode(', ', TranscriptionSettings::languages()).'.'),
            'title' => $schema->string()->description('A name for the transcript in the person\'s list. Defaults to the file name.'),
        ];
    }

    protected function run(Request $request, McpCaller $caller): Response|ResponseFactory
    {
        $input = $request->validate([
            'audio_url' => ['required', 'string', 'max:2048'],
            'language_code' => ['nullable', 'string', 'max:8'],
            'title' => ['nullable', 'string', 'max:200'],
        ]);
        $this->throttle($caller);

        $item = $caller->call(TranscriptionController::class, 'create', 'POST', '/transcriptions', array_filter([
            'url' => trim((string) $input['audio_url']),
            'language_code' => $input['language_code'] ?? null,
            'title' => $input['title'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== ''));
        $caller->log($this->name());

        $usage = (array) ($item['usage'] ?? []);
        $text = 'Started "'.$item['title'].'" ('.Minutes::format((int) round((float) $item['duration_seconds']), 'en').' of audio, '
            .Minutes::format((int) $item['seconds_used'], 'en').' of speech to text minutes used; '
            .Minutes::format((int) ($usage['seconds_left'] ?? 0), 'en', false).' left this month). '
            .'transcription_id: '.$item['transcription_id'].'. It is being written out now: call get_transcription with this id in a minute (longer files take a few minutes).';

        return Response::make([Response::text($text)])->withStructuredContent($item);
    }

    /** As for speech: a few starts a minute for the account, at its plan's rate. */
    private function throttle(McpCaller $caller): void
    {
        $key = 'mcp-generate:'.$caller->user->id;
        $limit = ApiPlanAccess::requestsPerMinute($caller->plan);
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $wait = max(1, RateLimiter::availableIn($key));

            throw new ApiError(429, ApiError::TYPE_RATE_LIMIT, 'rate_limit_exceeded',
                "Too many requests a minute for this account. Try again in {$wait} seconds.");
        }
        RateLimiter::hit($key, 60);
    }
}
