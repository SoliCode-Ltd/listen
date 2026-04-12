<?php

namespace App\Http\Controllers;

use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BillingSyncService;
use App\Services\DemoHistoryImporter;
use App\Services\LocalizedPricingService;
use App\Services\PlanAccessService;
use App\Services\StripeWebhookEmailService;
use App\Support\VoiceTier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Exceptions\SubscriptionUpdateFailure;
use Stripe\Exception\ApiErrorException;

class BillingController extends Controller
{
    public function __construct(
        private readonly BillingSyncService $billingSyncService,
        private readonly DemoHistoryImporter $demoHistoryImporter,
        private readonly LocalizedPricingService $localizedPricingService,
        private readonly PlanAccessService $planAccessService,
        private readonly StripeWebhookEmailService $stripeWebhookEmailService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $this->billingSyncService->syncIfStale($user);

        $activePlan = $this->planAccessService->resolveActivePlanForUserId((int) $user->id);
        $subscription = $this->currentDefaultSubscription($user);
        $activePlan = $this->resolveConsistentActivePlan($activePlan, $subscription);
        $stripeKey = (string) config('services.stripe.key', '');
        $stripeSecret = (string) config('services.stripe.secret', '');
        $stripeReady = trim($stripeKey) !== '' && trim($stripeSecret) !== '';

        $defaultPaymentMethod = $this->loadDefaultPaymentMethod($user, $stripeReady);
        $countryContext = $this->localizedPricingService->resolveCountryContext($request);
        $selectedPlan = trim((string) $request->query('plan', ''));
        $validPlanSlugs = Plan::query()
            ->where('is_active', true)
            ->where('price_cents', '>', 0)
            ->pluck('slug')
            ->all();

        return view('billing', [
            'activePlan' => $activePlan,
            'currentPlanSlug' => (string) ($activePlan?->slug ?? 'free'),
            'activeTierLabel' => VoiceTier::label((string) ($activePlan?->voice_tier ?: 'standard')),
            'plans' => $this->resolveCheckoutPlans($stripeReady, $countryContext),
            'yearlyDiscountPercent' => SiteSetting::yearlyDiscountPercent(),
            'selectedPlan' => in_array($selectedPlan, $validPlanSlugs, true) ? $selectedPlan : '',
            'stripeReady' => $stripeReady,
            'stripeKey' => $stripeKey,
            'defaultPaymentMethod' => $defaultPaymentMethod,
            'countryContext' => $countryContext,
            'paymentBrandAssets' => $this->localizedPricingService->paymentBrandAssets(),
        ]);
    }

    public function manage(Request $request)
    {
        $user = $request->user();
        $this->billingSyncService->syncIfStale($user, true);

        $activePlan = $this->planAccessService->resolveActivePlanForUserId((int) $user->id);
        $subscription = $this->currentDefaultSubscription($user);
        $activePlan = $this->resolveConsistentActivePlan($activePlan, $subscription);
        $stripeKey = (string) config('services.stripe.key', '');
        $stripeSecret = (string) config('services.stripe.secret', '');
        $stripeReady = trim($stripeKey) !== '' && trim($stripeSecret) !== '';

        $defaultPaymentMethod = $this->loadDefaultPaymentMethod($user, $stripeReady);

        $countryContext = $this->localizedPricingService->resolveCountryContext($request);
        $locale = (string) app()->getLocale();

        $invoices = $this->loadInvoices($user, $stripeReady, $countryContext, $locale);

        $periodEnd = null;
        if ($subscription && $stripeReady && $user->hasStripeId()) {
            try {
                $periodEnd = $subscription->currentPeriodEnd()?->toDateString();
            } catch (\Throwable $exception) {
                Log::warning('Unable to resolve Stripe current period end for manage subscription page.', [
                    'user_id' => $user->id,
                    'stripe_id' => $user->stripe_id,
                    'subscription_id' => $subscription->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $hasStripeCustomer = $user->hasStripeId();
        $billingProfileRemoved = ! $hasStripeCustomer
            && (bool) ($subscription?->id)
            && trim((string) ($subscription?->stripe_id ?? '')) !== '';

        return view('manage-subscription', [
            'activePlan' => $activePlan,
            'activePlanName' => $activePlan
                ? ($activePlan->getLocalizedName($locale) ?: $activePlan->name)
                : __('ui.dashboard.free_plan'),
            'activeTierLabel' => VoiceTier::label((string) ($activePlan?->voice_tier ?: 'standard')),
            'stripeReady' => $stripeReady,
            'stripeKey' => $stripeKey,
            'defaultPaymentMethod' => $defaultPaymentMethod,
            'invoices' => $invoices,
            'hasActiveSubscription' => (bool) ($subscription?->valid() ?? false),
            'hasCancelableSubscription' => (bool) ($subscription?->valid() ?? false) && ! ($subscription?->onGracePeriod() ?? false),
            'isCancellationScheduled' => (bool) ($subscription?->onGracePeriod() ?? false),
            'billingProfileRemoved' => $billingProfileRemoved,
            'subscriptionStatus' => $this->subscriptionStatusLabel($subscription, $hasStripeCustomer),
            'subscriptionEndsAt' => $this->formatDateLabel(($subscription?->onGracePeriod() ?? false) ? $subscription?->ends_at?->toDateString() : null),
            'subscriptionRenewsAt' => $this->formatDateLabel($periodEnd),
            'upcomingCharge' => $this->resolveUpcomingCharge($subscription, $countryContext, $locale),
        ]);
    }

    public function subscribe(Request $request)
    {
        if (! $this->isStripeReady()) {
            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.billing_not_configured'),
            ], 422);
        }

        $user = $request->user();

        $validated = $request->validate([
            'plan' => ['required', 'string', 'max:50'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'save_card' => ['nullable', 'boolean'],
        ]);

        // Dynamically validate plan slug exists as an active paid plan
        $planRecord = Plan::query()
            ->where('slug', $validated['plan'])
            ->where('is_active', true)
            ->where('price_cents', '>', 0)
            ->first();

        if (! $planRecord) {
            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.billing_invalid_plan'),
            ], 422);
        }

        $priceId = $this->resolveStripePriceId($validated['plan'], $validated['billing_cycle']);
        if ($priceId === '') {
            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.billing_price_not_configured'),
            ], 422);
        }

