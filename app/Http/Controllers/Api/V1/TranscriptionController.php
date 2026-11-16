<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TranscribeController;
use App\Models\ApiKey;
use App\Models\Transcription;
use App\Models\User;
use App\Services\Transcription\AudioLink;
use App\Services\Transcription\TranscriptEditor;
use App\Services\Transcription\TranscriptExport;
use App\Services\Transcription\TranscriptionFlow;
use App\Services\Transcription\TranscriptionRefused;
use App\Support\Api\ApiError;
use App\Support\GenerationSource;
use App\Support\Transcription\TranscriptionApi;
use App\Support\Transcription\TranscriptionSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /v1/transcriptions                      → 202 and a transcription to poll
 * GET  /v1/transcriptions                      → the member's transcriptions, newest first
 * GET  /v1/transcriptions/{id}                 → one, with its words once completed
 * GET  /v1/transcriptions/{id}/export?format=  → as a TXT, SRT, VTT or DOCX file
 *
 * Speech to text through the same flow as the page (TranscriptionFlow): the
 * same plan rules, minutes and refusals, except the free try, which is on the
 * page only. The audio is sent as a file (multipart "file") or as a link to
 * one ("url"); it is written out in the background.
 */
class TranscriptionController extends Controller
{
    public function __construct(private readonly TranscriptionFlow $flow, private readonly AudioLink $links) {}

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['nullable', 'file', 'required_without:url'],
            'url' => ['nullable', 'string', 'max:2048', 'required_without:file'],
            'language_code' => ['nullable', 'string', 'max:8'],
            'title' => ['nullable', 'string', 'max:200'],
        ]);
        $language = strtolower(trim((string) ($data['language_code'] ?? 'auto'))) ?: 'auto';
        if ($language !== 'auto' && ! in_array($language, TranscriptionSettings::languages(), true)) {
            throw ApiError::invalid('unsupported_language', 'language_code must be "auto" or one of: '.implode(', ', TranscriptionSettings::languages()).'.', 'language_code');
        }

        /** @var User $user */
        $user = $request->user();
        $apiKey = $request->attributes->get('api_key');
        $source = $request->attributes->get('listen_source') === GenerationSource::MCP ? 'mcp' : 'api';
        $meta = ['language' => $language, 'source' => $source, 'api_key_id' => $apiKey instanceof ApiKey ? $apiKey->id : null];

        Storage::disk(TranscriptionFlow::DISK)->makeDirectory(TranscribeController::UPLOADS);
        $path = null;
        try {
            // The plan and files at once first, so a link is never fetched for a member who cannot use it.
            $this->flow->check($user, 0.0, $source);
            $file = $request->file('file');
            if ($file instanceof UploadedFile) {
                if (! $file->isValid()) {
                    throw ApiError::invalid('file_not_received', 'The file did not arrive whole. Send it again as multipart/form-data in the "file" field.', 'file');
                }
                $name = self::fileName((string) $file->getClientOriginalName());
                // Saved under an ending the page takes, or none of the client's: a playlist named .m3u8
                // is never opened as one (Transcriber::INPUT_FORMATS reads plain audio and video only).
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $path = Storage::disk(TranscriptionFlow::DISK)->path(TranscribeController::UPLOADS.'/'.Str::random(40).'.'.(in_array($extension, TranscribeController::EXTENSIONS, true) ? $extension : 'part'));
                $file->move(dirname($path), basename($path));
                $meta += ['title' => (string) ($data['title'] ?? '') ?: $name, 'file_name' => $name];
            } else {
                $url = trim((string) $data['url']);
                $path = Storage::disk(TranscriptionFlow::DISK)->path(TranscribeController::UPLOADS.'/'.Str::random(40).'.link');
                $name = $this->links->fetch($url, $path, TranscriptionSettings::maxUploadMb() * 1024 * 1024, (int) $user->id);
                $meta += ['title' => (string) ($data['title'] ?? '') ?: $name, 'file_name' => $name, 'source_url' => $url];
            }

            $transcription = $this->flow->start($user, $path, $meta);
        } catch (TranscriptionRefused $e) {
            throw TranscriptionApi::error($e);
        } finally {
            if ($path !== null) {
                @unlink($path);
            }
        }

        $transcription = $transcription->fresh();

        return response()->json(TranscriptionApi::item($transcription) + ['usage' => TranscriptionApi::usage($user)], 202, [
            'Location' => url('/v1/transcriptions/'.$transcription->code),
            'X-Transcription-Id' => $transcription->code,
            'X-Seconds-Used' => (string) $transcription->usedSeconds(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'before' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'in:'.implode(',', TranscriptionApi::STATUSES)],
        ]);
        $pageSize = (int) ($filters['page_size'] ?? 30);

        $query = $this->mine($request)->select(Transcription::LISTED)->orderByDesc('id');
        if (isset($filters['before'])) {
            $before = $this->mine($request)->where('code', (string) $filters['before'])->value('id');
            if ($before === null) {
                throw ApiError::invalid('invalid_cursor', 'before must be a transcription_id from an earlier page.', 'before');
            }
            $query->where('id', '<', (int) $before);
        }
        if (isset($filters['status'])) {
            $query->where('status', TranscriptionApi::modelStatus((string) $filters['status']));
        }

        $rows = $query->limit($pageSize + 1)->get();
        $hasMore = $rows->count() > $pageSize;
        $rows = $rows->take($pageSize);

        return response()->json([
            'transcriptions' => $rows->map(fn (Transcription $t): array => TranscriptionApi::item($t))->values()->all(),
            'has_more' => $hasMore,
            'next_cursor' => $hasMore ? (string) $rows->last()->code : null,
        ]);
    }

    public function show(Request $request, string $transcriptionId): JsonResponse
    {
        return response()->json(TranscriptionApi::item($this->find($request, $transcriptionId), true));
    }

    /** The transcript as a file; with translate_to, in that language (made once and kept, no minutes). */
    public function export(Request $request, string $transcriptionId, TranscriptExport $export, TranscriptEditor $editor): Response
    {
        $t = $this->find($request, $transcriptionId);
        $data = $request->validate([
            'format' => ['required', 'string', 'in:'.implode(',', TranscriptExport::FORMATS)],
            'translate_to' => ['nullable', 'string', 'in:'.implode(',', TranscriptEditor::targets())],
        ]);
        if ($t->status !== Transcription::READY) {
            throw ApiError::invalid('transcription_not_ready', 'This transcription has no text yet (status: '.TranscriptionApi::status($t->status).').', 'transcription_id', 409);
        }

        $language = isset($data['translate_to']) && $data['translate_to'] !== $t->language ? (string) $data['translate_to'] : null;
        if ($language !== null && ! isset(($t->translations ?? [])[$language])) {
            try {
                $editor->translate($t, $language);
            } catch (TranscriptionRefused $e) {
                throw TranscriptionApi::error($e);
            } catch (\Throwable $e) {
                report($e);
                throw new ApiError(502, ApiError::TYPE_API, 'translation_failed', 'The translation could not be made. Please try again. No minutes were used.', 'translate_to');
            }
            $t->refresh();
        }

        $file = $export->export($t, (string) $data['format'], $language);

        return response($file['body'], 200, [
            'Content-Type' => $file['type'],
            'Content-Disposition' => 'attachment; filename="'.Str::ascii($file['name']).'"; filename*=UTF-8\'\''.rawurlencode($file['name']),
            'X-Transcription-Id' => $t->code,
        ]);
    }

    /** @return Builder<Transcription> */
    private function mine(Request $request)
    {
        return Transcription::query()->where('user_id', $request->user()->id)->where('source', '!=', 'demo');
    }

    private function find(Request $request, string $transcriptionId): Transcription
    {
        $t = preg_match('/^tr_[A-Za-z0-9]{8,40}$/', $transcriptionId) ? $this->mine($request)->where('code', $transcriptionId)->first() : null;
        if (! $t instanceof Transcription) {
            throw ApiError::notFound('transcription', 'transcription_id');
        }

        return $t;
    }

    /** The file's own name, without folders or odd characters, for the title and the extension. */
    private static function fileName(string $name): string
    {
        $name = trim(str_replace(["\0", '/', '\\'], ' ', basename(str_replace('\\', '/', $name))));

        return mb_substr($name !== '' ? $name : 'Recording', 0, 200);
    }
}
