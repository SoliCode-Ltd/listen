<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessVoiceCloningJob;
use App\Models\ClonedVoice;
use App\Services\PlanAccessService;
use App\Services\VoiceCloningService;
use App\Support\VoiceCloningAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VoiceCloningController extends Controller
{
    public function __construct(
        private readonly PlanAccessService $planAccessService,
    ) {}

    /**
     * Redirect legacy voice-cloning page requests to dashboard section.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $plan = $this->planAccessService->resolveActivePlanForUserId((int) $user->id);

        if (VoiceCloningAccess::allows($user) || VoiceCloningAccess::showsUpgrade($user, $plan)) {
            return redirect()->to(route('dashboard') . '#voiceCloningSection');
        }

        return redirect()->route('dashboard');
    }

    /**
     * Store a new voice cloning request.
     */
    public function store(Request $request)
    {
        if (! VoiceCloningAccess::allows($request->user())) {
            abort(404);
        }

        $userId = (int) auth()->id();
        $activePlan = $this->planAccessService->resolveActivePlanForUserId($userId);

        if (! VoiceCloningService::isEnabled()) {
            return $this->jsonError(__('ui.voice_cloning.feature_disabled'), 403);
        }

        if (! VoiceCloningService::planAllows($activePlan)) {
            return $this->jsonError(__('ui.voice_cloning.plan_disabled'), 403);
        }

        // Rate limiting: max 5 clone requests per hour per user
        $rateLimitKey = "voice_cloning:{$userId}";
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            return $this->jsonError(__('ui.voice_cloning.rate_limited'), 429);
        }

        // Check the plan cap. A stored max of 0 uses the site-wide default.
        // Failed attempts do not keep a slot, so the user can record again.
        $currentCount = ClonedVoice::forUser($userId)->where('status', '!=', 'failed')->count();
        $maxVoices = VoiceCloningService::maxVoicesForViewer($request->user(), $activePlan);
        if ($currentCount >= $maxVoices) {
            return $this->jsonError(__('ui.voice_cloning.limit_reached', ['max' => $maxVoices]), 422);
        }

        // Validate inputs
        $minDuration = VoiceCloningService::minSampleDuration();
        $maxDuration = VoiceCloningService::maxSampleDuration();
        $minConsent = VoiceCloningService::minConsentDuration();
        $maxSizeMb = VoiceCloningService::maxUploadSizeMb();
        $maxSizeKb = $maxSizeMb * 1024;
        $allowedMimes = VoiceCloningService::allowedMimeTypes();
        $allowedExtValues = VoiceCloningService::allowedExtensions();

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:50'],
            'language_code' => ['required', 'string', 'in:ar-XA,en-US,en-GB,en-AU'],
            'source_type' => ['required', 'string', 'in:upload,recording'],
            'audio_file' => [
                'required',
                'file',
                "max:{$maxSizeKb}",
                function (string $attribute, mixed $value, \Closure $fail) use ($allowedExtValues, $allowedMimes): void {
                    if (! $value instanceof \Illuminate\Http\UploadedFile) {
                        return;
                    }

                    $clientExt = strtolower((string) $value->getClientOriginalExtension());
                    $guessedExt = strtolower((string) $value->guessExtension());
                    $detectedMime = strtolower((string) $value->getMimeType());

                    $extAllowed = in_array($clientExt, $allowedExtValues, true)
                        || in_array($guessedExt, $allowedExtValues, true);
                    $mimeAllowed = in_array($detectedMime, $allowedMimes, true);

                    if ($extAllowed || $mimeAllowed) {
                        return;
                    }

                    $fail(__('ui.voice_cloning.validation.audio_invalid_type'));
                },
            ],
            'consent_audio' => [
                'required',
                'file',
                "max:{$maxSizeKb}",
                function (string $attribute, mixed $value, \Closure $fail) use ($allowedExtValues, $allowedMimes): void {
                    if (! $value instanceof \Illuminate\Http\UploadedFile) {
                        return;
                    }

                    $clientExt = strtolower((string) $value->getClientOriginalExtension());
                    $guessedExt = strtolower((string) $value->guessExtension());
                    $detectedMime = strtolower((string) $value->getMimeType());

                    $extAllowed = in_array($clientExt, $allowedExtValues, true)
                        || in_array($guessedExt, $allowedExtValues, true);
                    $mimeAllowed = in_array($detectedMime, $allowedMimes, true);

                    if ($extAllowed || $mimeAllowed) {
                        return;
                    }

                    $fail(__('ui.voice_cloning.validation.audio_invalid_type'));
                },
            ],
            'consent_confirmed' => ['required', 'accepted'],
            'consent_language' => ['nullable', 'string', 'in:ar-XA,en-US'],
            'audio_duration' => ['required', 'numeric', "min:{$minDuration}", "max:{$maxDuration}"],
            'consent_duration' => ['required', 'numeric', "min:{$minConsent}", "max:{$maxDuration}"],
        ], [
            'name.required' => __('ui.voice_cloning.validation.name_required'),
            'name.min' => __('ui.voice_cloning.validation.name_min'),
            'name.max' => __('ui.voice_cloning.validation.name_max'),
            'audio_file.required' => __('ui.voice_cloning.validation.audio_required'),
            'audio_file.max' => __('ui.voice_cloning.validation.audio_too_large', ['max' => $maxSizeMb]),
            'audio_file.mimes' => __('ui.voice_cloning.validation.audio_invalid_type'),
            'audio_file.mimetypes' => __('ui.voice_cloning.validation.audio_invalid_type'),
            'consent_confirmed.required' => __('ui.voice_cloning.validation.consent_required'),
            'consent_confirmed.accepted' => __('ui.voice_cloning.validation.consent_required'),
            'audio_duration.min' => __('ui.voice_cloning.validation.audio_too_short', ['min' => $minDuration]),
            'audio_duration.max' => __('ui.voice_cloning.validation.audio_too_long', ['max' => $maxDuration]),
            'consent_audio.required' => __('ui.voice_cloning.validation.consent_audio_required'),
            'consent_duration.required' => __('ui.voice_cloning.validation.consent_audio_required'),
            'consent_duration.min' => __('ui.voice_cloning.validation.consent_too_short', ['min' => $minConsent]),
            'consent_duration.max' => __('ui.voice_cloning.validation.audio_too_long', ['max' => $maxDuration]),
            'source_type.required' => __('ui.voice_cloning.validation.source_type_required'),
            'source_type.in' => __('ui.voice_cloning.validation.source_type_invalid'),
            'language_code.required' => __('ui.voice_cloning.validation.language_required'),
            'language_code.in' => __('ui.voice_cloning.validation.language_invalid'),
            'audio_duration.required' => __('ui.voice_cloning.validation.audio_required'),
        ]);

        // Store the audio file securely (private disk, not public)
        $file = $request->file('audio_file');
        $ext = $file->getClientOriginalExtension() ?: 'wav';
        $filename = "voice-cloning/{$userId}/" . Str::uuid() . ".{$ext}";
        Storage::disk('local')->put($filename, file_get_contents($file->getRealPath()));

        $consentFile = $request->file('consent_audio');
        $consentExt = $consentFile->getClientOriginalExtension() ?: 'wav';
        $consentFilename = "voice-cloning/{$userId}/" . Str::uuid() . "-consent.{$consentExt}";
        Storage::disk('local')->put($consentFilename, file_get_contents($consentFile->getRealPath()));

        $clonedVoice = ClonedVoice::create([
            'user_id' => $userId,
            'name' => $validated['name'],
            'source_type' => $validated['source_type'],
            'source_audio_path' => $filename,
            'consent_audio_path' => $consentFilename,
            'source_audio_duration_seconds' => (int) $validated['audio_duration'],
            'language_code' => $validated['language_code'],
            'provider' => 'gemini',
            'status' => 'pending',
            'consent_confirmed' => true,
            'metadata' => [
                'model' => VoiceCloningService::MODEL,
                'consent_duration_seconds' => (int) $validated['consent_duration'],
                'consent_language' => $validated['consent_language'] ?? $validated['language_code'],
                'consent_script' => VoiceCloningService::consentScriptFor($validated['consent_language'] ?? $validated['language_code']),
            ],
        ]);

        RateLimiter::hit($rateLimitKey, 3600);

        // Run after the response is sent so the studio can poll immediately,
        // even when no queue worker is consuming the database queue.
        ProcessVoiceCloningJob::dispatch($clonedVoice->id)->afterResponse();

        return response()->json([
            'success' => true,
            'message' => __('ui.voice_cloning.submitted_success'),
            'voice' => [
                'id' => $clonedVoice->id,
                'name' => $clonedVoice->name,
                'language_code' => $clonedVoice->language_code,
                'source_type' => $clonedVoice->source_type,
                'status' => $clonedVoice->status,
                'progress_percent' => VoiceCloningService::progressPercent((string) $clonedVoice->status, 0),
                'is_delayed' => false,
                'created_at_ts' => $clonedVoice->created_at?->getTimestamp(),
                'failure_reason' => $clonedVoice->isFailed()
                    ? VoiceCloningService::publicFailureMessage($clonedVoice)
                    : null,
            ],
        ]);
    }

    /**
     * Check the status of a cloned voice (AJAX polling).
     */
    public function status(Request $request, int $id)
    {
        if (! VoiceCloningAccess::allows($request->user())) {
            abort(404);
        }

        $clonedVoice = ClonedVoice::forUser((int) auth()->id())->findOrFail($id);
        $elapsedSeconds = VoiceCloningService::elapsedSeconds($clonedVoice->created_at);

        return response()->json([
            'id' => $clonedVoice->id,
            'name' => $clonedVoice->name,
            'status' => $clonedVoice->status,
            'progress_percent' => VoiceCloningService::progressPercent((string) $clonedVoice->status, $elapsedSeconds),
            'elapsed_seconds' => $elapsedSeconds,
            'is_delayed' => $clonedVoice->isProcessing() && $elapsedSeconds >= 90,
            'created_at_ts' => $clonedVoice->created_at?->getTimestamp(),
            'updated_at_ts' => $clonedVoice->updated_at?->getTimestamp(),
            'language_code' => $clonedVoice->language_code,
            'failure_reason' => $clonedVoice->isFailed()
                ? VoiceCloningService::publicFailureMessage($clonedVoice)
                : null,
            'failure_detail' => $clonedVoice->isFailed() && $request->user()?->is_admin === true
                ? VoiceCloningService::studioFailureDetail($clonedVoice)
                : null,
            'can_retry' => VoiceCloningService::canRetry($clonedVoice),
            'can_replace_consent' => VoiceCloningService::canReplaceConsent($clonedVoice),
            'is_ready' => $clonedVoice->isReady(),
        ]);
    }

    /**
     * Keep the voice sample and send it again with a newly recorded consent sentence.
     */
    public function replaceConsent(Request $request, int $id)
    {
        if (! VoiceCloningAccess::allows($request->user())) {
            abort(404);
        }

        $userId = (int) auth()->id();
        $clonedVoice = ClonedVoice::forUser($userId)->findOrFail($id);

        if (! VoiceCloningService::isEnabled()) {
            return $this->jsonError(__('ui.voice_cloning.feature_disabled'), 403);
        }
        $activePlan = $this->planAccessService->resolveActivePlanForUserId($userId);
        if (! VoiceCloningService::planAllows($activePlan)) {
            return $this->jsonError(__('ui.voice_cloning.plan_disabled'), 403);
        }
        if (! VoiceCloningService::canReplaceConsent($clonedVoice)) {
            return $this->jsonError(__('ui.voice_cloning.retry_not_possible'), 422);
        }

        $activeCount = ClonedVoice::forUser($userId)->where('status', '!=', 'failed')->count();
        $maxVoices = VoiceCloningService::maxVoicesForViewer($request->user(), $activePlan);
        if ($activeCount >= $maxVoices) {
            return $this->jsonError(__('ui.voice_cloning.limit_reached', ['max' => $maxVoices]), 422);
        }

        $minConsent = VoiceCloningService::minConsentDuration();
        $maxDuration = VoiceCloningService::maxSampleDuration();
        $maxSizeKb = VoiceCloningService::maxUploadSizeMb() * 1024;
        $allowedExtValues = VoiceCloningService::allowedExtensions();
        $allowedMimes = VoiceCloningService::allowedMimeTypes();

        $validated = $request->validate([
            'consent_audio' => [
                'required',
                'file',
                "max:{$maxSizeKb}",
                function (string $attribute, mixed $value, \Closure $fail) use ($allowedExtValues, $allowedMimes): void {
                    if (! $value instanceof \Illuminate\Http\UploadedFile) {
                        return;
                    }
                    $extAllowed = in_array(strtolower((string) $value->getClientOriginalExtension()), $allowedExtValues, true)
                        || in_array(strtolower((string) $value->guessExtension()), $allowedExtValues, true);
                    if ($extAllowed || in_array(strtolower((string) $value->getMimeType()), $allowedMimes, true)) {
                        return;
                    }
                    $fail(__('ui.voice_cloning.validation.audio_invalid_type'));
                },
            ],
            'consent_confirmed' => ['required', 'accepted'],
            'consent_language' => ['nullable', 'string', 'in:ar-XA,en-US'],
            'consent_duration' => ['required', 'numeric', "min:{$minConsent}", "max:{$maxDuration}"],
        ], [
            'consent_audio.required' => __('ui.voice_cloning.validation.consent_audio_required'),
            'consent_duration.required' => __('ui.voice_cloning.validation.consent_audio_required'),
            'consent_duration.min' => __('ui.voice_cloning.validation.consent_too_short', ['min' => $minConsent]),
            'consent_duration.max' => __('ui.voice_cloning.validation.audio_too_long', ['max' => $maxDuration]),
            'consent_confirmed.required' => __('ui.voice_cloning.validation.consent_required'),
            'consent_confirmed.accepted' => __('ui.voice_cloning.validation.consent_required'),
        ]);

        $consentFile = $request->file('consent_audio');
        $consentExt = $consentFile->getClientOriginalExtension() ?: 'wav';
        $consentFilename = "voice-cloning/{$userId}/".Str::uuid()."-consent.{$consentExt}";
        Storage::disk('local')->put($consentFilename, file_get_contents($consentFile->getRealPath()));

        $oldConsent = (string) $clonedVoice->consent_audio_path;
        $consentLanguage = $validated['consent_language'] ?? $clonedVoice->language_code;

        $clonedVoice->update([
            'consent_audio_path' => $consentFilename,
            'consent_confirmed' => true,
            'status' => 'pending',
            'failure_reason' => null,
            'metadata' => array_merge($clonedVoice->metadata ?? [], [
                'failure_code' => null,
                'consent_duration_seconds' => (int) $validated['consent_duration'],
                'consent_language' => $consentLanguage,
                'consent_script' => VoiceCloningService::consentScriptFor($consentLanguage),
                'consent_replaced_at' => now()->toIso8601String(),
            ]),
        ]);

        if ($oldConsent !== '' && $oldConsent !== $consentFilename) {
            Storage::disk('local')->delete($oldConsent);
        }

        ProcessVoiceCloningJob::dispatch($clonedVoice->id)->afterResponse();

        return response()->json([
            'success' => true,
            'voice' => [
                'id' => $clonedVoice->id,
                'name' => $clonedVoice->name,
                'language_code' => $clonedVoice->language_code,
                'source_type' => $clonedVoice->source_type,
                'status' => 'pending',
                'progress_percent' => VoiceCloningService::progressPercent('pending', 0),
                'elapsed_seconds' => 0,
                'is_delayed' => false,
                'created_at_ts' => now()->getTimestamp(),
                'failure_reason' => null,
                'can_retry' => false,
                'can_replace_consent' => false,
            ],
        ]);
    }

    /**
     * Send a failed voice to Gemini again with the recordings already saved.
     */
    public function retry(Request $request, int $id)
    {
        if (! VoiceCloningAccess::allows($request->user())) {
            abort(404);
        }

        $userId = (int) auth()->id();
        $clonedVoice = ClonedVoice::forUser($userId)->findOrFail($id);

        if (! VoiceCloningService::isEnabled()) {
            return $this->jsonError(__('ui.voice_cloning.feature_disabled'), 403);
        }
        $activePlan = $this->planAccessService->resolveActivePlanForUserId($userId);
        if (! VoiceCloningService::planAllows($activePlan)) {
            return $this->jsonError(__('ui.voice_cloning.plan_disabled'), 403);
        }
        if (! VoiceCloningService::canRetry($clonedVoice)) {
            return $this->jsonError(__('ui.voice_cloning.retry_not_possible'), 422);
        }

        $activeCount = ClonedVoice::forUser($userId)->where('status', '!=', 'failed')->count();
        $maxVoices = VoiceCloningService::maxVoicesForViewer($request->user(), $activePlan);
        if ($activeCount >= $maxVoices) {
            return $this->jsonError(__('ui.voice_cloning.limit_reached', ['max' => $maxVoices]), 422);
        }

        $clonedVoice->update([
            'status' => 'pending',
            'failure_reason' => null,
            'metadata' => array_merge($clonedVoice->metadata ?? [], [
                'failure_code' => null,
                'retried_at' => now()->toIso8601String(),
            ]),
        ]);
        $clonedVoice->refresh();

        ProcessVoiceCloningJob::dispatch($clonedVoice->id)->afterResponse();

        return response()->json([
            'success' => true,
            'voice' => [
                'id' => $clonedVoice->id,
                'name' => $clonedVoice->name,
                'language_code' => $clonedVoice->language_code,
                'source_type' => $clonedVoice->source_type,
                'status' => $clonedVoice->status,
                'progress_percent' => VoiceCloningService::progressPercent('pending', 0),
                'elapsed_seconds' => 0,
                'is_delayed' => false,
                'created_at_ts' => now()->getTimestamp(),
                'failure_reason' => null,
            ],
        ]);
    }

    /**
     * Delete a cloned voice.
     */
    public function destroy(Request $request, int $id)
    {
        if (! VoiceCloningAccess::allows($request->user())) {
            abort(404);
        }

        $clonedVoice = ClonedVoice::forUser((int) auth()->id())->findOrFail($id);

        if (! VoiceCloningService::canUserDelete($clonedVoice)) {
            $message = __('ui.voice_cloning.delete_ready_blocked');
            if ($request->expectsJson()) {
                return $this->jsonError($message, 422);
            }

            return redirect()->to(route('dashboard').'#voiceCloningSection')
                ->with('error', $message);
        }

        app(VoiceCloningService::class)->deleteReplicatedVoice($clonedVoice->provider_voice_key);

        // Clean up stored audio files
        if (filled($clonedVoice->source_audio_path)) {
            Storage::disk('local')->delete($clonedVoice->source_audio_path);
        }
        if (filled($clonedVoice->consent_audio_path)) {
            Storage::disk('local')->delete($clonedVoice->consent_audio_path);
        }

        $clonedVoice->delete();

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('ui.voice_cloning.deleted_success'),
            ]);
        }

        return redirect()->to(route('dashboard') . '#voiceCloningSection')
            ->with('success', __('ui.voice_cloning.deleted_success'));
    }

    private function jsonError(string $message, int $status = 422): \Illuminate\Http\JsonResponse
    {
        return response()->json(['success' => false, 'error' => $message], $status);
    }
}
