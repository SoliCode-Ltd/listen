<?php

namespace App\Http\Controllers;

use App\Services\Agents\AgentCalls;
use App\Support\Agents\DemoAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The demo page's Voice agents tab (App\Support\Agents\DemoAgent): a live call with a sample
 * business's agent. It comes out of the member's free test seconds, never their credits.
 */
class DemoAgentController extends Controller
{
    public function __construct(private AgentCalls $calls) {}

    public function start(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(DemoAgent::openTo($user), 404);
        $data = $request->validate(['preset' => ['required', 'string', Rule::in(DemoAgent::PRESETS)]]);
        if ($this->calls->freeTestSecondsLeft((int) $user->id) < DemoAgent::MIN_SECONDS) {
            return $this->usedUp();
        }

        $agent = DemoAgent::agentFor($user, $data['preset'], app()->getLocale() === 'ar' ? 'ar' : 'en');
        $result = $this->calls->start($agent, 'test', null, [
            'origin' => $request->getSchemeAndHttpHost(),
            'visitor' => 'demo-'.$user->id,
            'free_only' => true,
        ]);
        if (! $result['ok']) {
            if ($result['error'] === 'free_used') {
                return $this->usedUp();
            }

            return response()->json([
                'message' => $result['error'] === 'stopped' ? __('agents.error_paused') : __('agents.error_busy'),
                'reason' => $result['error'],
            ], 422);
        }

        return response()->json(AgentCallController::startPayload($agent, $result));
    }

    /** The free call time left, after a call (the page writes it under the call button). */
    public function left(Request $request): JsonResponse
    {
        abort_unless(DemoAgent::openTo($request->user()), 404);
        $left = $this->calls->freeTestSecondsLeft((int) $request->user()->id);

        return response()->json([
            'seconds' => $left,
            'clock' => DemoAgent::clock($left),
            'can_call' => $left >= DemoAgent::MIN_SECONDS,
        ]);
    }

    private function usedUp(): JsonResponse
    {
        return response()->json(['message' => __('agents.demo.used_up'), 'reason' => 'free_used'], 422);
    }
}
