<?php

namespace App\Http\Controllers;

use App\Models\CharPackPurchase;
use App\Models\ManualPaymentRequest;
use App\Services\Billing\CharPackCardPayment;
use App\Services\Billing\CharPackService;
use App\Services\LocalizedPricingService;
use App\Services\PlanAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Buying the one-time character pack: by card here, or by InstaPay /
 * Vodafone Cash through the manual payment page.
 */
class CharPackController extends Controller
{
    public function __construct(
        private readonly CharPackService $packs,
        private readonly LocalizedPricingService $pricing,
        private readonly PlanAccessService $plans,
    ) {}

    /**
     * Pricing page button: guests sign up first, then land on the pack page.
     */
    public function start(Request $request)
    {
        if (! $request->user()) {
            $request->session()->put('url.intended', route('billing.pack'));

            return redirect()->route('register');
        }

        return redirect()->route('billing.pack');
    }

    public function show(Request $request)
    {
        $user = $request->user();
        $pack = $this->packs->offeredPack();
        if (! $pack) {
            return redirect()->route('pricing');
        }

        $locale = app()->getLocale();
        $country = $this->pricing->resolveCountryContext($request);
        $manualOptions = [];
        foreach ($this->pricing->enabledManualMethods((string) ($country['country_code'] ?? '')) as $method) {
            $quote = $this->pricing->manualQuoteForPlan($pack, 'monthly', $country, $method, $locale);
            if ($quote !== null) {
                $manualOptions[] = [
                    'method' => $method,
                    'meta' => $this->pricing->paymentMethodMeta($method),
                    'amount' => (string) $quote['amount_formatted'],
                    'url' => route('billing.manual.instructions', ['plan' => $pack->slug, 'billing_cycle' => 'one_time', 'payment_method' => $method]),
                ];
            }
        }

        $pendingManual = ManualPaymentRequest::query()
            ->where('user_id', $user->id)
            ->where('plan_id', $pack->id)
            ->where('status', ManualPaymentRequest::STATUS_PENDING)
            ->latest('id')
            ->first();

        return view('billing-pack', [
            'pack' => $pack,
            'packName' => $pack->getLocalizedName($locale),
            'cardPrice' => $this->pricing->formatAmount((int) $pack->getEffectivePriceCents(), 'USD', 'en'),
            'canBuy' => $this->packs->canBuy($user, $pack),
            'currentPlan' => $this->plans->resolveActivePlanForUserId((int) $user->id),
            'wallet' => $this->packs->summary((int) $user->id),
            'manualOptions' => $manualOptions,
            'pendingManual' => $pendingManual,
            'stripeReady' => $this->stripeReady(),
            'stripeKey' => (string) config('services.stripe.key'),
        ]);
    }

    public function payByCard(Request $request, CharPackCardPayment $card): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => ['required', 'string', 'starts_with:pm_', 'max:255'],
        ]);

        $user = $request->user();
        $pack = $this->packs->offeredPack();
        if (! $pack || ! $this->stripeReady()) {
            return response()->json(['ok' => false, 'message' => __('ui.pack.not_available')], 422);
        }
        if (! $this->packs->canBuy($user, $pack)) {
            return response()->json(['ok' => false, 'message' => __('ui.pack.plan_includes_premium')], 409);
        }

        return $this->respond($card->start($user, $pack, $validated['payment_method']));
    }

    public function confirmCard(Request $request, CharPackCardPayment $card): JsonResponse
    {
        $validated = $request->validate([
            'purchase' => ['required', 'integer'],
        ]);

        $purchase = CharPackPurchase::query()
            ->whereKey($validated['purchase'])
            ->where('user_id', $request->user()->id)
            ->where('method', CharPackPurchase::METHOD_CARD)
            ->firstOrFail();

        return $this->respond($card->confirm($purchase));
    }

    /**
     * @param  array{status:string, purchase:CharPackPurchase, client_secret?:?string, message?:string}  $result
     */
    private function respond(array $result): JsonResponse
    {
        $purchase = $result['purchase'];

        return match ($result['status']) {
            // The frontend counts a purchase only on ok:true.
            'paid' => $this->paid($purchase),
            'requires_action' => response()->json([
                'ok' => false,
                'requires_action' => true,
                'client_secret' => $result['client_secret'] ?? null,
                'purchase' => $purchase->id,
            ], 402),
            'processing' => response()->json([
                'ok' => false,
                'processing' => true,
                'message' => $result['message'] ?? __('ui.pack.card_processing'),
            ], 202),
            default => response()->json([
                'ok' => false,
                'message' => $result['message'] ?? __('ui.pack.card_failed'),
            ], 422),
        };
    }

    private function paid(CharPackPurchase $purchase): JsonResponse
    {
        $message = __('ui.pack.added', ['count' => number_format((int) $purchase->chars)]);
        session()->flash('ok', $message);

        return response()->json([
            'ok' => true,
            'message' => $message,
            'purchase' => $purchase->id,
            'redirect' => route('dashboard'),
        ]);
    }

    private function stripeReady(): bool
    {
        return trim((string) config('services.stripe.key', '')) !== ''
            && trim((string) config('services.stripe.secret', '')) !== '';
    }
}
