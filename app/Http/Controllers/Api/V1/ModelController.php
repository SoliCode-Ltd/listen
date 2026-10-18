<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiDocs;
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

        // Options no plan includes are not described (as in the docs).
        $offered = ApiFeatureGate::offered();
        $options = array_keys(array_filter([
            'Arabic dialects' => true,
            'English accents' => true,
            'delivery styles' => true,
            'emotions' => true,
            'inline sound tags such as <laugh> or <short pause>' => true,
            'sound environments' => $offered[ApiFeatureGate::SOUND_ENVIRONMENTS],
            'dialogue' => $offered[ApiFeatureGate::DIALOGUE],
        ]));

        return response()->json([
            'models' => [
                [
                    'model_id' => ApiModels::PREMIUM,
                    'name' => ApiModels::NAME,
                    'description' => 'Expressive studio voices for Arabic and English, with '.ApiDocs::series($options, false).'.',
                    'languages' => ['ar', 'en'],
                    'available_on_plan' => true,
                    'max_characters_per_request' => ApiFeatureGate::maxChars($quotaPlan),
                    'max_characters_per_async_request' => ApiFeatureGate::maxChars($quotaPlan, true),
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
