<?php

namespace App\Http\Controllers;

use App\Http\Middleware\CaptureAdClickId;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\CharPackService;
use App\Services\Billing\PlanChanges;
use App\Services\LocalizedPricingService;
use App\Services\ManualPaymentService;
use DomainException;
use Illuminate\Http\Request;

class ManualPaymentController extends Controller
{
    public function __construct(
        private readonly LocalizedPricingService $localizedPricingService,
        private readonly ManualPaymentService $manualPaymentService,
        private readonly CharPackService $charPacks,
        private readonly PlanChanges $planChanges,
    ) {}

    /**
     * A plan or pack on sale. A pack is always paid once ("one_time");
     * a monthly plan never is.
     *
     * @param  array<string, mixed>  $validated
     */
    private function payablePlan(array $validated): Plan
    {
        $plan = Plan::query()
            ->where('slug', $validated['plan'])
            ->where('is_active', true)
            ->where('price_cents', '>', 0)
            ->firstOrFail();

        abort_if(! $plan->isPack() && $validated['billing_cycle'] === 'one_time', 404);

        return $plan;
    }

    /**
     * A lower plan paid by hand that waits for its day is already paid for: another plan
     * paid now would call it off, so the team handles it. The member is told, or null.
     */
    private function paidChangeWaitingMessage(?User $user): ?string
    {
        $waiting = $user ? $this->planChanges->paidChangeWaiting($user) : null;

        return $waiting ? __('ui.plan_change.paid_change_waiting', [
            'plan' => $waiting->toPlan ? ($waiting->toPlan->getLocalizedName(app()->getLocale()) ?: (string) $waiting->toPlan->name) : '',
            'date' => $this->planChanges->date($waiting->starts_at, app()->getLocale()),
        ]) : null;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $countryContext
     * @return array<string, mixed>|null
     */
    private function quote(Plan $plan, array $validated, array $countryContext, ?User $user): ?array
    {
        // A move down pays the lower plan's full price: no offer (PlanChanges).
        if ($user && ! $plan->isPack() && $this->planChanges->movesDown($user, $plan, (string) $validated['billing_cycle'])) {
            $plan = $plan->withoutOffer();
        }

        $quote = $this->localizedPricingService->manualQuoteForPlan(
            $plan,
            $plan->isPack() ? 'monthly' : $validated['billing_cycle'],
            $countryContext,
            $validated['payment_method'],
        );

        if ($quote !== null && $plan->isPack()) {
            $quote['cycle'] = 'one_time';
            $quote['interval'] = null;
        }

        return $quote;
    }

    public function instructions(Request $request)
    {
        $validated = $request->validate([
            'plan' => ['required', 'string', 'max:50'],
            'billing_cycle' => ['required', 'in:monthly,yearly,one_time'],
            'payment_method' => ['required', 'in:instapay,vodafone_cash'],
        ]);

        $plan = $this->payablePlan($validated);
        if ($plan->isPack() && ! $this->charPacks->canBuy($request->user(), $plan)) {
            return redirect()->route('billing.pack');
        }
        if (! $plan->isPack() && ($waiting = $this->paidChangeWaitingMessage($request->user())) !== null) {
            return redirect()->route('billing.page')->withErrors(['manual_payment' => $waiting]);
        }
        if ($plan->isPack()) {
            $validated['billing_cycle'] = 'one_time';
        }

        $countryContext = $this->localizedPricingService->resolveCountryContext($request);
        $quote = $this->quote($plan, $validated, $countryContext, $request->user());

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
            'billing_cycle' => ['required', 'in:monthly,yearly,one_time'],
            'payment_method' => ['required', 'in:instapay,vodafone_cash'],
            'proof_image' => ['nullable', 'image', 'max:5120'],
        ]);

        $plan = $this->payablePlan($validated);
        if ($plan->isPack() && ! $this->charPacks->canBuy($request->user(), $plan)) {
            $message = __('ui.pack.plan_includes_premium');
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $message], 409);
            }

            return redirect()->route('billing.pack')->withErrors(['manual_payment' => $message]);
        }
        if (! $plan->isPack() && ($waiting = $this->paidChangeWaitingMessage($request->user())) !== null) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $waiting], 409);
            }

            return redirect()->route('billing.page')->withErrors(['manual_payment' => $waiting]);
        }
        if ($plan->isPack()) {
            $validated['billing_cycle'] = 'one_time';
        }

        $countryContext = $this->localizedPricingService->resolveCountryContext($request);
        $quote = $this->quote($plan, $validated, $countryContext, $request->user());

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
                CaptureAdClickId::fromRequest($request),
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

        if ($plan->isPack()) {
            return redirect()->route('billing.pack')->with('manual_payment_success', __('ui.billing.manual_request_received'));
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
