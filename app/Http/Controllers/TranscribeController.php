<?php

namespace App\Http\Controllers;

use App\Models\Transcription;
use App\Models\User;
use App\Services\Transcription\AudioLink;
use App\Services\Transcription\TranscriptEditor;
use App\Services\Transcription\TranscriptExport;
use App\Services\Transcription\TranscriptionFlow;
use App\Services\Transcription\TranscriptionRefused;
use App\Support\Transcription\TranscriptionAccess;
use App\Support\Transcription\TranscriptionPresenter;
use App\Support\Transcription\TranscriptionSettings;
use App\Support\VideoDubbing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Transcribe on the dashboard (resources/views/components/transcribe): a
 * member checks a file (check(): what it uses and what is left, before a
 * byte is sent), sends it in pieces as dubbing does (startUpload(),
 * uploadChunk()), or gives a link, and starts it (store()); then follows it,
 * reads, plays, corrects, names the speakers, translates, downloads and
 * deletes. Every refusal comes back as {code, message, upgrade} with the
 * plan that fits. The rules and the minutes: App\Services\Transcription\TranscriptionFlow.
 */
class TranscribeController extends Controller
{
    /** Where files sent in pieces are joined, on the private disk (transcriptions:prune deletes ones left behind). */
    public const UPLOADS = 'transcribe-uploads';