        $paymentMethod = trim((string) ($validated['payment_method'] ?? ''));
        $saveCard = $validated['save_card'] ?? true;

        // Resolve active discount coupon for this plan/cycle.
        $couponId = $this->resolveCheckoutCouponId($planRecord, (string) $validated['billing_cycle']);

        try {
            $this->ensureStripeCustomer($user);

            if ($paymentMethod !== '') {
                $this->assertCardPaymentMethod($paymentMethod);
                // Only save card as default if user consented
                if ($saveCard) {
                    $user->updateDefaultPaymentMethod($paymentMethod);
                }
            }

            $subscription = $this->currentDefaultSubscription($user);

            if ($subscription && $subscription->valid()) {
                if (! $subscription->hasPrice($priceId)) {
                    try {
                        $subscription->swap($priceId);
                        // Apply coupon to existing subscription swap
                        if ($couponId && $subscription->stripe_id) {
                            try {
                                Cashier::stripe()->subscriptions->update($subscription->stripe_id, [
                                    'coupon' => $couponId,
                                ]);
                            } catch (\Throwable $e) {
                                Log::warning('Could not apply discount coupon to swapped subscription', [
                                    'coupon' => $couponId,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                        if ($paymentMethod !== '') {
                            $this->setSubscriptionPaymentMethod($user, $subscription, $paymentMethod);
                        }
                    } catch (SubscriptionUpdateFailure $exception) {
                        // Incomplete / stale local records must be retired before recreating.
                        $this->retireStaleSubscription($subscription);
                        $this->createNewSubscription($user, $priceId, $paymentMethod, $couponId);
                    }
                } elseif ($paymentMethod !== '') {
                    $this->setSubscriptionPaymentMethod($user, $subscription, $paymentMethod);
                }
            } else {
                if ($subscription) {
                    $this->retireStaleSubscription($subscription);
                }

                $this->createNewSubscription($user, $priceId, $paymentMethod, $couponId);
            }
        } catch (IncompletePayment $exception) {
            return response()->json([
                'ok' => false,
                'requires_action' => true,
                'client_secret' => $exception->payment->client_secret,
                'message' => __('ui.backend.billing_auth_required'),
            ], 402);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $firstMessage = is_array($errors['payment_method'] ?? null)
                ? (string) ($errors['payment_method'][0] ?? '')
                : '';

            return response()->json([
                'ok' => false,
                'message' => $firstMessage !== '' ? $firstMessage : __('ui.backend.billing_add_payment_method'),
            ], 422);
        } catch (\Throwable $exception) {
            Log::warning('Unable to update Stripe subscription.', [
                'user_id' => $user->id,
                'plan' => $validated['plan'] ?? null,
                'billing_cycle' => $validated['billing_cycle'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.billing_update_subscription_failed'),
            ], 422);
        }

        $this->clearApprovedManualOverride($user);
        $this->billingSyncService->syncIfStale($user, true);
        $activePlan = $this->planAccessService->resolveActivePlanForUserId((int) $user->id);

        if (! $activePlan || $activePlan->slug !== $validated['plan']) {
            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.billing_plan_not_active_yet'),
            ], 409);
        }

        // Critical: tag demo voices for paid history visibility immediately after upgrade.
        $this->demoHistoryImporter->syncStorageForPlan((int) $user->id, $activePlan, 200);

        // NOTE: The frontend fires a Google Ads conversion event (gtag)
        // when it receives { ok: true } from this endpoint. Do NOT return
        // ok:true unless the subscription is fully active.
        return response()->json([
            'ok' => true,
            'message' => __('ui.backend.billing_subscription_updated'),
            'plan' => [
                'slug' => (string) $activePlan->slug,
                'name' => (string) $activePlan->name,
                'voice_tier' => VoiceTier::label((string) $activePlan->voice_tier),
            ],
        ]);
    }

    public function updatePaymentMethod(Request $request)
    {
        if (! $this->isStripeReady()) {
            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.billing_not_configured'),
            ], 422);
        }

        $user = $request->user();
        $validated = $request->validate([
            'payment_method' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->ensureStripeCustomer($user);
            $this->assertCardPaymentMethod((string) $validated['payment_method']);
            $user->updateDefaultPaymentMethod((string) $validated['payment_method']);
            $this->billingSyncService->syncIfStale($user, true);
        } catch (\Throwable $exception) {
            Log::warning('Unable to update Stripe payment method.', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.billing_update_card_failed'),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => __('ui.backend.billing_payment_method_updated'),
        ]);
    }

    public function cancel(Request $request)
    {
        $user = $request->user();

        $subscription = $this->currentDefaultSubscription($user);
        if (! $subscription || ! $subscription->valid()) {
            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.billing_no_active_subscription'),
            ], 422);
        }

