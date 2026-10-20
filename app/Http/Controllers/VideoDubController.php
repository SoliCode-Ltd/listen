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

    public function store(Request $request, VideoDubber $dubber): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(VideoDubbing::openTo($user), 404);
        $language = $request->input('language') === 'en' ? 'en' : 'ar';
        $validated = $request->validate([
            'video' => ['required', 'file', 'max:'.((int) config('listen.dubbing.max_upload_mb', 100) * 1024),
                'mimetypes:video/mp4,video/quicktime,video/webm,video/x-matroska,video/x-m4v,video/3gpp'],
            'language' => ['nullable', Rule::in(VideoDubbing::LANGUAGES)],
            // An Arabic dialect, or an English accent.
            'dialect' => ['required', 'string', Rule::in(array_keys(VideoDubbing::dialects(false, $language)))],
            'voice_key' => ['required', 'string', 'max:40'],
            // Empty: each line as the video says it.
            'performance_style' => ['nullable', 'string', Rule::in(TtsStudioConfig::performanceStyleValues())],
            'emotion' => ['nullable', 'string', Rule::in(TtsStudioConfig::emotionValues())],
            'background' => ['nullable', Rule::in(['keep', 'replace'])],
        ]);
        $voice = VideoDubbing::voiceName($validated['voice_key'], $language);
        if ($voice === null) {
            throw ValidationException::withMessages(['voice_key' => __('ui.dub.pick_voice')]);
        }

        if (VideoDub::query()->where('user_id', $user->id)->whereIn('status', [VideoDub::QUEUED, VideoDub::PROCESSING])->exists()) {
            throw ValidationException::withMessages(['video' => __('ui.dub.one_at_a_time')]);
        }

        $plan = $this->plans->resolveAccessPlanForUserId((int) $user->id);
        $free = ! VideoDubbing::planIncludes($plan);
        if ($free) {
            $this->assertMayDubFree($user, $request);
        } else {
            $this->assertWithinMonth($user, $plan);
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

    public function status(Request $request, int $id): JsonResponse
    {
        return response()->json(VideoDubbing::present($this->ownDub($request, $id)));
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
                return back(fallback: route('dub.page'))->with('dub_error', collect($e->errors())->flatten()->first());
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

    private function ownDub(Request $request, int $id): VideoDub
    {
        return VideoDub::query()->whereKey($id)->where('user_id', (int) $request->user()->id)->firstOrFail();
    }
}
