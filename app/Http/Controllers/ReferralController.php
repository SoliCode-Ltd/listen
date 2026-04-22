<?php

namespace App\Http\Controllers;

use App\Models\SocialShareLog;
use App\Services\Referral\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function __construct(private readonly ReferralService $referralService) {}

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        return response()->json([
            'ok' => true,
            'stats' => $this->referralService->statsPayload($user),
        ]);
    }

    public function share(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $validated = $request->validate([
            'platform' => ['required', 'string', 'in:'.implode(',', SocialShareLog::PLATFORMS)],
        ]);

        $log = $this->referralService->logShare($user, $validated['platform'], $request);

        return response()->json([
            'ok' => true,
            'share_id' => $log->id,
            'stats' => $this->referralService->statsPayload($user),
        ]);
    }
}
