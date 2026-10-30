<?php

namespace App\Http\Controllers;

use App\Models\DesignedVoice;
use App\Models\User;
use App\Rules\DoesNotContainProfanity;
use App\Services\Billing\MemberUsageMeter;
use App\Services\PlanAccessService;
use App\Services\ProfanityFilter;
use App\Services\VoiceDesignService;
use App\Support\CreditPeriod;
use App\Support\UsageSnapshot;
use App\Support\VoiceDesign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Voice Design on the dashboard (components/voice-design/studio.blade.php):
 * a description written out in full, the takes of a design, keeping one,
 * and deleting a designed voice. The demo's single take is
 * HomeController::designVoice. Who may: App\Support\VoiceDesign.
 *
 * A design reserves the credits of every take speaking its line before any
 * is made (MemberUsageMeter::reserveCredits), and gives back what a take
 * that never came would have cost; nothing is charged when none came.
 */
class VoiceDesignController extends Controller
{
    public function __construct(
        private readonly PlanAccessService $plans,
        private readonly VoiceDesignService $designer,
    ) {}

    /** /dashboard/voice-design: the dashboard's section. */
    public function index(Request $request)
    {
        abort_unless(VoiceDesign::openTo($request->user()), 404);

        return redirect()->to(route('dashboard').'#voiceDesignSection');
    }

