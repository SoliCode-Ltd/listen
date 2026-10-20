<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\VideoDub;
use App\Services\Billing\MemberUsageMeter;
use App\Services\DemoFraudService;
use App\Services\DemoLinkedAccounts;
use App\Services\Dubbing\VideoDubber;
use App\Services\Dubbing\VideoDubRunner;
use App\Services\PlanAccessService;
use App\Support\ArabicDialects;
use App\Support\EmailVerification;
use App\Support\TtsStudioConfig;
use App\Support\VideoDubbing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Dub my video": a member uploads a short video, picks a dialect and a voice,
 * and gets it back in that dialect (App\Services\Dubbing\VideoDubber).
 *
 * Without a plan that includes dubbing a member gets one free dub, watched
 * with the Listen mark and never downloaded without it; with one, the dub is
 * paid in credits and the clean copy can be downloaded. A member who
 * subscribes after their free dub can download it clean, paid then.
 * Who may see the page at all: App\Support\VideoDubbing.
 */
class VideoDubController extends Controller
{
    public function __construct(private PlanAccessService $plans) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        abort_unless(VideoDubbing::openTo($user), 404);
        $arabic = app()->getLocale() === 'ar';
        $plan = $user ? $this->plans->resolveAccessPlanForUserId((int) $user->id) : null;
        $included = VideoDubbing::planIncludes($plan);

        $response = response()->view('dub', [
            'user' => $user,
            'included' => $included,
            'freeUsed' => $user && ! $included ? VideoDubbing::freeUsed($user, $request) : false,
            'upgradePlan' => $included ? null : VideoDubbing::cheapestPlan(),
            'needsEmail' => $user !== null && ! $included && EmailVerification::requiredForFreeUse($user),
            'dubs' => $user ? $this->recent($user) : [],
            // The studio's own pickers: its voice library, dialects, styles and emotions.
            'voices' => VideoDubbing::voices(),
            'defaultVoiceKey' => VideoDubbing::defaultVoiceKey(),
            'dialects' => VideoDubbing::dialects($arabic),
            'styles' => TtsStudioConfig::performanceStyleCatalog(),
            'emotions' => TtsStudioConfig::emotionCatalog(),
            'arabic' => $arabic,
            'maxSeconds' => (int) config('listen.dubbing.max_seconds', 60),
            'minSeconds' => (int) config('listen.dubbing.min_seconds', 3),
            'maxMb' => (int) config('listen.dubbing.max_upload_mb', 100),
            'keepHours' => (int) config('listen.dubbing.keep_hours', 48),
        ]);

