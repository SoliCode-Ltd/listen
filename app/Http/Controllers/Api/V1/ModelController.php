<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiFeatureGate;
use App\Support\Api\ApiModels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /v1/models
 */
class ModelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $plan = $request->attributes->get('api_plan');
        $quotaPlan = $request->attributes->get('api_quota_plan');

        return response()->json([
            'models' => [
                [
                    'model_id' => ApiModels::PREMIUM,
                    'name' => ApiModels::NAME,
                    'description' => 'Expressive studio voices for Arabic and English, with Arabic dialects, English accents, delivery styles, emotions, inline sound tags such as <laugh> or <short pause>, sound environments and dialogue.',
                    'languages' => ['ar', 'en'],
                    'available_on_plan' => true,
                    'max_characters_per_request' => ApiFeatureGate::maxChars($quotaPlan),
                    // What this account's plan can use; the rest is refused with feature_not_in_plan.
                    'supports' => [
                        'dialect' => true,
                        'style' => true,
                        'emotion' => true,
                        'voice_settings' => true,
                        'inline_tags' => true,
                    ] + ApiFeatureGate::availableTo($user, $plan),
                ],
            ],
        ]);
    }
}
