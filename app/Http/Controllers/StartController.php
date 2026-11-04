<?php

namespace App\Http\Controllers;

use App\Services\PlanAccessService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A new member's first page: "What do you want to do first?", voice-overs in
 * the studio or a voice agent for their business. It only decides where they
 * start; both stay one click away (the Studio | Agents switch).
 */
class StartController extends Controller
{
    public function __invoke(Request $request, PlanAccessService $plans): View
    {
        $plan = $plans->resolveAccessPlanForUserId((int) $request->user()->id);
        $paid = $plan !== null && ($plan->isPack() || (int) $plan->price_cents > 0);

        return view('agents.start', [
            // Free members start the studio on the demo, as after any sign-in.
            'studioUrl' => $paid || $request->user()->is_admin ? route('dashboard') : route('demo.page'),
            'agentsUrl' => route('voice-agents.create'),
        ]);
    }
}
