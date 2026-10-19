<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AudioGeneration;
use App\Models\Voice;
use App\Services\Api\ApiSpeechService;
use App\Support\Api\ApiError;
use App\Support\Api\ApiPresenter;
use App\Support\AudioFormats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /v1/history, GET|DELETE /v1/history/{id}, GET /v1/history/{id}/audio
 *
 * Every generation the member made (API, dashboard and extension), newest
 * first, as in the dashboard's history. Demo clips are left out.
 */
class HistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'before' => ['nullable', 'integer', 'min:1'],
            'voice_id' => ['nullable', 'string', 'max:40'],
            'source' => ['nullable', 'string', 'in:api,app'],
            'status' => ['nullable', 'string', 'in:queued,processing,completed,failed'],
        ]);
        $pageSize = (int) ($filters['page_size'] ?? 30);

        $query = $this->historyQuery($request)->orderByDesc('id');
        if (isset($filters['before'])) {
            $query->where('id', '<', (int) $filters['before']);
        }
        if (isset($filters['source'])) {
            $filters['source'] === 'api' ? $query->whereNotNull('api_key_id') : $query->whereNull('api_key_id');
        }
        if (isset($filters['status'])) {
            $query->where('status', match ($filters['status']) {
                'completed' => 'done',
                default => $filters['status'],
            });
        }
        if (isset($filters['voice_id'])) {
            $voiceName = $this->voiceNameForId((string) $filters['voice_id']);
            $voiceName === null ? $query->whereRaw('1 = 0') : $query->where('voice_name', $voiceName);
        }

        $rows = $query->with('textItem:id,title')->limit($pageSize + 1)->get();
        $hasMore = $rows->count() > $pageSize;
        $rows = $rows->take($pageSize);

        return response()->json([
            'history' => ApiPresenter::historyItems($rows),
            'has_more' => $hasMore,
            'next_cursor' => $hasMore ? (int) $rows->last()->id : null,
        ]);
    }

    public function show(Request $request, string $historyItemId): JsonResponse
    {
        return response()->json(ApiPresenter::historyItem($this->find($request, $historyItemId)));
    }

    public function audio(Request $request, string $historyItemId): Response
    {
        $generation = $this->find($request, $historyItemId);
        if ($generation->status !== 'done' || blank($generation->audio_path)) {
            throw ApiError::invalid('audio_not_ready', 'This history item has no audio yet (status: '.ApiPresenter::status($generation->status).').', 'history_item_id', 409);
        }

        $format = strtolower((string) $request->query('output_format', 'mp3'));
        $plan = $request->attributes->get('api_plan');
        app(ApiSpeechService::class)->assertOutputFormat($format, $plan);

        // Turning the file into another type runs inside a slot, so downloads cannot pile up on the server.
        $audio = TextToSpeechController::needsConversion($generation, $format)
            ? app(TextToSpeechController::class)->withSlot($request, $plan, fn () => TextToSpeechController::audioBytes($generation, $format))
            : TextToSpeechController::audioBytes($generation, $format);
        if ($audio === null) {
            throw new ApiError(410, ApiError::TYPE_INVALID_REQUEST, 'audio_expired', 'The audio file for this history item is no longer stored.', 'history_item_id');
        }

        return response($audio['bytes'], 200, [
            'Content-Type' => AudioFormats::ALL[$audio['format']]['mime'],
            'Content-Length' => (string) strlen($audio['bytes']),
            'Content-Disposition' => 'inline; filename="listen-'.$generation->id.'.'.$audio['format'].'"',
            'X-History-Item-Id' => (string) $generation->id,
            'X-Output-Format' => $audio['format'],
        ]);
    }

    public function destroy(Request $request, string $historyItemId): JsonResponse
    {
        $generation = $this->find($request, $historyItemId);
        if (in_array($generation->status, ['queued', 'processing'], true)) {
            throw ApiError::invalid('history_item_in_progress', 'This generation is still running. Delete it once it has finished.', 'history_item_id', 409);
        }

        $id = (int) $generation->id;
        // As the dashboard's delete: the row, its audio and its text go; credits already spent stay spent.
        $generation->deleteWithAudio();

        return response()->json(['history_item_id' => $id, 'deleted' => true]);
    }

    /**
     * @return Builder<AudioGeneration>
     */
    private function historyQuery(Request $request): Builder
    {
        return AudioGeneration::query()
            ->where('user_id', $request->user()->id)
            ->where(function (Builder $query): void {
                $query->whereDoesntHave('textItem')
                    ->orWhereHas('textItem', static function (Builder $textItem): void {
                        $textItem->where('source_type', '<>', 'demo');
                    });
            });
    }

    private function find(Request $request, string $historyItemId): AudioGeneration
    {
        if (! ctype_digit($historyItemId)) {
            throw ApiError::notFound('history_item', 'history_item_id');
        }

        $generation = $this->historyQuery($request)->whereKey((int) $historyItemId)->first();
        if (! $generation instanceof AudioGeneration) {
            throw ApiError::notFound('history_item', 'history_item_id');
        }

        return $generation;
    }

    private function voiceNameForId(string $voiceId): ?string
    {
        if (preg_match('/^cloned_(\d+)$/', $voiceId, $m) === 1) {
            return 'cloned_'.(int) $m[1];
        }
        if (preg_match('/^voice_(\d+)$/', $voiceId, $m) === 1) {
            $name = Voice::query()->whereKey((int) $m[1])->value('internal_voice_id');

            return $name !== null ? (string) $name : null;
        }

        return null;
    }
}
