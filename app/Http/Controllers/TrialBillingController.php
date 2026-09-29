<?php

namespace App\Http\Controllers;

use App\Exceptions\TrialStartException;
use App\Services\Billing\TrialSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrialBillingController extends Controller
{
    public function __construct(
        private readonly TrialSubscriptionService $trials,
    ) {}

    public function setup(Request $request): JsonResponse
    {
        if (! $this->stripeReady()) {
            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.billing_not_configured'),
            ], 422);
        }

        $validated = $request->validate([
            'plan' => ['required', 'string', 'max:50'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
        ]);

        try {
            $payload = $this->trials->beginSetup($request->user(), $validated);
        } catch (TrialStartException $exception) {
            return response()->json([
                'ok' => false,
                'message' => __($exception->errorKey),
            ], $exception->status);
        }

        return response()->json([
            'ok' => true,
            'client_secret' => $payload['client_secret'],
            'setup_intent_id' => $payload['setup_intent_id'],
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        if (! $this->stripeReady()) {
            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.billing_not_configured'),
            ], 422);
        }

        $validated = $request->validate([
            'setup_intent_id' => ['required', 'string', 'max:255'],
        ]);

        try {
            $payload = $this->trials->confirm($request->user(), (string) $validated['setup_intent_id']);
        } catch (TrialStartException $exception) {
            return response()->json([
                'ok' => false,
                'message' => __($exception->errorKey),
            ], $exception->status);
        }

        return response()->json([
            'ok' => true,
            'trial' => true,
            'message' => __('ui.backend.trial_started'),
            'trial_ends_at' => $payload['trial_ends_at'],
            'plan' => $payload['plan'],
            'amount_cents' => $payload['amount_cents'],
        ]);
    }

    private function stripeReady(): bool
    {
        return trim((string) config('services.stripe.key', '')) !== ''
            && trim((string) config('services.stripe.secret', '')) !== '';
    }
}