        try {
            $subscription->cancel();
            $this->billingSyncService->syncIfStale($user, true);
            $subscription->refresh();
            $this->stripeWebhookEmailService->sendCancellationScheduledForUser($user, $subscription);
        } catch (\Throwable $exception) {
            Log::warning('Unable to cancel Stripe subscription renewal.', [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.billing_cancel_failed'),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => __('ui.backend.billing_cancellation_scheduled'),
        ]);
    }

    public function resume(Request $request)
    {
        $user = $request->user();

        $subscription = $this->currentDefaultSubscription($user);
        if (! $subscription || ! $subscription->onGracePeriod()) {
            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.billing_no_cancellation_to_resume'),
            ], 422);
        }

        try {
            $subscription->resume();
            $this->billingSyncService->syncIfStale($user, true);
        } catch (\Throwable $exception) {
            Log::warning('Unable to resume Stripe subscription.', [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.billing_resume_failed'),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => __('ui.backend.billing_subscription_resumed'),
        ]);
    }

    public function sync(Request $request)
    {
        $user = $request->user();
        $this->billingSyncService->syncIfStale($user, true);
        $activePlan = $this->planAccessService->resolveActivePlanForUserId((int) $user->id);
        $this->demoHistoryImporter->syncStorageForPlan((int) $user->id, $activePlan, 200);

        return response()->json([
            'ok' => true,
            'plan' => [
                'slug' => (string) ($activePlan?->slug ?? 'free'),
                'name' => (string) ($activePlan?->name ?? 'FREE'),
                'voice_tier' => VoiceTier::label((string) ($activePlan?->voice_tier ?: 'standard')),
            ],
        ]);
    }

    public function viewInvoice(Request $request, string $invoiceId)
    {
        $invoice = $request->user()->findInvoice($invoiceId);
        abort_if(! $invoice, 404);

        $stripeInvoice = $invoice->asStripeInvoice();
        $hostedUrl = trim((string) ($stripeInvoice->hosted_invoice_url ?? ''));

        if ($hostedUrl !== '') {
            return redirect()->away($hostedUrl);
        }

        return $this->safeDownloadInvoice($request, $invoiceId);
    }

    public function downloadInvoice(Request $request, string $invoiceId)
    {
        return $this->safeDownloadInvoice($request, $invoiceId);
    }

    private function createNewSubscription(User $user, string $priceId, string $paymentMethod, ?string $couponId = null): void
    {
        $paymentMethod = trim($paymentMethod);
        $this->ensureStripeCustomer($user);

        if ($paymentMethod === '') {
            $defaultPaymentMethod = $user->defaultPaymentMethod();
            $paymentMethod = $defaultPaymentMethod?->id ?: '';
        }

        if ($paymentMethod === '') {
            throw ValidationException::withMessages([
                'payment_method' => __('ui.backend.billing_add_payment_method'),
            ]);
        }

        $builder = $user->newSubscription('default', $priceId);

        if ($couponId) {
            $builder->withCoupon($couponId);
        }

        $builder->create($paymentMethod);
    }

    private function setSubscriptionPaymentMethod(User $user, BillingSubscription $subscription, string $paymentMethod): void
    {
        $paymentMethod = trim($paymentMethod);
        if ($paymentMethod === '') {
            return;
        }

        $this->assertCardPaymentMethod($paymentMethod);
        $user->updateDefaultPaymentMethod($paymentMethod);
        $stripeSubscriptionId = trim((string) $subscription->stripe_id);
        if ($stripeSubscriptionId === '') {
            return;
        }

        Cashier::stripe()->subscriptions->update($stripeSubscriptionId, [
            'default_payment_method' => $paymentMethod,
        ]);
    }

    private function currentDefaultSubscription(User $user): ?BillingSubscription
    {
        return BillingSubscription::query()
            ->where('user_id', $user->id)
            ->where('type', 'default')
            ->latest('id')
            ->first();
    }

    private function retireStaleSubscription(BillingSubscription $subscription): void
    {
        $stripeId = trim((string) $subscription->stripe_id);
        $stripeStatus = strtolower((string) $subscription->stripe_status);

        if ($stripeId !== '' && ! in_array($stripeStatus, ['canceled', 'incomplete_expired'], true)) {
            try {
                Cashier::stripe()->subscriptions->cancel($stripeId, []);
            } catch (ApiErrorException $exception) {
                Log::warning('Unable to cancel stale Stripe subscription before recreate.', [
                    'subscription_id' => $subscription->id,
                    'stripe_subscription_id' => $stripeId,
                    'error' => $exception->getMessage(),
                ]);
            } catch (\Throwable $exception) {
                Log::warning('Unexpected error while retiring stale Stripe subscription.', [
                    'subscription_id' => $subscription->id,
                    'stripe_subscription_id' => $stripeId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $subscription->items()->delete();
        $subscription->delete();
    }

    private function ensureStripeCustomer(User $user): void
    {
        if ($user->hasStripeId()) {
            return;
        }

        $user->createAsStripeCustomer();
        $user->refresh();
    }

    private function assertCardPaymentMethod(string $paymentMethodId): void
    {
        $paymentMethodId = trim($paymentMethodId);
        if ($paymentMethodId === '') {
            return;
        }

        try {
            $paymentMethod = Cashier::stripe()->paymentMethods->retrieve($paymentMethodId, []);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'payment_method' => __('ui.backend.billing_invalid_payment_method'),
            ]);
        }

        $type = strtolower((string) ($paymentMethod->type ?? ''));
        if ($type !== 'card') {
            throw ValidationException::withMessages([
                'payment_method' => __('ui.backend.billing_only_cards_supported'),
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveCheckoutPlans(bool $stripeReady, array $countryContext): array
    {
        $locale = app()->getLocale();

        return Plan::query()
            ->where('is_active', true)
            ->where('price_cents', '>', 0)
            ->orderBy('price_cents')
            ->get()
            ->filter(function (Plan $plan) {
                // Only show plans that have at least one Stripe price configured
                $slug = (string) $plan->slug;
                $monthlyPriceId = $this->resolveStripePriceId($slug, 'monthly');
                $yearlyPriceId = $this->resolveStripePriceId($slug, 'yearly');
                return $monthlyPriceId !== '' || $yearlyPriceId !== '';
            })
            ->map(function (Plan $plan) use ($stripeReady, $countryContext, $locale) {
                $slug = (string) $plan->slug;
                $yearlyDiscountPercent = SiteSetting::yearlyDiscountPercent();

                $monthlyPriceId = $this->resolveStripePriceId($slug, 'monthly');
                $yearlyPriceId = $this->resolveStripePriceId($slug, 'yearly');

                $effectiveMonthlyFallback = max(0, (int) $plan->getEffectivePriceCents());
                $yearlyFallback = max(0, (int) $plan->getEffectiveYearlyPriceCents($yearlyDiscountPercent));

                $monthly = $this->resolvePriceMeta($monthlyPriceId, $stripeReady, $effectiveMonthlyFallback, 'monthly', $countryContext, $locale);
                $yearly = $this->resolvePriceMeta($yearlyPriceId, $stripeReady, $yearlyFallback, 'yearly', $countryContext, $locale);
                $monthly['method'] = 'stripe';
                $yearly['method'] = 'stripe';

                $paymentMethods = [
                    'stripe' => [
                        'key' => 'stripe',
                        'available' => true,
                        'label' => $this->localizedPricingService->paymentMethodMeta('stripe')['label'],
                        'description' => $this->localizedPricingService->paymentMethodMeta('stripe')['description'],
                        'monthly' => $monthly,
                        'yearly' => $yearly,
                    ],
                ];

                foreach ($this->localizedPricingService->enabledManualMethods((string) ($countryContext['country_code'] ?? '')) as $method) {
                    // Keep checkout amount identical across payment methods for the same plan/cycle.
                    $manualMonthlyAmount = max(0, (int) ($monthly['amount_cents'] ?? 0));
                    $manualYearlyAmount = max(0, (int) ($yearly['amount_cents'] ?? 0));
                    $manualCurrency = strtoupper((string) ($monthly['currency'] ?? 'USD'));

                    $manualMonthly = $this->localizedPricingService->buildQuote(
                        $manualMonthlyAmount,
                        $manualCurrency,
                        'monthly',
                        $method,
                        $locale,
                    );
                    $manualYearly = $this->localizedPricingService->buildQuote(
                        $manualYearlyAmount,
                        $manualCurrency,
                        'yearly',
                        $method,
                        $locale,
                    );
                    $methodMeta = $this->localizedPricingService->paymentMethodMeta($method);

                    $paymentMethods[$method] = [
                        'key' => $method,
                        'available' => $manualMonthlyAmount > 0 && $manualYearlyAmount > 0,
                        'label' => $methodMeta['label'],
                        'description' => $methodMeta['description'],
                        'destination' => $this->localizedPricingService->manualPaymentDestination($method, $locale),
                        'monthly' => $manualMonthly,
                        'yearly' => $manualYearly,
                    ];
                }

                $preferredMethod = $this->localizedPricingService->preferredManualMethodForPlan($plan, $countryContext) ?? 'stripe';
                if (($paymentMethods[$preferredMethod]['available'] ?? false) !== true) {
                    $preferredMethod = 'stripe';
                }

                $checkoutPlan = [
                    'slug' => $slug,
                    'name' => (string) $plan->name,
                    'voice_tier' => VoiceTier::label((string) $plan->voice_tier),
                    'monthly' => $monthly,
                    'yearly' => $yearly,
                    'payment_methods' => $paymentMethods,
                    'preferred_payment_method' => $preferredMethod,
                ];

                // Include discount info so the billing page can display it
                if ($plan->hasActiveDiscount()) {
                    $checkoutPlan['has_discount'] = true;
                    $checkoutPlan['discount_percentage'] = (int) $plan->discount_percentage;
                    $checkoutPlan['discount_ends_at'] = $plan->discount_ends_at?->toIso8601String();
                }

                return $checkoutPlan;
            })
            ->values()
            ->all();
    }

    /**
     * Display amount always comes from the local DB (admin-editable).
     * Stripe API is only used for interval/currency metadata.
     *
     * @return array<string, mixed>
     */
    private function resolvePriceMeta(
        string $priceId,
        bool $stripeReady,
        int $fallbackAmountCents,
        string $cycle,
        array $countryContext,
        string $locale,
    ): array
    {
        $chargeCurrency = 'USD';
        $interval = $cycle === 'yearly' ? 'year' : 'month';
        $intervalCount = 1;
        // Always use the DB USD amount as the canonical base.
        $baseUsdAmountCents = $fallbackAmountCents;

        if ($stripeReady && $priceId !== '') {
            $stripePrice = $this->fetchStripePrice($priceId);
            if ($stripePrice) {
                // Only take interval/currency metadata from Stripe, NOT the display amount.
                $chargeCurrency = (string) $stripePrice['currency'];
                $interval = (string) $stripePrice['interval'];
                $intervalCount = (int) $stripePrice['interval_count'];
            }
        }

        $displayQuote = $this->localizedPricingService->convertedQuoteFromUsd(
            $baseUsdAmountCents,
            $cycle,
            $countryContext,
            'stripe',
            $locale,
        );

        return [
            'price_id' => $priceId,
            'amount_cents' => (int) $displayQuote['amount_minor'],
            'amount_formatted' => (string) $displayQuote['amount_formatted'],
            'currency' => (string) $displayQuote['currency'],
            'interval' => $interval,
            'interval_count' => $intervalCount,
            'base_usd_cents' => $baseUsdAmountCents,
            'base_usd_formatted' => (string) $displayQuote['base_usd_formatted'],
            'is_converted' => (bool) $displayQuote['is_converted'],
            'charge_currency' => strtoupper($chargeCurrency),
        ];
    }

    private function resolveCheckoutCouponId(Plan $plan, string $billingCycle): ?string
    {
        if (! $plan->hasActiveDiscount()) {
            return null;
        }

        $baseCouponId = trim((string) $plan->stripe_coupon_id);
        $billingCycle = strtolower(trim($billingCycle));
        if ($billingCycle !== 'yearly') {
            return $baseCouponId !== '' ? $baseCouponId : null;
        }

        $promoPercent = max(0, min(99, (int) $plan->discount_percentage));
        $yearlyPercent = SiteSetting::yearlyDiscountPercent();
        if ($promoPercent <= 0 || $yearlyPercent <= 0) {
            return $baseCouponId !== '' ? $baseCouponId : null;
        }

        // Yearly Stripe price already includes yearly discount.
        // Convert promo% to an equivalent coupon so total discount is additive on original total.
        $denominator = max(1, 100 - $yearlyPercent);
        $adjustedCouponPercent = max(0.01, min(99.99, round(($promoPercent / $denominator) * 100, 2)));
        $couponId = 'lis_y_'.substr(sha1($plan->slug.'|'.$promoPercent.'|'.$yearlyPercent.'|'.$adjustedCouponPercent), 0, 22);

        try {
            $existing = Cashier::stripe()->coupons->retrieve($couponId, []);
            if ($existing && (bool) ($existing->valid ?? false)) {
                return $couponId;
            }
        } catch (\Throwable) {
            // Will create coupon below.
        }

        $couponData = [
            'id' => $couponId,
            'percent_off' => $adjustedCouponPercent,
            'duration' => 'once',
            'name' => $plan->name.' - Yearly additive promo',
            'metadata' => [
                'listen_plan_slug' => (string) $plan->slug,
                'listen_plan_id' => (string) $plan->id,
                'listen_cycle' => 'yearly',
                'listen_promo_percent' => (string) $promoPercent,
                'listen_yearly_percent' => (string) $yearlyPercent,
                'listen_pricing_mode' => 'additive',
            ],
        ];

        if ($plan->discount_ends_at) {
            $couponData['redeem_by'] = $plan->discount_ends_at->getTimestamp();
        }

        try {
            Cashier::stripe()->coupons->create($couponData);

            return $couponId;
        } catch (\Throwable $exception) {
            Log::warning('Unable to create yearly additive coupon, falling back to base coupon.', [
                'plan_slug' => $plan->slug,
                'billing_cycle' => $billingCycle,
                'adjusted_coupon_percent' => $adjustedCouponPercent,
                'error' => $exception->getMessage(),
            ]);

            return $baseCouponId !== '' ? $baseCouponId : null;
        }
    }

    /**
     * @return array{amount_cents:int,currency:string,interval:string,interval_count:int}|null
     */
    private function fetchStripePrice(string $priceId): ?array
    {
        static $cache = [];

        $priceId = trim($priceId);
        if ($priceId === '') {
            return null;
        }

        if (array_key_exists($priceId, $cache)) {
            return $cache[$priceId];
        }

        try {
            $price = Cashier::stripe()->prices->retrieve($priceId, []);
        } catch (\Throwable $exception) {
            Log::warning('Unable to load Stripe price for billing display.', [
                'stripe_price_id' => $priceId,
                'error' => $exception->getMessage(),
            ]);

            $cache[$priceId] = null;

            return null;
        }

        $currency = strtoupper((string) ($price->currency ?? 'USD'));

        $cache[$priceId] = [
            'amount_cents' => (int) ($price->unit_amount ?? 0),
            'currency' => $currency !== '' ? $currency : 'USD',
            'interval' => (string) ($price->recurring->interval ?? 'month'),
            'interval_count' => (int) ($price->recurring->interval_count ?? 1),
        ];

        return $cache[$priceId];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function loadInvoices(User $user, bool $stripeReady, array $countryContext = [], string $locale = 'en'): array
    {
        if (! $stripeReady || ! $user->hasStripeId()) {
            return [];
        }

        try {
            return collect($user->invoices())->map(function ($invoice) use ($countryContext, $locale) {
                $stripeInvoice = $invoice->asStripeInvoice();
                $currency = strtoupper((string) ($stripeInvoice->currency ?? 'USD'));
                $totalCents = (int) ($stripeInvoice->total ?? 0);
                $invoiceDate = $invoice->date();

                return [
                    'id' => (string) $invoice->id,
                    'date' => $invoiceDate ? $this->formatCarbonDate($invoiceDate) : '-',
                    'status' => strtoupper((string) ($stripeInvoice->status ?? 'paid')),
                    'total' => $this->formatLocalizedInvoiceAmount($totalCents, $currency, $countryContext, $locale),
                    'purpose' => $this->resolveInvoicePurposeLabel($stripeInvoice, $locale),
                ];
            })->values()->all();
        } catch (\Throwable $exception) {
            Log::warning('Unable to load Stripe invoices for manage subscription page.', [
                'user_id' => $user->id,
                'stripe_id' => $user->stripe_id,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function loadDefaultPaymentMethod(User $user, bool $stripeReady): mixed
    {
        if (! $stripeReady || ! $user->hasStripeId()) {
            return null;
        }

        try {
            return $user->defaultPaymentMethod();
        } catch (\Throwable $exception) {
            Log::warning('Unable to load default Stripe payment method.', [
                'user_id' => $user->id,
                'stripe_id' => $user->stripe_id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array{total:string,currency:string,date:?string}|null
     */
    private function resolveUpcomingCharge(?BillingSubscription $subscription, array $countryContext = [], string $locale = 'en'): ?array
    {
        if (! $subscription || ! $subscription->valid()) {
            return null;
        }

        try {
            $upcoming = $subscription->upcomingInvoice();
            if (! $upcoming) {
                return null;
            }

            $stripeInvoice = $upcoming->asStripeInvoice();
            $periodEndTs = (int) ($stripeInvoice->period_end ?? 0);
            $currency = strtoupper((string) ($stripeInvoice->currency ?? 'USD'));
            $totalCents = (int) ($stripeInvoice->total ?? 0);

            return [
                'total' => $this->formatLocalizedInvoiceAmount($totalCents, $currency, $countryContext, $locale),
                'currency' => $currency,
                'date' => $periodEndTs > 0 ? $this->formatCarbonDate(Carbon::createFromTimestamp($periodEndTs)) : null,
            ];
        } catch (\Throwable $exception) {
            Log::warning('Unable to load upcoming invoice for manage subscription page.', [
                'subscription_id' => $subscription->id,
                'stripe_subscription_id' => $subscription->stripe_id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function subscriptionStatusLabel(?BillingSubscription $subscription, bool $hasStripeCustomer = true): string
    {
        if (! $subscription) {
            return 'No subscription';
        }

        if ($subscription->ended()) {
            return 'No subscription';
        }

        if ($subscription->onGracePeriod()) {
            return 'Cancellation scheduled';
        }

        if (! $hasStripeCustomer && ! $subscription->valid()) {
            return 'No subscription';
        }

        return match (strtolower((string) $subscription->stripe_status)) {
            'active' => 'Active',
            'trialing' => 'Trialing',
            'past_due' => 'Past due',
            'incomplete' => 'Incomplete payment',
            'incomplete_expired' => 'Incomplete expired',
            'canceled' => 'Canceled',
            'unpaid' => 'Unpaid',
            default => ucfirst((string) $subscription->stripe_status),
        };
    }

    private function isStripeReady(): bool
    {
        return trim((string) config('services.stripe.key', '')) !== ''
            && trim((string) config('services.stripe.secret', '')) !== '';
    }

    private function safeDownloadInvoice(Request $request, string $invoiceId)
    {
        try {
            return $request->user()->downloadInvoice($invoiceId, [
                'vendor' => config('app.name', 'Listen'),
                'product' => 'Subscription Invoice',
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Unable to render PDF invoice locally, redirecting to Stripe hosted invoice when possible.', [
                'user_id' => $request->user()->id,
                'invoice_id' => $invoiceId,
                'error' => $exception->getMessage(),
            ]);

            $invoice = $request->user()->findInvoice($invoiceId);
            $hostedUrl = trim((string) ($invoice?->asStripeInvoice()->hosted_invoice_url ?? ''));

            if ($hostedUrl !== '') {
                return redirect()->away($hostedUrl);
            }

            abort(422, 'Unable to download invoice PDF right now.');
        }
    }

    private function formatDateLabel(?string $date): ?string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return null;
        }

        try {
            return $this->formatCarbonDate(Carbon::parse($date));
        } catch (\Throwable) {
            return $date;
        }
    }

    private function formatCarbonDate(\DateTimeInterface $date): string
    {
        return Carbon::parse($date)
            ->clone()
            ->locale((string) app()->getLocale())
            ->translatedFormat('j M Y');
    }

    private function formatInvoiceTotalLabel(int $amountCents, string $currency): string
    {
        $formatted = Cashier::formatAmount(abs($amountCents), strtolower(trim($currency) !== '' ? $currency : 'USD'));

        if ($amountCents < 0) {
            return __('ui.billing.credit_prefix').' '.$formatted;
        }

        return $formatted;
    }

    /**
     * Format an invoice/charge amount in the user's local currency when possible.
     *
     * If the Stripe charge is in USD and the user's local display currency differs
     * (e.g. EGP for Egypt), convert and format in the local currency so prices match
     * what was shown on the pricing page. For non-USD charges, fall back to Stripe's amount.
     */
    private function formatLocalizedInvoiceAmount(int $amountCents, string $stripeCurrency, array $countryContext, string $locale): string
    {
        $stripeCurrency = strtoupper(trim($stripeCurrency) !== '' ? $stripeCurrency : 'USD');

        // When the Stripe charge is in USD and there's a local display currency, convert
        if ($stripeCurrency === 'USD' && ! empty($countryContext)) {
            $quote = $this->localizedPricingService->convertedQuoteFromUsd(
                abs($amountCents),
                'monthly', // cycle doesn't affect conversion, just formatting metadata
                $countryContext,
                'stripe',
                $locale
            );

            $formatted = (string) ($quote['amount_formatted'] ?? '');
            if ($formatted !== '') {
                if ($amountCents < 0) {
                    return __('ui.billing.credit_prefix').' '.$formatted;
                }

                return $formatted;
            }
        }

        // Fallback to Stripe's native formatting
        return $this->formatInvoiceTotalLabel($amountCents, $stripeCurrency);
    }

    private function resolveInvoicePurposeLabel(mixed $stripeInvoice, string $locale = 'en'): string
    {
        if (! is_object($stripeInvoice)) {
            return __('ui.manage_subscription.subscription');
        }

        try {
            $lines = $stripeInvoice->lines->data ?? [];
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    // Strategy 1: resolve from Stripe metadata (new invoices)
                    $planSlug = $this->extractMetaValue($line, 'listen_plan_slug');

                    // Strategy 2: parse plan name from Stripe description (old invoices)
                    // e.g. "Starter Plan (at $9.00 / month) × 1"
                    if ($planSlug === '') {
                        $desc = trim((string) ($line->description ?? ''));
                        $planSlug = $this->guessSlugFromDescription($desc);
                    }

                    if ($planSlug !== '') {
                        $plan = Plan::where('slug', $planSlug)->first();
                        if ($plan) {
                            $localizedName = $plan->getLocalizedName($locale);
                            $interval = $this->extractMetaValue($line, 'listen_interval');
                            if ($interval === '') {
                                $interval = (string) ($line->price->recurring->interval ?? 'month');
                            }
                            $cycleLabel = $interval === 'year'
                                ? __('ui.pricing.yearly')
                                : __('ui.pricing.monthly');

                            return $localizedName . ' — ' . $cycleLabel;
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // Fall through to default
        }

        return __('ui.manage_subscription.subscription');
    }

    /**
     * Try to match a Stripe description like "Starter Plan (at $9.00 / month) × 1"
     * to a known plan slug from the database.
     */
    private function guessSlugFromDescription(string $description): string
    {
        if ($description === '') {
            return '';
        }

        // Cache plans for this request
        static $planNames = null;
        if ($planNames === null) {
            $planNames = Plan::pluck('slug', 'name')->toArray(); // ['Starter Plan' => 'starter', ...]
        }

        foreach ($planNames as $name => $slug) {
            if (stripos($description, (string) $name) !== false) {
                return (string) $slug;
            }
        }

        return '';
    }

    /**
     * Safely extract a metadata value from a Stripe line item, checking
     * the line metadata, then the price metadata, then the product metadata.
     */
    private function extractMetaValue(mixed $line, string $key): string
    {
        // Line-level metadata
        $val = $line->metadata->{$key} ?? $line->metadata[$key] ?? null;
        if ($val !== null && trim((string) $val) !== '') {
            return trim((string) $val);
        }

        // Price-level metadata
        try {
            $val = $line->price->metadata->{$key} ?? $line->price->metadata[$key] ?? null;
            if ($val !== null && trim((string) $val) !== '') {
                return trim((string) $val);
            }
        } catch (\Throwable) {
        }

        // Product-level metadata (often not expanded)
        try {
            $val = $line->price->product->metadata->{$key} ?? $line->price->product->metadata[$key] ?? null;
            if ($val !== null && trim((string) $val) !== '') {
                return trim((string) $val);
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function resolveConsistentActivePlan(?Plan $activePlan, ?BillingSubscription $subscription): ?Plan
    {
        if (! $subscription || ! $subscription->valid()) {
            return $activePlan;
        }

        $mappedPlan = $this->resolvePlanFromBillingSubscription($subscription);
        if (! $mappedPlan) {
            return $activePlan;
        }

        if (! $activePlan || (string) $activePlan->slug !== (string) $mappedPlan->slug) {
            return $mappedPlan;
        }

        return $activePlan;
    }

    private function resolvePlanFromBillingSubscription(BillingSubscription $subscription): ?Plan
    {
        $priceId = trim((string) $subscription->stripe_price);

        if ($priceId === '') {
            $firstItem = $subscription->items()->latest('id')->first();
            $priceId = trim((string) ($firstItem?->stripe_price ?? ''));
        }

        if ($priceId === '') {
            return null;
        }

        $slug = $this->mapPriceIdToPlanSlug($priceId);
        if ($slug === null) {
            return null;
        }

        return Plan::query()->where('slug', $slug)->first();
    }

    private function mapPriceIdToPlanSlug(string $priceId): ?string
    {
        $priceId = trim($priceId);
        if ($priceId === '') {
            return null;
        }

        // Check admin-set prices (SiteSetting) first, then .env config
        $plans = Plan::where('is_active', true)->where('price_cents', '>', 0)->pluck('slug');
        foreach ($plans as $slug) {
            foreach (['monthly', 'yearly'] as $cycle) {
                if ($priceId === $this->resolveStripePriceId((string) $slug, $cycle)) {
                    return (string) $slug;
                }
            }
        }

        return null;
    }

    /**
     * Resolve Stripe Price ID from Plan model first, then admin dashboard (SiteSetting), then .env config.
     */
    private function resolveStripePriceId(string $planSlug, string $cycle): string
    {
        // 1. Check Plan model (auto-synced from Stripe) - PRIMARY SOURCE
        $plan = Plan::where('slug', $planSlug)->first();
        if ($plan) {
            $priceId = $plan->getStripePriceId($cycle);
            if ($priceId && trim($priceId) !== '') {
                return trim($priceId);
            }
        }

        // 2. Check admin-editable setting (SiteSetting) - LEGACY FALLBACK
        $adminPrice = trim((string) SiteSetting::get("stripe_price_{$planSlug}_{$cycle}", ''));
        if ($adminPrice !== '') {
            return $adminPrice;
        }

        // 3. Fall back to .env / config - LEGACY FALLBACK
        return trim((string) config("services.stripe.prices.{$planSlug}.{$cycle}", ''));
    }

    private function clearApprovedManualOverride(User $user): void
    {
        Subscription::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('billing_payment_state', 'manual_override')
            ->update([
                'billing_payment_state' => 'ok',
            ]);
    }
}
