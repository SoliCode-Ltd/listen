<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\LocalizedPricingService;
use App\Services\ManualPaymentService;
use DomainException;
use Illuminate\Http\Request;

class ManualPaymentController extends Controller
{
    public function __construct(
        private readonly LocalizedPricingService $localizedPricingService,
        private readonly ManualPaymentService $manualPaymentService,
    ) {}

    public function instructions(Request $request)
    {
        $validated = $request->validate([
            'plan' => ['required', 'string', 'max:50'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
            'payment_method' => ['required', 'in:instapay,vodafone_cash'],
        ]);

        $plan = Plan::query()
            ->where('slug', $validated['plan'])
            ->where('is_active', true)
            ->where('price_cents', '>', 0)
            ->firstOrFail();

        $countryContext = $this->localizedPricingService->resolveCountryContext($request);
        $quote = $this->localizedPricingService->manualQuoteForPlan(
            $plan,
            $validated['billing_cycle'],
            $countryContext,
            $validated['payment_method'],
        );

        abort_if($quote === null, 404);

        $pendingRequest = $this->manualPaymentService->latestPendingRequest(
            $request->user(),
            $plan,
            $validated['billing_cycle'],
            $validated['payment_method'],
        );

        return view('billing-manual', [
            'plan' => $plan,
            'billingCycle' => $validated['billing_cycle'],
            'paymentMethod' => $validated['payment_method'],
            'paymentMethodMeta' => $this->localizedPricingService->paymentMethodMeta($validated['payment_method']),
            'countryContext' => $countryContext,
            'quote' => array_merge($quote, ['country_code' => $countryContext['country_code']]),
            'destination' => $this->localizedPricingService->manualPaymentDestination($validated['payment_method']),
            'pendingRequest' => $pendingRequest,
        ]);
    }

    public function confirm(Request $request)
    {
        $validated = $request->validate([
            'plan' => ['required', 'string', 'max:50'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
            'payment_method' => ['required', 'in:instapay,vodafone_cash'],
            'proof_image' => ['nullable', 'image', 'max:5120'],
        ]);

        $plan = Plan::query()
            ->where('slug', $validated['plan'])
            ->where('is_active', true)
            ->where('price_cents', '>', 0)
            ->firstOrFail();

        $countryContext = $this->localizedPricingService->resolveCountryContext($request);
        $quote = $this->localizedPricingService->manualQuoteForPlan(
            $plan,
            $validated['billing_cycle'],
            $countryContext,
            $validated['payment_method'],
        );

        if ($quote === null) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => __('ui.billing.manual_payment_not_available'),
                ], 422);
            }

            return redirect()
                ->route('billing.page')
                ->withInput([
                    'plan' => $validated['plan'],
                    'billing_cycle' => $validated['billing_cycle'],
                    'payment_method' => $validated['payment_method'],
                ])
                ->withErrors(['payment_method' => __('ui.billing.manual_payment_not_available')]);
        }

        try {
            $this->manualPaymentService->createRequest(
                $request->user(),
                $plan,
                $validated['billing_cycle'],
                $validated['payment_method'],
                array_merge($quote, ['country_code' => $countryContext['country_code']]),
                array_merge(
                    $this->localizedPricingService->manualPaymentDestination($validated['payment_method']),
                    ['country_code' => $countryContext['country_code']]
                ),
                $request->file('proof_image'),
            );
        } catch (DomainException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => $exception->getMessage(),
                ], 422);
            }

            return redirect()
                ->route('billing.page', [
                    'plan' => $validated['plan'],
                    'billing_cycle' => $validated['billing_cycle'],
                    'payment_method' => $validated['payment_method'],
                ])
                ->withErrors(['manual_payment' => $exception->getMessage()]);
        }

        // NOTE: The frontend fires a Google Ads conversion event (gtag)
        // when it receives { ok: true } from this endpoint. Do NOT return
        // ok:true unless the payment request was successfully created.
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => __('ui.billing.manual_request_received'),
            ]);
        }

        return redirect()
            ->route('billing.page', [
                'plan' => $validated['plan'],
                'billing_cycle' => $validated['billing_cycle'],
                'payment_method' => $validated['payment_method'],
            ])
            ->with('manual_payment_success', __('ui.billing.manual_request_received'));
    }
}
