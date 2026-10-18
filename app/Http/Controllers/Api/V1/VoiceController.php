<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Api\ApiSpeechService;
use App\Services\PlanAccessService;
use App\Support\Api\ApiError;
use App\Support\Api\ApiFeatureGate;
use App\Support\Api\ApiModels;
use App\Support\Api\ApiVoiceCatalog;
use App\Support\AudioEffects;
use App\Support\SpeechLanguages;
use App\Support\TtsStudioConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /v1/voices, GET /v1/voices/{voice_id}, GET /v1/voice-options
 */
class VoiceController extends Controller
{
    public function __construct(private readonly PlanAccessService $planAccessService) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'language' => ['nullable', 'string', 'in:'.implode(',', SpeechLanguages::codes())],
            'model_id' => ['nullable', 'string', 'in:'.implode(',', ApiModels::ids())],
            'gender' => ['nullable', 'string', 'in:female,male'],
            'category' => ['nullable', 'string', 'in:premade,cloned'],
        ]);

        $plan = $request->attributes->get('api_plan');
        $voices = collect(ApiVoiceCatalog::forUser($request->user(), $plan, $this->planAccessService->resolveTierForPlan($plan)))
            ->filter(function (array $voice) use ($filters): bool {
                if (isset($filters['language']) && $voice['language'] !== null && $voice['language'] !== SpeechLanguages::voiceLanguage($filters['language'])) {
                    return false;
                }
                foreach (['model_id', 'gender', 'category'] as $field) {
                    if (isset($filters[$field]) && $voice[$field] !== $filters[$field]) {
                        return false;
                    }
                }

                return true;
            })
            ->map(fn (array $voice): array => ApiVoiceCatalog::public($voice))
            ->values();

        return response()->json([
            'voices' => $voices,
            'total_count' => $voices->count(),
        ]);
    }

    public function show(Request $request, string $voiceId): JsonResponse
    {
        $plan = $request->attributes->get('api_plan');
        $voice = ApiVoiceCatalog::find($request->user(), $plan, $this->planAccessService->resolveTierForPlan($plan), $voiceId);
        if ($voice === null) {
            throw ApiError::notFound('voice', 'voice_id');
        }

        return response()->json(ApiVoiceCatalog::public($voice));
    }

    /**
     * Dialects, delivery styles, emotions and sound environments, with the
     * site's defaults, and the output formats and options this account can use.
     */
    public function options(Request $request): JsonResponse
    {
        $row = static fn (array $item): array => [
            'value' => (string) $item['value'],
            'name' => (string) $item['label'],
            'name_ar' => (string) ($item['label_ar'] ?? $item['label']),
        ];

        $dialects = [];
        foreach (['ar', 'en'] as $language) {
            $dialects[$language] = array_map($row, TtsStudioConfig::dialectCatalogForLanguage($language));
        }

        $plan = $request->attributes->get('api_plan');
        $features = ApiFeatureGate::availableTo($request->user(), $plan);
        // An option no plan includes is left out, unless this account has it anyway.
        $offered = ApiFeatureGate::offered();
        $optional = array_filter([
            'audio_effects' => $offered[ApiFeatureGate::SOUND_ENVIRONMENTS] || $features['audio_effect']
                ? array_map($row, AudioEffects::options()) : null,
            'dialogue' => $offered[ApiFeatureGate::DIALOGUE] || $features['dialogue']
                ? ['min_speakers' => 2, 'max_speakers' => 6, 'max_characters' => ApiSpeechService::maxCharsFor($request->attributes->get('api_quota_plan'), true, true)] : null,
        ], fn ($value): bool => $value !== null);

        return response()->json([
            'model_id' => ApiModels::PREMIUM,
            'dialects' => $dialects,
            'styles' => array_map($row, TtsStudioConfig::performanceStyleCatalog()),
            'emotions' => array_map($row, TtsStudioConfig::emotionCatalog()),
            'defaults' => [
                'ar' => [
                    'dialect' => TtsStudioConfig::defaultDialect('ar') ?: null,
                    'style' => TtsStudioConfig::defaultPerformanceStyle('ar'),
                    'emotion' => TtsStudioConfig::defaultEmotion('ar'),
                ],
                'en' => [
                    'dialect' => TtsStudioConfig::defaultDialect('en') ?: null,
                    'style' => TtsStudioConfig::defaultPerformanceStyle('en'),
                    'emotion' => TtsStudioConfig::defaultEmotion('en'),
                ],
            ],
            'voice_settings' => [
                'speed' => ['min' => 0.5, 'max' => 2.0, 'default' => 1.0],
                'pitch' => ['min' => -10, 'max' => 10, 'default' => 0],
            ],
        ] + $optional + [
            'output_formats' => ApiSpeechService::formatsFor($plan),
            // The options only some plans include, and whether this account has them.
            'features' => $features,
        ]);
    }
}