        return $this->withBrowserCookie($request, $response);
    }

    public function store(Request $request, VideoDubber $dubber): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(VideoDubbing::openTo($user), 404);
        $validated = $request->validate([
            'video' => ['required', 'file', 'max:'.((int) config('listen.dubbing.max_upload_mb', 100) * 1024),
                'mimetypes:video/mp4,video/quicktime,video/webm,video/x-matroska,video/x-m4v,video/3gpp'],
            'dialect' => ['required', 'string', Rule::in(array_keys(ArabicDialects::ALL))],
            'voice_key' => ['required', 'string', 'max:40'],
            'performance_style' => ['nullable', 'string', Rule::in(TtsStudioConfig::performanceStyleValues())],
            'emotion' => ['nullable', 'string', Rule::in(TtsStudioConfig::emotionValues())],
        ]);
        $voice = VideoDubbing::voiceName($validated['voice_key']);
        if ($voice === null) {
            throw ValidationException::withMessages(['voice_key' => __('ui.dub.pick_voice')]);
        }

        if (VideoDub::query()->where('user_id', $user->id)->whereIn('status', [VideoDub::QUEUED, VideoDub::PROCESSING])->exists()) {
            throw ValidationException::withMessages(['video' => __('ui.dub.one_at_a_time')]);
        }

        // Style and emotion left on "Default" get the site's defaults, as in the studio.
        $validated = array_merge($validated, TtsStudioConfig::withDefaultChoices($validated, 'ar'));

        $plan = $this->plans->resolveAccessPlanForUserId((int) $user->id);
        $free = ! VideoDubbing::planIncludes($plan);
        if ($free) {
            $this->assertMayDubFree($user, $request);
        }

        // Kept on the private disk: only its owner can watch it, through this controller.
        $folder = 'dubs/'.now()->format('Ymd').'/'.Str::random(24);
        $file = $validated['video'];
        $extension = strtolower($file->getClientOriginalExtension() ?: 'mp4');
        $source = $file->storeAs($folder, 'source.'.(preg_match('/^[a-z0-9]{2,4}$/', $extension) ? $extension : 'mp4'), 'local');

        try {
            $probe = $dubber->probe(Storage::disk('local')->path($source));
        } catch (\Throwable) {
            $probe = null;
        }
        $min = (int) config('listen.dubbing.min_seconds', 3);
        $max = (int) config('listen.dubbing.max_seconds', 60);
        $problem = match (true) {
            $probe === null || ! $probe['picture'] => __('ui.dub.error_unreadable'),
            ! $probe['sound'] => __('ui.dub.error_no_sound'),
            $probe['seconds'] < $min => __('ui.dub.too_short', ['seconds' => $min]),
            // A second over is the rounding of phones' "1:00" clips.
            $probe['seconds'] > $max + 1 => __('ui.dub.too_long', ['seconds' => $max]),
            default => null,
        };
        if ($problem !== null) {
            Storage::disk('local')->deleteDirectory($folder);
            throw ValidationException::withMessages(['video' => $problem]);
        }

        $row = [
            'user_id' => $user->id,
            'status' => VideoDub::QUEUED,
            'is_free' => $free,
            'dialect' => $validated['dialect'],
            'voice' => $voice,
            'performance_style' => $validated['performance_style'] ?? null,
            'emotion' => $validated['emotion'] ?? null,
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
                    fn (array $reservation): VideoDub => VideoDub::query()->create($row + ['reservation' => $reservation]));
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

        return response()->json($this->present($dub), 202);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        return response()->json($this->present($this->ownDub($request, $id)));
    }

    /** The dub to watch on the page: with the mark when it was free, else the clean copy. */
    public function watch(Request $request, int $id): BinaryFileResponse
    {
        $dub = $this->ownDub($request, $id);
        $file = $dub->is_free ? 'dub-preview.mp4' : 'dub.mp4';
        $path = $dub->path($file);
        abort_unless($dub->status === VideoDub::READY && $dub->files_deleted_at === null && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Content-Type' => 'video/mp4', 'Cache-Control' => 'private, max-age=3600']);
    }

    /**
     * The copy without the mark: a plan with dubbing only. A free dub made
     * before subscribing is paid for now, once, then downloaded.
     */
    public function download(Request $request, int $id): Response
    {
        $dub = $this->ownDub($request, $id);
        $path = $dub->path('dub.mp4');
        abort_unless($dub->status === VideoDub::READY && $dub->has_clean && $dub->files_deleted_at === null && Storage::disk('local')->exists($path), 404);

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
                return redirect()->route('dub.page')->with('dub_error', collect($e->errors())->flatten()->first());
            }
        }

        return response()->download(Storage::disk('local')->path($path), 'listen-dub-'.$dub->id.'.mp4', ['Content-Type' => 'video/mp4']);
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

    private function ownDub(Request $request, int $id): VideoDub
    {
        return VideoDub::query()->whereKey($id)->where('user_id', (int) $request->user()->id)->firstOrFail();
    }

    /** @return array<int, array<string, mixed>> */
    private function recent(User $user): array
    {
        return VideoDub::query()->where('user_id', $user->id)->whereNull('files_deleted_at')
            ->where('created_at', '>=', now()->subHours((int) config('listen.dubbing.keep_hours', 48)))
            ->latest('id')->limit(10)->get()->map(fn (VideoDub $dub): array => $this->present($dub))->all();
    }

    /** @return array<string, mixed> */
    private function present(VideoDub $dub): array
    {
        $ready = $dub->status === VideoDub::READY && $dub->files_deleted_at === null;

        return [
            'id' => $dub->id,
            'status' => $dub->status,
            'free' => $dub->is_free,
            'name' => (string) $dub->original_name,
            'dialect' => $dub->dialect,
            'seconds' => (float) $dub->video_seconds,
            'error' => $dub->status === VideoDub::FAILED ? (string) $dub->error_message : null,
            'status_url' => route('dub.status', ['id' => $dub->id]),
            'watch_url' => $ready ? route('dub.watch', ['id' => $dub->id]) : null,
            'download_url' => $ready && $dub->has_clean ? route('dub.download', ['id' => $dub->id]) : null,
            'expires_at' => $dub->created_at?->copy()->addHours((int) config('listen.dubbing.keep_hours', 48))->toIso8601String(),
        ];
    }

    /** The browser cookie that ties accounts made in one browser to one free dub, as the demo's does. */
    private function withBrowserCookie(Request $request, Response $response): Response
    {
        if (DemoLinkedAccounts::browserFrom($request) !== null) {
            return $response;
        }

        $response->headers->setCookie(cookie(DemoLinkedAccounts::BROWSER_COOKIE, Str::random(48), 60 * 24 * 730, '/', null, $request->isSecure(), true, false, 'lax'));

        return $response;
    }
}