    /** "Write it for me": a short idea as a full description. Text only, open to the demo too. */
    public function enhance(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(VoiceDesign::openTo($user), 404);
        $validated = $request->validate([
            'idea' => ['required', 'string', 'min:3', 'max:'.VoiceDesign::MAX_DESCRIPTION, new DoesNotContainProfanity(app(ProfanityFilter::class))],
            'language' => ['nullable', Rule::in(VoiceDesign::LANGUAGES)],
        ]);

        $rate = 'voice-design-enhance:'.$user->id;
        if (RateLimiter::tooManyAttempts($rate, 30)) {
            return $this->error(__('voice_design.errors.too_many', ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($rate) / 60))]), 429);
        }
        RateLimiter::hit($rate, 3600);

        $description = $this->designer->enhance((string) $validated['idea'], (string) ($validated['language'] ?? 'ar'));
        if ($description === null) {
            return $this->error(__('voice_design.errors.enhance_failed'), 422);
        }

        return response()->json(['ok' => true, 'description' => $description]);
    }

    public function design(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(VoiceDesign::openTo($user), 404);
        $userId = (int) $user->id;
        $plan = $this->plans->resolveAccessPlanForUserId($userId);
        if (! VoiceDesign::allows($user, $plan)) {
            return $this->error(__('voice_design.upgrade_text'), 403);
        }
        $validated = $this->validateDesign($request, VoiceDesign::MAX_PREVIEW);

        $rate = 'voice-design:'.$userId;
        if (RateLimiter::tooManyAttempts($rate, VoiceDesign::hourlyLimit())) {
            return $this->error(__('voice_design.errors.too_many', ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($rate) / 60))]), 429);
        }
        // Designing costs credits: never for a voice that could not be kept.
        if (($blocker = VoiceDesign::keepBlocker($userId, $plan)) !== null) {
            return $this->error($blocker, 422);
        }

        @set_time_limit(180);
        $line = $validated['line'] ?? $this->designer->previewLine($validated['description'], $validated['language']);
        $takes = VoiceDesign::takes();
        $estimate = VoiceDesign::estimate($line, $takes);
        $quotaPlan = $this->plans->resolveQuotaPlanForUserId($userId, $plan);
        $meter = app(MemberUsageMeter::class);
        $reservation = $meter->reserveCredits($userId, $quotaPlan, $estimate, fn (array $reservation): array => $reservation);
        RateLimiter::hit($rate, 3600);

        try {
            $result = $this->designer->design($user, ['line' => $line] + $validated, $takes);
        } catch (\Throwable $e) {
            Log::error('Voice design failed unexpectedly.', ['user_id' => $userId, 'exception' => get_class($e).': '.$e->getMessage()]);
            $result = ['takes' => [], 'error' => $e->getMessage(), 'error_code' => 'server'];
        }

        $made = count($result['takes']);
        if ($made === 0) {
            $meter->giveBack($userId, $reservation);
            RateLimiter::clear($rate);

            return $this->error($this->failureMessage((string) $result['error_code']), 422);
        }
        if ($made < $takes) {
            $one = VoiceDesign::estimate($line, 1);
            $meter->giveBackPart($userId, $reservation, $one['credits'] * ($takes - $made), $one['seconds'] * ($takes - $made));
        }

        return response()->json([
            'ok' => true,
            'line' => $line,
            'charged_credits' => (int) round($estimate['credits'] / $takes * $made),
            'takes' => array_map(fn (DesignedVoice $take): array => VoiceDesign::present($take), $result['takes']),
            'usage' => UsageSnapshot::for($userId, $quotaPlan, CreditPeriod::counter($userId)),
        ]);
    }

    /** Keeps one take of a design, under the member's name for it. */
    public function keep(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(VoiceDesign::openTo($user), 404);
        $take = DesignedVoice::query()->ownedBy((int) $user->id)->drafts()->whereKey($id)->firstOrFail();
        $plan = $this->plans->resolveAccessPlanForUserId((int) $user->id);
        if (! VoiceDesign::allows($user, $plan)) {
            return $this->error(__('voice_design.upgrade_text'), 403);
        }
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:50', new DoesNotContainProfanity(app(ProfanityFilter::class))],
        ]);
        if (($blocker = VoiceDesign::keepBlocker((int) $user->id, $plan)) !== null) {
            return $this->error($blocker, 422);
        }

        $voice = $this->designer->keep($take, trim((string) $validated['name']));

        return response()->json([
            'ok' => true,
            'message' => __('voice_design.kept', ['name' => $voice->name]),
            'voice' => VoiceDesign::present($voice),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(VoiceDesign::openTo($user), 404);
        $voice = DesignedVoice::query()->ownedBy((int) $user->id)->whereKey($id)->firstOrFail();
        // A kept voice stays named in the member's history; a take is gone.
        $this->designer->purge($voice, $voice->isSaved());

        return response()->json(['ok' => true]);
    }

    /**
     * The design form, for the dashboard and the demo ($maxLine: the longest line the takes may speak).
     *
     * @return array{description:string, language:string, gender:?string, line:?string, projection:int, pace:int, variety:int}
     */
    public static function validateDesign(Request $request, int $maxLine): array
    {
        $profanity = new DoesNotContainProfanity(app(ProfanityFilter::class));
        foreach (['description', 'line'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => trim(str_replace(["\r\n", "\r"], "\n", $request->input($field)))]);
            }
        }
        $validated = $request->validate([
            'description' => ['required', 'string', 'min:'.VoiceDesign::MIN_DESCRIPTION, 'max:'.VoiceDesign::MAX_DESCRIPTION, $profanity],
            'language' => ['required', Rule::in(VoiceDesign::LANGUAGES)],
            'gender' => ['nullable', Rule::in(VoiceDesign::GENDERS)],
            'auto_line' => ['nullable', 'boolean'],
            'line' => ['nullable', 'exclude_if:auto_line,1,true', 'required_unless:auto_line,1,true', 'string', 'min:'.VoiceDesign::MIN_PREVIEW, 'max:'.max(VoiceDesign::MIN_PREVIEW, $maxLine), $profanity],
            'projection' => ['nullable', 'integer', 'between:0,100'],
            'pace' => ['nullable', 'integer', 'between:0,100'],
            'variety' => ['nullable', 'integer', 'between:0,100'],
        ], [
            'line.required_unless' => __('voice_design.errors.line_needed'),
            'line.max' => __('voice_design.errors.line_too_long', ['max' => $maxLine]),
        ]);

        return [
            'description' => (string) $validated['description'],
            'language' => (string) $validated['language'],
            'gender' => $validated['gender'] ?? null,
            'line' => filled($validated['line'] ?? null) ? (string) $validated['line'] : null,
            'projection' => (int) ($validated['projection'] ?? VoiceDesign::SLIDER_MIDDLE),
            'pace' => (int) ($validated['pace'] ?? VoiceDesign::SLIDER_MIDDLE),
            'variety' => (int) ($validated['variety'] ?? VoiceDesign::SLIDER_MIDDLE),
        ];
    }

    /** What the member is told when no take came. */
    public static function failureMessage(string $code): string
    {
        return match ($code) {
            'quota' => __('voice_design.errors.busy'),
            'refused' => __('voice_design.errors.refused'),
            'invalid' => __('voice_design.errors.invalid'),
            default => __('voice_design.errors.our_side'),
        };
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $message], $status);
    }
}
