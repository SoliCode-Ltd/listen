<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\VideoDub;
use App\Services\Billing\MemberUsageMeter;
use App\Services\DemoFraudService;
use App\Services\DemoLinkedAccounts;
use App\Services\Dubbing\DriveArchive;
use App\Services\Dubbing\VideoDubber;
use App\Services\Dubbing\VideoDubRunner;
use App\Services\PlanAccessService;
use App\Support\EmailVerification;
use App\Support\TtsStudioConfig;
use App\Support\VideoDubbing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Dub my video": a member uploads a short video, picks Arabic (a dialect) or
 * English (an accent) and a voice, and gets it back in that dialect (App\Services\Dubbing\VideoDubber). The
 * same studio sits on its own page, in the demo and on the dashboard
 * (components/dub/studio.blade.php); all of them send here.
 *
 * Without a plan that includes dubbing a member gets one free dub, watched
 * with the Listen mark and never downloaded without it; with one, the dub is
 * paid in credits (up to the plan's videos a month and its longest video)
 * and the clean copy can be downloaded. A member who subscribes after their
 * free dub can download it clean, paid then. Who may see it at all, and what
 * the studio shows: App\Support\VideoDubbing.
 */
class VideoDubController extends Controller
{
    /** Where videos sent in pieces are joined, on the private disk (dubs:prune deletes ones left behind). */
    public const UPLOADS = 'dub-uploads';

    public const UPLOAD_HOURS = 3;

    public function __construct(private PlanAccessService $plans) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        abort_unless(VideoDubbing::openTo($user), 404);

        return VideoDubbing::withBrowserCookie($request, response()->view('dub', [
            'user' => $user,
            'dubStudio' => VideoDubbing::studio($user, $request),
        ]));
    }

    /**
     * Starts sending a video in pieces (the page always does): what it is and
     * how big, checked before a byte is sent, so nobody waits for a large
     * video to arrive to be told it cannot be dubbed. Then uploadChunk() for
     * each piece, then store() with the upload's id. Each request stays far
     * below any limit on one request's size, on the server or in front of it.
     */
    public function startUpload(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(VideoDubbing::openTo($user), 404);
        $plan = $this->plans->resolveAccessPlanForUserId((int) $user->id);
        $free = ! VideoDubbing::planIncludes($plan);
        $mb = VideoDubbing::maxUploadMb($free);
        $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/\.(mp4|mov|webm|mkv|m4v|3gp)$/i'],
            'size' => ['required', 'integer', 'min:1', 'max:'.($mb * 1024 * 1024)],
        ], [
            'name.regex' => __('ui.dub.js.not_video'),
            'size.max' => __('ui.dub.js.too_big', ['mb' => $mb]),
        ]);
        $this->assertMayStart($user, $request, $plan, $free);

        $id = Str::random(40);
        $upload = ['id' => $id, 'user_id' => (int) $user->id, 'name' => mb_substr((string) $request->input('name'), 0, 200),
            'size' => (int) $request->input('size'), 'path' => self::UPLOADS.'/'.$id.'.part'];
        Storage::disk('local')->put($upload['path'], '');
        Cache::put('dub-upload:'.$id, $upload, now()->addHours(self::UPLOAD_HOURS));

        return response()->json(['id' => $id, 'chunk_bytes' => VideoDubbing::chunkBytes(), 'chunk_url' => route('dub.upload.chunk', ['id' => $id])], 201);
    }

    /**
     * One piece of a video, as the request's body, at the place X-Upload-Offset
     * says. A piece sent again (its answer was lost) is not added twice: the
     * reply says how much has arrived, and the page carries on from there.
     */
    public function uploadChunk(Request $request, string $id): JsonResponse
    {
        $upload = $this->ownUpload($request, $id);
        $path = Storage::disk('local')->path($upload['path']);

        return Cache::lock('dub-upload-lock:'.$id, 120)->block(30, function () use ($request, $upload, $path): JsonResponse {
            clearstatcache(true, $path);
            $received = is_file($path) ? (int) filesize($path) : 0;
            $offset = (int) $request->header('X-Upload-Offset', '-1');
            if ($offset !== $received) {
                return response()->json(['received' => $received], $offset < $received ? 200 : 409);
            }
            $in = $request->getContent(true);
            $out = fopen($path, 'ab');
            // Never more than the piece size, nor past the size announced.
            stream_copy_to_stream($in, $out, min(VideoDubbing::chunkBytes() + 1024 * 1024, $upload['size'] - $received));
            fclose($out);
            clearstatcache(true, $path);

            return response()->json(['received' => (int) filesize($path)]);
        });
    }

    public function store(Request $request, VideoDubber $dubber): JsonResponse
    {
        // A video sent in pieces: its joined file goes to the dub, or is deleted.
        $upload = $request->filled('upload_id') ? $this->finishedUpload($request, (string) $request->input('upload_id')) : null;
        try {
            return $this->dub($request, $dubber, $upload);
        } finally {
            if ($upload !== null) {
                Storage::disk('local')->delete($upload['path']);
                Cache::forget('dub-upload:'.$upload['id']);
            }
        }
    }

    /** @param  array<string, mixed>|null  $upload */
    private function dub(Request $request, VideoDubber $dubber, ?array $upload): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(VideoDubbing::openTo($user), 404);
        $language = $request->input('language') === 'en' ? 'en' : 'ar';
        $plan = $this->plans->resolveAccessPlanForUserId((int) $user->id);
        $free = ! VideoDubbing::planIncludes($plan);
        $data = $request->all();
        if ($upload !== null) {
            $data['video'] = new UploadedFile(Storage::disk('local')->path($upload['path']), $upload['name'], null, null, true);
        }
        $validated = Validator::make($data, [
            // The free dub has its own largest video (Video Dubbing → Settings).
            'video' => ['required', 'file', 'max:'.(VideoDubbing::maxUploadMb($free) * 1024),
                'mimetypes:video/mp4,video/quicktime,video/webm,video/x-matroska,video/x-m4v,video/3gpp'],
            'language' => ['nullable', Rule::in(VideoDubbing::LANGUAGES)],
            // An Arabic dialect, or an English accent.
            'dialect' => ['required', 'string', Rule::in(array_keys(VideoDubbing::dialects(false, $language)))],
            'voice_key' => ['required', 'string', 'max:40'],
            // Empty: each line as the video says it.
            'performance_style' => ['nullable', 'string', Rule::in(TtsStudioConfig::performanceStyleValues())],
            'emotion' => ['nullable', 'string', Rule::in(TtsStudioConfig::emotionValues())],
            'background' => ['nullable', Rule::in(['keep', 'replace'])],
        ])->validate();
        $voice = VideoDubbing::voiceName($validated['voice_key'], $language);
        if ($voice === null) {
            throw ValidationException::withMessages(['voice_key' => __('ui.dub.pick_voice')]);
        }
        $this->assertMayStart($user, $request, $plan, $free);

        // Kept on the private disk: only its owner can watch it, through this controller.
        $folder = 'dubs/'.now()->format('Ymd').'/'.Str::random(24);
        $file = $validated['video'];
        $extension = strtolower($file->getClientOriginalExtension() ?: 'mp4');
        $name = 'source.'.(preg_match('/^[a-z0-9]{2,4}$/', $extension) ? $extension : 'mp4');
        // A video sent in pieces is moved, not copied: it can be hundreds of MB.
        $source = $upload !== null
            ? (Storage::disk('local')->move($upload['path'], $folder.'/'.$name) ? $folder.'/'.$name : throw new \RuntimeException('The uploaded video could not be moved.'))
            : $file->storeAs($folder, $name, 'local');

        try {
            $probe = $dubber->probe(Storage::disk('local')->path($source));
        } catch (\Throwable) {
            $probe = null;
        }
        $min = (int) config('listen.dubbing.min_seconds', 3);
        $max = VideoDubbing::maxSeconds($plan, $free);
        $problem = match (true) {
            $probe === null || ! $probe['picture'] => __('ui.dub.error_unreadable'),
            ! $probe['sound'] => __('ui.dub.error_no_sound'),
            $probe['seconds'] < $min => __('ui.dub.too_short', ['seconds' => $min]),
            // Half a second over is the rounding of phones' "1:00" clips.
            $probe['seconds'] > $max + 0.5 => __('ui.dub.too_long', ['seconds' => $max]),
            default => null,
        };
        if ($problem !== null) {
            Storage::disk('local')->deleteDirectory($folder);
            throw ValidationException::withMessages(['video' => $problem]);
        }

        $row = [
            'user_id' => $user->id,
            'status' => VideoDub::QUEUED,
            'stage' => 'queued',
            'is_free' => $free,
            'language' => $language,
            'dialect' => $validated['dialect'],
            'voice' => $voice,
            'voice_key' => $validated['voice_key'],
            'performance_style' => $validated['performance_style'] ?? null,
            'emotion' => $validated['emotion'] ?? null,
            'keep_background' => ($validated['background'] ?? 'keep') !== 'replace',
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 200),
            'video_seconds' => min($probe['seconds'], $max),
            'folder' => $folder,
            'browser' => DemoLinkedAccounts::browserFrom($request),
            'device' => DemoLinkedAccounts::deviceFrom($request)['signal'] ?? null,
        ];

        try {
            if ($free) {
                // Two at once from one person must not both be their free one.
                $dub = Cache::lock('video-dub-free:'.$user->id, 15)->block(10, function () use ($user, $request, $row): VideoDub {
                    $this->assertMayDubFree($user, $request);

                    return VideoDub::query()->create($row);
                });
            } else {
                $quotaPlan = $this->plans->resolveQuotaPlanForUserId((int) $user->id, $plan);
                $dub = app(MemberUsageMeter::class)->reserveCredits((int) $user->id, $quotaPlan, VideoDubbing::estimate($row['video_seconds']),
                    function (array $reservation) use ($row, $user, $plan): VideoDub {
                        // Counted again under the meter's locks: two uploads at once cannot both take the month's last video.
                        $this->assertWithinMonth($user, $plan);

                        return VideoDub::query()->create($row + ['reservation' => $reservation, 'period_ym' => $reservation['period']]);
                    });
            }
        } catch (\Throwable $e) {
            Storage::disk('local')->deleteDirectory($folder);
            if ($e instanceof ValidationException && isset($e->errors()['content'])) {
                // The meter's words about credits, shown under the upload.
                throw ValidationException::withMessages(['video' => $e->errors()['content']]);
            }
            throw $e;
        }

        VideoDubRunner::runAfterResponse((int) $dub->id);

        return response()->json(VideoDubbing::present($dub) + ['studio' => $this->studioAfter($user, $request)], 202);
    }

    public function status(Request $request, string $code): JsonResponse
    {
        return response()->json(VideoDubbing::present($this->ownDub($request, $code)));
    }

    /**
     * The dub to watch: with the mark when it was free, else the clean copy,
     * from the server or, once it is there, from the site's Google Drive.
     * Only while it is kept (VideoDubbing::availableUntil()).
     */
    public function watch(Request $request, string $code): Response
    {
        $dub = $this->ownDub($request, $code);
        abort_unless(VideoDubbing::available($dub), 404);
        if ($dub->drive_file_id) {
            return app(DriveArchive::class)->stream($dub->drive_file_id, $request->header('Range'), 'listen-dub-'.$dub->id.'.mp4', false);
        }
        // A free dub its member paid for since plays clean.
        $path = $dub->path(! is_array($dub->reservation) && $dub->has_preview ? 'dub-preview.mp4' : 'dub.mp4');
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Content-Type' => 'video/mp4', 'Cache-Control' => 'private, max-age=3600']);
    }

    /**
     * The copy without the mark: a plan with dubbing only. A free dub made
     * before subscribing is paid for now, once, then downloaded.
     */
    public function download(Request $request, string $code): Response
    {
        $dub = $this->ownDub($request, $code);
        $path = $dub->path('dub.mp4');
        abort_unless(VideoDubbing::available($dub) && $dub->has_clean && ($dub->drive_file_id || Storage::disk('local')->exists($path)), 404);

        if (! is_array($dub->reservation)) {
            $userId = (int) $request->user()->id;
            $plan = $this->plans->resolveAccessPlanForUserId($userId);
            if (! VideoDubbing::planIncludes($plan)) {
                $upgrade = VideoDubbing::cheapestPlan();

                return redirect($upgrade ? route('billing.page', ['plan' => $upgrade->slug]) : route('pricing'));
            }
            try {
                app(MemberUsageMeter::class)->reserveCredits($userId, $this->plans->resolveQuotaPlanForUserId($userId, $plan),
                    VideoDubbing::estimate((float) $dub->video_seconds), function (array $reservation) use ($dub): void {
                        // Locked, so two clicks at once pay once.
                        $fresh = VideoDub::query()->whereKey($dub->id)->lockForUpdate()->first();
                        if (is_array($fresh?->reservation)) {
                            throw new \LogicException('paid');
                        }
                        $fresh->update(['reservation' => $reservation]);
                    });
            } catch (\LogicException) {
                // Already paid by the other click: the transaction gave this one back.
            } catch (ValidationException $e) {
                return back(fallback: route('dub.page'))->with('dub_error', collect($e->errors())->flatten()->first());
            }
        }

        if ($dub->drive_file_id) {
            return app(DriveArchive::class)->stream($dub->drive_file_id, null, 'listen-dub-'.$dub->id.'.mp4', true);
        }

        return response()->download(Storage::disk('local')->path($path), 'listen-dub-'.$dub->id.'.mp4', ['Content-Type' => 'video/mp4']);
    }

    /** One dub at a time, then the free dub's rules or the plan's month. */
    private function assertMayStart(User $user, Request $request, ?\App\Models\Plan $plan, bool $free): void
    {
        if (VideoDub::query()->where('user_id', $user->id)->whereIn('status', [VideoDub::QUEUED, VideoDub::PROCESSING])->exists()) {
            throw ValidationException::withMessages(['video' => __('ui.dub.one_at_a_time')]);
        }
        if ($free) {
            $this->assertMayDubFree($user, $request);
        } else {
            $this->assertWithinMonth($user, $plan);
        }
    }

    /**
     * One free dub per person, when today's free dubs for the site are not used
     * up, from an account with a confirmed email and not an automated browser.
     */
    private function assertMayDubFree(User $user, Request $request): void
    {
        if (EmailVerification::requiredForFreeUse($user)) {
            throw ValidationException::withMessages(['video' => __('ui.dub.verify_email', ['email' => (string) $user->email])]);
        }
        if (app(DemoFraudService::class)->demoBlockReason($request) !== null) {
            throw ValidationException::withMessages(['video' => __('ui.dub.free_blocked')]);
        }
        if (VideoDubbing::freeUsed($user, $request)) {
            throw ValidationException::withMessages(['video' => __('ui.dub.free_used')]);
        }
        if (VideoDubbing::freeDayFull()) {
            throw ValidationException::withMessages(['video' => __('ui.dub.free_day_full')]);
        }
    }

    /** The plan's videos a month, when it has a number. */
    private function assertWithinMonth(User $user, ?\App\Models\Plan $plan): void
    {
        $limit = VideoDubbing::monthlyLimit($plan);
        if ($limit > 0 && VideoDubbing::dubsThisMonth((int) $user->id) >= $limit) {
            throw ValidationException::withMessages(['video' => trans_choice('ui.dub.month_used', $limit, ['count' => $limit])]);
        }
    }

    /** What the studio shows once a dub is on its way (the free one used, a video less this month). */
    private function studioAfter(User $user, Request $request): array
    {
        return array_intersect_key(VideoDubbing::studio($user, $request), array_flip(['state', 'usage']));
    }

    /** @return array<string, mixed> An upload of this member's that is still open. */
    private function ownUpload(Request $request, string $id): array
    {
        $upload = Cache::get('dub-upload:'.$id);
        abort_unless(is_array($upload) && (int) $upload['user_id'] === (int) $request->user()->id, 404);

        return $upload;
    }

    /** @return array<string, mixed> An upload of this member's with every piece in. */
    private function finishedUpload(Request $request, string $id): array
    {
        $upload = Cache::get('dub-upload:'.$id);
        $disk = Storage::disk('local');
        if (! is_array($upload) || (int) $upload['user_id'] !== (int) $request->user()->id || ! $disk->exists($upload['path'])
            || $disk->size($upload['path']) !== (int) $upload['size']) {
            throw ValidationException::withMessages(['video' => __('ui.dub.upload_lost')]);
        }

        return $upload;
    }

    /** A dub of this member's, by the random code its links carry (never by its number). */
    private function ownDub(Request $request, string $code): VideoDub
    {
        return VideoDub::query()->where('code', $code)->where('user_id', (int) $request->user()->id)->firstOrFail();
    }
}
