<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Api\ApiSpeechService;
use App\Services\PlanAccessService;
use App\Support\Api\ApiModels;
use App\Support\PlanFeatures;
use App\Support\PlanUsageGuard;
use App\Support\VoiceTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /v1/models
 */
class ModelController extends Controller
{
    public function index(Request $request, PlanAccessService $planAccessService): JsonResponse
    {
        $plan = $request->attributes->get('api_plan');
        $quotaPlan = $request->attributes->get('api_quota_plan');
        $planTier = $planAccessService->resolveTierForPlan($plan);
        $maxChars = min(PlanUsageGuard::maxCharsPerRequest($quotaPlan), ApiSpeechService::MAX_TEXT);

        return response()->json([
            'models' => [
                [
                    'model_id' => ApiModels::STANDARD,
                    'name' => 'Listen Standard',
                    'description' => 'Clear, fast neural voices for Arabic and English. Best for high-volume reading, notifications and accessibility.',
                    'languages' => ['ar', 'en'],
                    'available_on_plan' => true,
                    'max_characters_per_request' => $maxChars,
                    'supports' => [
                        'dialect' => false,
                        'style' => false,
                        'emotion' => false,
                        'style_prompt' => false,
                        'voice_settings' => false,
                        'inline_tags' => false,
                        'cloned_voices' => false,
                    ],
                ],
                [
                    'model_id' => ApiModels::PREMIUM,
                    'name' => 'Listen Premium',
                    'description' => 'Expressive studio voices with Arabic dialects, English accents, delivery styles, emotions and inline sound tags such as <laugh> or <short pause>.',
                    'languages' => ['ar', 'en'],
                    'available_on_plan' => ! VoiceTier::isHigherThan(VoiceTier::HD, $planTier),
                    'max_characters_per_request' => $maxChars,
                    'supports' => [
                        'dialect' => true,
                        'style' => true,
                        'emotion' => true,
                        'style_prompt' => PlanFeatures::planOffers($plan, PlanFeatures::PROMPT),
                        'voice_settings' => true,
                        'inline_tags' => true,
                        'cloned_voices' => true,
                    ],
                ],
            ],
        ]);
    }
}
