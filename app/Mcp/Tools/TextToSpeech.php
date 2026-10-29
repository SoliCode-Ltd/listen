<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\Api\V1\TextToSpeechController;
use App\Mcp\McpCaller;
use App\Mcp\SpeechLinks;
use App\Mcp\SpeechSummary;
use App\Models\AudioGeneration;
use App\Models\UsageCounter;
use App\Services\Api\ApiSpeechService;
use App\Support\Api\ApiError;
use App\Support\Api\ApiPlanAccess;
use App\Support\Api\ApiPresenter;
use App\Support\Api\ApiScopes;
use App\Support\CreditPeriod;
use App\Support\Credits;
use App\Support\UsageSnapshot;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
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
 * Makes speech as POST /v1/text-to-speech/{voice_id}/async does (the same
 * checks, credits and queue), then waits a little for it: most texts are
 * done by then and come back with a link to play. A longer one comes back
 * with its history item, to check with get_speech. The wait stays well
 * inside the time AI apps give a tool, so the answer never gets lost while
 * the speech is still charged.
 */
#[Name('text_to_speech')]
#[Title('Make speech')]
#[Description('Turn Arabic or English text into speech with a Listen voice. Returns a link the person can play or download for '.SpeechLinks::DAYS.' days. Uses the account\'s credits: '.Credits::PER_SECOND.' credits per second of audio. Pick voice_id with list_voices first; dialect, style and emotion codes come from get_voice_options.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class TextToSpeech extends ListenTool
{
    protected function scope(): string
    {
        return ApiScopes::TEXT_TO_SPEECH;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->required()->max(ApiSpeechService::ASYNC_MAX_TEXT)
                ->description('The words to read aloud, in Arabic or English, exactly as they should be spoken. Up to '.number_format(ApiSpeechService::ASYNC_MAX_TEXT).' characters (less on some plans); split longer texts.'),
            'voice_id' => $schema->string()->required()
                ->description('The voice, from list_voices (for example voice_12). Its language must match the text.'),
            'dialect' => $schema->string()
                ->description('Arabic dialect or English accent code from get_voice_options, for example ar-EG (Egyptian, Cairene), ar-SA (Saudi, Najdi), ar-LB (Lebanese) or en-GB (British). Leave out for the default.'),
            'style' => $schema->string()
                ->description('Delivery style code from get_voice_options, for example narrative, commercial or podcast. Leave out for the default.'),
            'emotion' => $schema->string()
                ->description('Emotion code from get_voice_options, for example warm or excited. Leave out for the default.'),
            'speed' => $schema->number()->min(0.5)->max(2)
                ->description('Speaking speed from 0.5 to 2. Default 1.'),
            'style_prompt' => $schema->string()->max(2000)
                ->description('Your own direction for the delivery, in a sentence or two. Only on plans that include custom direction.'),
            'title' => $schema->string()->max(255)
                ->description('A name for the recording in the person\'s Listen history.'),
            'dialogue' => $schema->array()->min(2)->max(6)
                ->items($schema->object([
                    'name' => $schema->string()->required()->description('The speaker\'s name, as it starts their lines in the text.'),
                    'voice_id' => $schema->string()->required()->description('The speaker\'s voice, from list_voices.'),
                    'dialect' => $schema->string()->description('The speaker\'s own dialect or accent code.'),
                ]))
                ->description('For a conversation between 2 to 6 voices: every line of the text starts with a speaker\'s name and a colon ("Sara: ..."). Only on plans that include dialogue.'),
        ];
    }

    protected function run(Request $request, McpCaller $caller): Response|ResponseFactory
    {
        $input = $request->validate([
            'text' => ['required', 'string'],
            'voice_id' => ['required', 'string', 'max:40'],
            'speed' => ['nullable', 'numeric', 'min:0.5', 'max:2'],
            'dialogue' => ['nullable', 'array'],
        ]);
        $voiceId = trim((string) $input['voice_id']);

        $body = array_filter([
            'text' => $input['text'],
            'dialect' => $request->get('dialect'),
            'style' => $request->get('style'),
            'emotion' => $request->get('emotion'),
            'style_prompt' => $request->get('style_prompt'),
            'title' => $request->get('title'),
            'voice_settings' => isset($input['speed']) ? ['speed' => (float) $input['speed']] : null,
            'dialogue' => ! empty($input['dialogue']) ? ['speakers' => array_values((array) $input['dialogue'])] : null,
        ], static fn ($value): bool => $value !== null && $value !== '');

        $this->throttle($caller);

        $started = microtime(true);
        $item = $caller->call(TextToSpeechController::class, 'convertAsync', 'POST', '/text-to-speech/'.rawurlencode($voiceId).'/async', $body, [$voiceId]);
        $historyItemId = (int) $item['history_item_id'];
        $generation = $this->waitFor($historyItemId, $started + max(0, (int) config('listen.mcp.wait_seconds', 40)));
        $item = ApiPresenter::historyItem($generation);
        $caller->log($this->name(), [
            'history_item_id' => $historyItemId,
            'credits' => (int) $item['credits_used'],
            'characters' => (int) $item['character_count'],
        ]);

        if ($item['status'] === 'failed') {
            $caller->log($this->name(), ['history_item_id' => $historyItemId, 'error_code' => 'generation_failed']);

            return Response::error('generation_failed: '.rtrim((string) ($item['error']['message'] ?? 'Voice generation failed.'), '.').'. No credits were charged. You can try again.');
        }

        $speech = SpeechLinks::withLink($item) + ['credits_remaining' => self::creditsRemaining($generation, $caller)];

        if ($item['status'] !== 'completed') {
            return Response::make([Response::text(implode("\n", [
                'The speech is still being made (history item '.$historyItemId.'). Call get_speech with history_item_id '.$historyItemId.' in a few seconds to get the link.',
                'Credits reserved: '.number_format((int) $item['credits_used']).'. Anything not used is given back.',
            ]))])->withStructuredContent($speech);
        }

        return Response::make([
            Response::text(SpeechSummary::text($speech)),
            Response::resourceLink(
                $speech['audio_url'],
                name: SpeechLinks::fileName($historyItemId, $generation->audio_path),
                mimeType: SpeechLinks::mimeType($generation->audio_path),
                title: SpeechSummary::title($speech),
                description: 'Speech made with Listen. The link works for '.SpeechLinks::DAYS.' days.',
            ),
        ])->withStructuredContent($speech);
    }

    /**
     * The API's limit on speech requests a minute for the account, kept for
     * the MCP server's own requests.
     */
    private function throttle(McpCaller $caller): void
    {
        $key = 'mcp-generate:'.$caller->user->id;
        $limit = ApiPlanAccess::requestsPerMinute($caller->plan);
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $wait = max(1, RateLimiter::availableIn($key));

            throw new ApiError(429, ApiError::TYPE_RATE_LIMIT, 'rate_limit_exceeded',
                "Too many speech requests a minute for this account. Try again in {$wait} seconds.");
        }
        RateLimiter::hit($key, 60);
    }

    /**
     * The generation once it is finished, or as it is when the time is up.
     * Waiting holds a web worker, so only a few calls wait at once for the
     * whole site (listen.mcp.waiting_slots); the others answer straight away.
     */
    private function waitFor(int $historyItemId, float $until): AudioGeneration
    {
        $slot = null;
        try {
            while (true) {
                $generation = AudioGeneration::query()->findOrFail($historyItemId);
                if (in_array($generation->status, ['done', 'failed'], true) || microtime(true) >= $until) {
                    return $generation;
                }
                $slot ??= self::waitingSlot((int) ceil($until - microtime(true)));
                if ($slot === false) {
                    return $generation;
                }
                usleep(750_000);
            }
        } finally {
            if ($slot instanceof Lock) {
                $slot->release();
            }
        }
    }

    private static function waitingSlot(int $seconds): Lock|false
    {
        for ($slot = 1; $slot <= max(0, (int) config('listen.mcp.waiting_slots', 4)); $slot++) {
            $lock = Cache::lock('mcp-wait-slot:'.$slot, max(1, $seconds) + 10);
            if ($lock->get()) {
                return $lock;
            }
        }

        return false;
    }

    private static function creditsRemaining(AudioGeneration $generation, McpCaller $caller): ?int
    {
        $counter = UsageCounter::query()->where('user_id', $generation->user_id)->where('period_ym', CreditPeriod::ofGeneration($generation))->first();

        return $counter ? (int) UsageSnapshot::for((int) $generation->user_id, $caller->quotaPlan, $counter)['remaining_chars'] : null;
    }
}