    /** The files a member can choose (the box says the same). */
    public const EXTENSIONS = ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'flac', 'webm', 'mp4', 'mov', 'm4v', '3gp', 'amr', 'wma'];

    public function __construct(private TranscriptionFlow $flow) {}

    /** What a file of this length would use, and whether it can start: asked as soon as a file is chosen. */
    public function check(Request $request): JsonResponse
    {
        $user = $this->member($request);
        $data = $request->validate(['seconds' => ['required', 'numeric', 'min:0.1', 'max:86400'], 'source' => ['nullable', 'string', Rule::in(['upload', 'record', 'url'])]]);

        return $this->guard(fn () => response()->json($this->flow->check($user, (float) $data['seconds'], (string) ($data['source'] ?? 'upload'))));
    }

    public function startUpload(Request $request): JsonResponse
    {
        $user = $this->member($request);
        $mb = TranscriptionSettings::maxUploadMb();
        $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/\.('.implode('|', self::EXTENSIONS).')$/i'],
            'size' => ['required', 'integer', 'min:1', 'max:'.($mb * 1024 * 1024)],
        ], [
            'name.regex' => __('transcribe.refused.no_audio'),
            'size.max' => __('transcribe.refused.too_big', ['mb' => $mb]),
        ]);

        $id = Str::random(40);
        $upload = ['id' => $id, 'user_id' => (int) $user->id, 'name' => mb_substr((string) $request->input('name'), 0, 200),
            'size' => (int) $request->input('size'), 'path' => self::UPLOADS.'/'.$id.'.part'];
        Storage::disk('local')->put($upload['path'], '');
        Cache::put('transcribe-upload:'.$id, $upload, now()->addHours(3));

        return response()->json(['id' => $id, 'chunk_bytes' => VideoDubbing::chunkBytes(), 'chunk_url' => route('transcribe.upload.chunk', ['id' => $id])], 201);
    }

    /** One piece of a file, at the place X-Upload-Offset says; a piece sent again is not added twice. */
    public function uploadChunk(Request $request, string $id): JsonResponse
    {
        $upload = Cache::get('transcribe-upload:'.$id);
        abort_unless(is_array($upload) && (int) $upload['user_id'] === (int) $request->user()->id, 404);
        $path = Storage::disk('local')->path($upload['path']);

        return Cache::lock('transcribe-upload-lock:'.$id, 120)->block(30, function () use ($request, $upload, $path): JsonResponse {
            clearstatcache(true, $path);
            $received = is_file($path) ? (int) filesize($path) : 0;
            $offset = (int) $request->header('X-Upload-Offset', '-1');
            if ($offset !== $received) {
                return response()->json(['received' => $received], $offset < $received ? 200 : 409);
            }
            $in = $request->getContent(true);
            $out = fopen($path, 'ab');
            stream_copy_to_stream($in, $out, min(VideoDubbing::chunkBytes() + 1024 * 1024, $upload['size'] - $received));
            fclose($out);
            clearstatcache(true, $path);

            return response()->json(['received' => (int) filesize($path)]);
        });
    }

    /** A file sent in pieces (upload_id) or a link (url) transcribed. */
    public function store(Request $request, AudioLink $links): JsonResponse
    {
        $user = $this->member($request);
        $data = $request->validate([
            'upload_id' => ['nullable', 'string', 'size:40', 'required_without:url'],
            'url' => ['nullable', 'string', 'max:2048', 'required_without:upload_id'],
            'language' => ['nullable', 'string', 'max:8'],
            'source' => ['nullable', 'string', Rule::in(['upload', 'record'])],
            'title' => ['nullable', 'string', 'max:200'],
        ]);
        $language = (string) ($data['language'] ?? 'auto');
        if ($language !== 'auto' && ! in_array($language, TranscriptionSettings::languages(), true)) {
            $language = 'auto';
        }

        return $this->guard(function () use ($request, $user, $data, $language, $links): JsonResponse {
            if (! empty($data['upload_id'])) {
                $upload = $this->finishedUpload($request, (string) $data['upload_id']);
                Cache::forget('transcribe-upload:'.$upload['id']);
                $path = Storage::disk('local')->path($upload['path']);
                $meta = ['title' => (string) ($data['title'] ?? '') ?: $upload['name'], 'file_name' => $upload['name'], 'source' => $data['source'] ?? 'upload'];
            } else {
                $path = Storage::disk('local')->path(self::UPLOADS.'/'.Str::random(40).'.link');
                Storage::disk('local')->makeDirectory(self::UPLOADS);
                $name = $links->fetch((string) $data['url'], $path, TranscriptionSettings::maxUploadMb() * 1024 * 1024);
                $meta = ['title' => (string) ($data['title'] ?? '') ?: $name, 'file_name' => $name, 'source' => 'url', 'source_url' => (string) $data['url']];
            }
            try {
                $t = $this->flow->start($user, $path, $meta + ['language' => $language]);
            } finally {
                @unlink($path);
            }

            return response()->json([
                'transcription' => TranscriptionPresenter::summary($t->fresh(), $this->arabic()),
                'account' => TranscriptionPresenter::account($user, $this->arabic()),
            ], 202);
        });
    }

    /** The member's transcripts, newest first, searched by title or words. */
    public function index(Request $request): JsonResponse
    {
        $user = $this->member($request);
        $q = trim((string) $request->query('q', ''));
        $query = Transcription::query()->where('user_id', $user->id)->latest('id');
        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $query->where(fn ($w) => $w->where('title', 'like', $like)
                ->orWhere(Transcription::hasSearchText() ? 'search_text' : 'paragraphs', 'like', $like));
        }
        $page = $query->paginate(20);

        return response()->json([
            'items' => array_map(fn ($t) => TranscriptionPresenter::summary($t, $this->arabic()), $page->items()),
            'next' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
            'account' => TranscriptionPresenter::account($user, $this->arabic()),
        ]);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        $t = $this->own($request, $code);

        return response()->json(['transcription' => TranscriptionPresenter::full($t, $this->arabic())]
            + ($t->isWorking() ? [] : ['account' => TranscriptionPresenter::account($request->user(), $this->arabic())]));
    }

    /** The recording, to play beside its words (until it is deleted after the days set). */
    public function audio(Request $request, string $code): Response
    {
        $t = $this->own($request, $code);
        $disk = Storage::disk(TranscriptionFlow::DISK);
        abort_unless($t->audio_deleted_at === null && $t->audio_path && $disk->exists($t->audio_path) && ! $t->isWorking(), 404);

        return response()->file($disk->path($t->audio_path), ['Content-Type' => 'audio/mpeg', 'Cache-Control' => 'private, max-age=3600']);
    }

    /** A new title, speaker names, or a corrected paragraph. */
    public function update(Request $request, string $code, TranscriptEditor $editor): JsonResponse
    {
        $t = $this->own($request, $code);
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'speakers' => ['nullable', 'array', 'max:40'],
            'speakers.*' => ['nullable', 'string', 'max:60'],
            'paragraph' => ['nullable', 'integer', 'min:0'],
            'text' => ['nullable', 'string', 'max:20000', 'required_with:paragraph'],
        ]);
        if (isset($data['title'])) {
            $editor->rename($t, (string) $data['title']);
        }
        if (isset($data['speakers']) && $t->status === Transcription::READY) {
            $editor->nameSpeakers($t, array_map('strval', $data['speakers']));
        }
        if (isset($data['paragraph']) && $t->status === Transcription::READY) {
            $editor->correct($t, (int) $data['paragraph'], (string) $data['text']);
        }

        return response()->json(['transcription' => TranscriptionPresenter::full($t->fresh(), $this->arabic())]);
    }

    /** Deleted from the member's list (it stays counted in its month); its audio goes at the next clean-up. */
    public function destroy(Request $request, string $code): JsonResponse
    {
        $t = $this->own($request, $code);
        abort_if($t->isWorking(), 409);
        $t->delete();

        return response()->json(['deleted' => true]);
    }

    /** Downloaded as text, subtitles or a Word document, in its own language or a translation: no minutes. */
    public function export(Request $request, string $code, string $format): Response
    {
        $t = $this->own($request, $code);
        abort_unless($t->status === Transcription::READY && in_array($format, TranscriptExport::FORMATS, true), 404);
        $file = app(TranscriptExport::class)->export($t, $format, $request->query('lang') ? (string) $request->query('lang') : null);

        return response($file['body'], 200, [
            'Content-Type' => $file['type'],
            'Content-Disposition' => 'attachment; filename="'.Str::ascii($file['name']).'"; filename*=UTF-8\'\''.rawurlencode($file['name']),
        ]);
    }

    /** Translated into another language: made once and kept, no minutes. */
    public function translate(Request $request, string $code, TranscriptEditor $editor): JsonResponse
    {
        $t = $this->own($request, $code);
        abort_unless($t->status === Transcription::READY, 409);
        $data = $request->validate(['language' => ['required', 'string', Rule::in(TranscriptEditor::targets())]]);
        try {
            $translation = $editor->translate($t, (string) $data['language']);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('ui.backend.translation_failed')], 502);
        }

        return response()->json(['language' => $data['language'], 'paragraphs' => $translation['paragraphs'], 'segments' => $translation['segments']]);
    }

    private function member(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(TranscriptionAccess::openTo($user), 404);

        return $user;
    }

    private function own(Request $request, string $code): Transcription
    {
        $this->member($request);

        return Transcription::query()->where('code', $code)->where('user_id', (int) $request->user()->id)->firstOrFail();
    }

    /** @return array<string, mixed> An upload of this member's with every piece in. */
    private function finishedUpload(Request $request, string $id): array
    {
        $upload = Cache::get('transcribe-upload:'.$id);
        $disk = Storage::disk('local');
        if (! is_array($upload) || (int) $upload['user_id'] !== (int) $request->user()->id || ! $disk->exists($upload['path'])
            || $disk->size($upload['path']) !== (int) $upload['size']) {
            throw ValidationException::withMessages(['upload_id' => __('ui.dub.upload_lost')]);
        }

        return $upload;
    }

    /** @param  callable(): JsonResponse  $work */
    private function guard(callable $work): JsonResponse
    {
        try {
            return $work();
        } catch (TranscriptionRefused $e) {
            return response()->json($e->toArray(), $e->status);
        }
    }

    private function arabic(): bool
    {
        return app()->getLocale() === 'ar';
    }
}
