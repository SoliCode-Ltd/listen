<?php

namespace App\Http\Controllers;

use App\Exceptions\SubscriptionStillLiveException;
use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\SavedPaymentMethodRecorder;
use App\Services\Billing\StripePricePlans;
use App\Services\Billing\TrialEligibilityService;
use App\Services\Billing\TrialOutcomeService;
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
        private readonly TrialEligibilityService $trialEligibility,
        private readonly SavedPaymentMethodRecorder $savedCards,
        private readonly TrialOutcomeService $trialOutcomes,
        private readonly StripePricePlans $stripePrices,
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
        $validPlanSlugs = Plan::query()->subscriptionPlans()
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
            'trialOffer' => $this->trialEligibility->offerFor($user),
        ]);
    }

    public function manage(Request $request)
    {
        $user = $request->user();
        $userId = (int) $user->id;
        $this->billingSyncService->syncIfStale($user, true);

        $stripeKey = (string) config('services.stripe.key', '');
        $stripeSecret = (string) config('services.stripe.secret', '');
        $stripeReady = trim($stripeKey) !== '' && trim($stripeSecret) !== '';
        $locale = (string) app()->getLocale();
        $countryContext = $this->localizedPricingService->resolveCountryContext($request);

        $subscription = $this->currentDefaultSubscription($user);
        $stripeValid = (bool) ($subscription?->valid() ?? false);
        $onGracePeriod = (bool) ($subscription?->onGracePeriod() ?? false);

        // Show the plan the member actually gets (the one every limit uses),
        // and log it if Stripe says otherwise so a broken sync gets noticed.
        $accessPlan = $this->planAccessService->resolveAccessPlanForUserId($userId);
        $stripePlan = $this->resolveConsistentActivePlan($accessPlan, $subscription);
        if ($stripePlan && $accessPlan && (string) $stripePlan->slug !== (string) $accessPlan->slug && ! $accessPlan->isPack()) {
            Log::warning('Manage subscription: Stripe plan and enforced plan differ.', [
                'user_id' => $userId,
                'stripe_plan' => $stripePlan->slug,
                'enforced_plan' => $accessPlan->slug,
            ]);
        }

        $manual = $stripeValid ? null : $this->manualSubscriptionState($user);
        $hasStripeCustomer = $user->hasStripeId();

        $periodEnd = null;
        $renews = $stripeValid && ! $onGracePeriod
            && in_array(strtolower((string) $subscription?->stripe_status), ['active', 'trialing'], true);
        if ($renews && $stripeReady && $hasStripeCustomer) {
            try {
                $periodEnd = $subscription->currentPeriodEnd();
            } catch (\Throwable $exception) {
                Log::warning('Unable to resolve Stripe current period end for manage subscription page.', [
                    'user_id' => $userId,
                    'subscription_id' => $subscription->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $billingProfileRemoved = ! $hasStripeCustomer
            && (bool) ($subscription?->id)
            && trim((string) ($subscription?->stripe_id ?? '')) !== '';

        $stripeInvoices = $this->loadInvoices($user, $stripeReady, $countryContext, $locale);
        $upcomingCharge = $stripeReady && $renews ? $this->resolveUpcomingCharge($subscription, $countryContext, $locale) : null;

        return view('manage-subscription', [
            'activePlan' => $accessPlan,
            'activePlanName' => $accessPlan
                ? ($accessPlan->getLocalizedName($locale) ?: $accessPlan->name)
                : __('ui.dashboard.free_plan'),
            'activeTierLabel' => VoiceTier::label($this->planAccessService->resolveTierForPlan($accessPlan)),
            'stripeReady' => $stripeReady,
            'stripeKey' => $stripeKey,
            'defaultPaymentMethod' => $this->loadDefaultPaymentMethod($user, $stripeReady, $stripeValid ? $subscription : null),
            'invoices' => array_merge($stripeInvoices['rows'], $this->manualPaymentReceipts($user, $locale)),
            'billing' => $this->billingSummary($user, $subscription, $stripeValid, $manual, $stripeInvoices['last_paid'], $upcomingCharge, $locale),
            'hasCancelableSubscription' => $stripeValid && ! $onGracePeriod,
            'isCancellationScheduled' => $onGracePeriod,
            'billingProfileRemoved' => $billingProfileRemoved,
            'subscriptionStatus' => $manual['status'] ?? $this->subscriptionStatusLabel($subscription, $hasStripeCustomer),
            'subscriptionEndsAt' => $onGracePeriod && $subscription?->ends_at ? $this->formatCarbonDate($subscription->ends_at) : null,
            'subscriptionRenewsAt' => $periodEnd ? $this->formatCarbonDate($periodEnd) : null,
            'upcomingCharge' => $upcomingCharge,
            'trialNotice' => $this->trialNotice($user, $locale),
            'manual' => $manual,
        ]);
    }

    /**
     * "20% off (−$1.80)" for an invoice with a discount, or null.
     */
    private function discountLabel(mixed $stripeInvoice, string $currency): ?string
    {
        $discount = 0;
        foreach ((array) ($stripeInvoice->total_discount_amounts ?? []) as $row) {
            $discount += (int) (is_object($row) ? ($row->amount ?? 0) : ($row['amount'] ?? 0));
        }
        $subtotal = (int) ($stripeInvoice->subtotal ?? 0);
        if ($discount <= 0 || $subtotal <= 0) {
            return null;
        }

        return __('ui.manage_subscription.discount_value', [
            'percent' => (int) round($discount / $subtotal * 100),
            'amount' => $this->savedAmount($this->formatInvoiceTotalLabel($discount, $currency)),
        ]);
    }

    /** "−119 EGP" kept left-to-right inside Arabic text. */
    private function savedAmount(string $formatted): string
    {
        return "\u{2066}−".$formatted."\u{2069}";
    }

    /**
     * What the member paid last, what they pay next and how often, for card and manual plans.
     *
     * @return array<string, mixed>
     */
    private function billingSummary(User $user, ?BillingSubscription $subscription, bool $stripeValid, ?array $manual, ?array $lastCard, ?array $upcoming, string $locale): array
    {
        $cycle = null;
        $since = null;
        $last = $lastCard;
        $next = null;

        if ($stripeValid && $subscription) {
            $cycle = $this->billingSubscriptionPlan($subscription)['cycle'] ?? null;
            $since = $subscription->created_at ? $this->formatCarbonDate($subscription->created_at) : null;
            if ($upcoming) {
                $next = ['amount' => $upcoming['charged'] ?? $upcoming['total'], 'date' => $upcoming['date'] ?? null, 'discount' => $upcoming['discount'] ?? null, 'manual' => false];
            }
        } elseif ($manual && ! empty($manual['plan_slug'])) {
            $payments = \App\Models\ManualPaymentRequest::query()
                ->where('user_id', $user->id)
                ->where('status', \App\Models\ManualPaymentRequest::STATUS_APPROVED)
                ->orderBy('id')
                ->get();
            $first = $payments->first();
            $latest = $payments->last();
            if ($latest) {
                $snapshot = is_array($latest->price_snapshot) ? $latest->price_snapshot : [];
                $currency = strtoupper((string) ($latest->currency ?: 'EGP'));
                $amount = (int) $latest->expected_amount_minor;
                $full = (int) ($snapshot['full_amount_minor'] ?? 0);
                $cycle = in_array((string) $latest->billing_cycle, ['monthly', 'yearly'], true) ? (string) $latest->billing_cycle : null;
                $last = [
                    'amount' => $this->formatMinor($amount, $currency),
                    'date' => $this->formatCarbonDate($latest->reviewed_at ?? $latest->created_at),
                    'method' => $this->localizedPricingService->paymentMethodMeta((string) $latest->payment_method)['label'],
                    'discount' => $full > $amount ? __('ui.manage_subscription.discount_value', [
                        'percent' => (int) ($snapshot['discount_percent'] ?? round((1 - $amount / $full) * 100)),
                        'amount' => $this->savedAmount($this->formatMinor($full - $amount, $currency)),
                    ]) : null,
                ];
                $since = $this->formatCarbonDate($first->reviewed_at ?? $first->created_at);

                // Not renewed automatically: what renewing costs today, and by when.
                $plan = Plan::query()->where('slug', $manual['plan_slug'])->first();
                $quote = $plan ? $this->localizedPricingService->egpPriceQuote($plan, $cycle ?? 'monthly', (string) $latest->payment_method, $locale) : null;
                if ($quote) {
                    $next = [
                        'amount' => $this->formatMinor((int) $quote['amount_minor'], (string) $quote['currency']),
                        'date' => $manual['ends_at'] ?? null,
                        'discount' => (int) ($quote['discount_percent'] ?? 0) > 0 ? __('ui.manage_subscription.discount_value', [
                            'percent' => (int) $quote['discount_percent'],
                            'amount' => $this->savedAmount($this->formatMinor((int) $quote['full_amount_minor'] - (int) $quote['amount_minor'], (string) $quote['currency'])),
                        ]) : null,
                        'manual' => true,
                    ];
                }
            }
        }

        return [
            'cycle' => $cycle ? __('ui.billing.'.$cycle) : null,
            'since' => $since,
            'last' => $last,
            'next' => $next,
        ];
    }

    private function formatMinor(int $amountMinor, string $currency): string
    {
        $currency = strtoupper($currency);

        return $currency === 'USD'
            ? $this->formatInvoiceTotalLabel($amountMinor, 'USD')
            : number_format($amountMinor / 100, $amountMinor % 100 === 0 ? 0 : 2).' '.$currency;
    }

    /**
     * A plan paid by InstaPay / Vodafone Cash: no Stripe subscription, an end
     * date set when the payment was approved, and no automatic renewal.
     *
     * @return array{status:?string, ends_at:?string, expired:bool, plan_slug:?string, pending_plan:?string, paid_manually:bool}|null
     */
    private function manualSubscriptionState(User $user): ?array
    {
        $local = Subscription::query()->where('user_id', $user->id)->latest('id')->with('plan')->first();
        $pending = \App\Models\ManualPaymentRequest::query()
            ->where('user_id', $user->id)
            ->where('status', \App\Models\ManualPaymentRequest::STATUS_PENDING)
            ->whereNotNull('submitted_at')
            ->latest('id')
            ->with('plan')
            ->first();
        $pendingPlan = $pending?->plan ? ($pending->plan->getLocalizedName((string) app()->getLocale()) ?: $pending->plan->name) : null;

        if (! $local || (int) ($local->plan?->price_cents ?? 0) <= 0) {
            return $pendingPlan ? ['status' => null, 'ends_at' => null, 'expired' => false, 'plan_slug' => null, 'pending_plan' => $pendingPlan, 'paid_manually' => false] : null;
        }

        // A paid plan without a card subscription: paid by InstaPay / Vodafone Cash, or set by an admin.
        $paidManually = (string) $local->billing_payment_state === 'manual_override';
        $end = $local->current_period_end;
        $expired = (string) $local->status !== 'active' || ($end !== null && $end->isPast());

        return [
            'status' => $expired ? 'Expired' : ($paidManually ? 'Active manual' : 'Active'),
            'ends_at' => $end ? $this->formatCarbonDate($end) : null,
            'expired' => $expired,
            'plan_slug' => (string) $local->plan?->slug,
            'pending_plan' => $pendingPlan,
            'paid_manually' => $paidManually,
        ];
    }

    /**
     * Approved InstaPay / Vodafone Cash payments, listed with the card invoices.
     *
     * @return array<int, array<string, string>>
     */
    private function manualPaymentReceipts(User $user, string $locale): array
    {
        return \App\Models\ManualPaymentRequest::query()
            ->where('user_id', $user->id)
            ->where('status', \App\Models\ManualPaymentRequest::STATUS_APPROVED)
            ->latest('id')
            ->limit(24)
            ->with('plan')
            ->get()
            ->map(function ($payment) use ($locale): array {
                $currency = strtoupper((string) ($payment->currency ?: 'EGP'));
                $amount = (int) $payment->expected_amount_minor;

                return [
                    'id' => '',
                    'date' => $this->formatCarbonDate($payment->reviewed_at ?? $payment->created_at),
                    'status' => 'PAID',
                    'total' => number_format($amount / 100, $amount % 100 === 0 ? 0 : 2).' '.$currency,
                    'purpose' => trim(($payment->plan ? ($payment->plan->getLocalizedName($locale) ?: $payment->plan->name).' · ' : '').__('ui.manage_subscription.manual_payment_label')),
                    'pay_url' => '',
                ];
            })
            ->all();
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
            'end_trial' => ['nullable', 'boolean'],
        ]);

        // Dynamically validate plan slug exists as an active paid plan
        $planRecord = Plan::query()->subscriptionPlans()
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
            if (! $subscription || ! $subscription->valid()) {
                // Our copy can be out of date: Stripe decides. A subscription it still
                // runs is used as it is, so nobody is ever charged for a second one.
                $subscription = $this->liveSubscriptionInStripe($user) ?? $subscription;
            }
            // The same plan and cycle, at today's price or an older one the member kept.
            $samePlan = $subscription && $this->isOnPlan($subscription, $priceId, (string) $planRecord->slug, (string) $validated['billing_cycle']);
            $charged = true;

            // Same plan while a cancellation is scheduled: undo the cancellation, charge nothing now.
            if ($subscription && $subscription->onGracePeriod() && $samePlan) {
                $subscription->resume();
                $charged = false;
            }

            if ($subscription && $subscription->valid() && $subscription->onTrial() && ($samePlan || ! $request->boolean('end_trial'))) {
                // Never end a free trial without the member saying so: the same plan
                // keeps the trial (nothing is charged), another plan asks first.
                if ($paymentMethod !== '' && $samePlan) {
                    $this->setSubscriptionPaymentMethod($user, $subscription, $paymentMethod);
                }
                $this->billingSyncService->syncIfStale($user, true);
                $trialEnds = $this->formatDateLabel($subscription->trial_ends_at?->toDateString());
                if ($samePlan) {
                    return response()->json([
                        'ok' => false,
                        'already_on_trial' => true,
                        'message' => __('ui.backend.billing_already_on_trial', ['plan' => $planRecord->name, 'date' => $trialEnds]),
                    ], 409);
                }

                return response()->json([
                    'ok' => false,
                    'requires_trial_end_confirmation' => true,
                    'message' => __('ui.backend.billing_trial_end_confirm', ['plan' => $planRecord->name, 'date' => $trialEnds]),
                ], 409);
            }

            if ($subscription && $subscription->valid() && $subscription->onTrial()) {
                // Confirmed "pay now" for another plan.
                if ($paymentMethod !== '') {
                    $this->setSubscriptionPaymentMethod($user, $subscription, $paymentMethod);
                }
                $notCharged = $this->endTrialAndCharge($user, $subscription, $priceId, $couponId);
                if ($notCharged !== null) {
                    return $notCharged;
                }
            } elseif ($subscription && $subscription->valid()) {
                if (! $samePlan) {
                    try {
                        $subscription->swap($priceId, $couponId ? ['discounts' => [['coupon' => $couponId]]] : []);
                        if ($paymentMethod !== '') {
                            $this->setSubscriptionPaymentMethod($user, $subscription, $paymentMethod);
                        }
                    } catch (SubscriptionUpdateFailure $exception) {
                        // Incomplete / stale local records must be retired before recreating.
                        $this->retireStaleSubscription($subscription);
                        $this->createNewSubscription($user, $priceId, $paymentMethod, $couponId);
                    }
                } else {
                    $charged = false;
                    if ($paymentMethod !== '') {
                        $this->setSubscriptionPaymentMethod($user, $subscription, $paymentMethod);
                    }
                }
            } else {
                if ($subscription) {
                    $this->retireStaleSubscription($subscription);
                }

                $this->createNewSubscription($user, $priceId, $paymentMethod, $couponId);
            }
        } catch (IncompletePayment $exception) {
            $this->billingSyncService->syncIfStale($user, true);
            if (! $exception->payment->requiresAction()) {
                // Declined: nothing to confirm in the browser, say so plainly.
                return response()->json([
                    'ok' => false,
                    'message' => __('ui.backend.billing_card_declined'),
                ], 422);
            }

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
        } catch (SubscriptionStillLiveException $exception) {
            Log::error('Checkout stopped: Stripe still charges this member for another subscription.', [
                'user_id' => $user->id,
                'plan' => $validated['plan'] ?? null,
                'stripe_subscriptions' => $exception->subscriptionIds,
            ]);
            $this->billingSyncService->syncIfStale($user, true);

            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.billing_already_subscribed'),
            ], 409);
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
        if ($saveCard && $paymentMethod !== '') {
            $this->savedCards->rememberQuietly($user, $paymentMethod, 'subscription');
        }
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
            'charged' => $charged,
            'message' => __('ui.backend.billing_subscription_updated'),
            'plan' => [
                'slug' => (string) $activePlan->slug,
                'name' => (string) $activePlan->name,
                'voice_tier' => VoiceTier::label((string) $activePlan->voice_tier),
            ],
        ]);
    }

    /**
     * Step 1 of replacing the card: a SetupIntent the page confirms with the
     * bank (3D Secure when asked). Nothing changes until the bank approves.
     */
    public function paymentMethodIntent(Request $request, \App\Services\Billing\StripeCardUpdateGateway $stripe)
    {
        if (! $this->isStripeReady()) {
            return response()->json(['ok' => false, 'message' => __('ui.backend.billing_not_configured')], 422);
        }

        $user = $request->user();
        try {
            $intent = $stripe->createSetupIntent($stripe->ensureCustomer($user), (int) $user->id);
        } catch (\Throwable $exception) {
            Log::warning('Unable to start a card update.', ['user_id' => $user->id, 'error' => $exception->getMessage()]);

            return response()->json(['ok' => false, 'message' => __('ui.backend.billing_update_card_failed')], 422);
        }

        return response()->json(['ok' => true, 'client_secret' => $intent['client_secret']]);
    }

    /**
     * Step 2: the bank approved the new card, so it now renews the subscription.
     * A card the bank refused never gets here, and the old card keeps renewing.
     * A member behind on payment is charged right away with the new card.
     */
    public function updatePaymentMethod(Request $request, \App\Services\Billing\StripeCardUpdateGateway $stripe)
    {
        if (! $this->isStripeReady()) {
            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.billing_not_configured'),
            ], 422);
        }

        $user = $request->user();
        $validated = $request->validate([
            'setup_intent' => ['required', 'string', 'max:255'],
        ]);

        try {
            $customerId = $stripe->ensureCustomer($user);
            $intent = $stripe->retrieveSetupIntent((string) $validated['setup_intent']);
        } catch (\Throwable $exception) {
            Log::warning('Unable to read the card update from Stripe.', ['user_id' => $user->id, 'error' => $exception->getMessage()]);

            return response()->json(['ok' => false, 'message' => __('ui.backend.billing_update_card_failed')], 422);
        }

        // Only this member's own, bank-approved card update.
        if ($intent['customer'] !== $customerId || $intent['purpose'] !== 'card_update' || $intent['payment_method'] === '') {
            return response()->json(['ok' => false, 'message' => __('ui.backend.billing_update_card_failed')], 422);
        }
        if ($intent['status'] !== 'succeeded') {
            return response()->json(['ok' => false, 'message' => __('ui.backend.billing_card_not_approved')], 422);
        }

        $paymentMethod = $intent['payment_method'];
        $subscription = $this->currentDefaultSubscription($user);
        $subscriptionId = $subscription && ! $subscription->ended() ? trim((string) $subscription->stripe_id) : '';

        try {
            $stripe->useForRenewals($customerId, $subscriptionId !== '' ? $subscriptionId : null, $paymentMethod);
        } catch (\Throwable $exception) {
            Log::warning('Unable to set the approved card for renewals.', ['user_id' => $user->id, 'error' => $exception->getMessage()]);

            return response()->json(['ok' => false, 'message' => __('ui.backend.billing_update_card_failed')], 422);
        }

        $this->savedCards->rememberQuietly($user, $paymentMethod, 'update');
        $message = __('ui.backend.billing_payment_method_updated');

        // Behind on payment: pay now with the new card instead of waiting for a retry.
        $behind = $subscriptionId !== '' && in_array(strtolower((string) $subscription->stripe_status), ['past_due', 'unpaid', 'incomplete'], true);
        if ($behind) {
            try {
                $result = $stripe->payOpenInvoices($customerId, $subscriptionId, $paymentMethod);
                if ($result['attempted'] > 0) {
                    $message = $result['paid'] === $result['attempted']
                        ? __('ui.backend.billing_card_updated_and_paid')
                        : __('ui.backend.billing_card_updated_payment_failed');
                }
            } catch (\Throwable $exception) {
                Log::warning('Unable to pay open invoices with the updated card.', ['user_id' => $user->id, 'error' => $exception->getMessage()]);
                $message = __('ui.backend.billing_card_updated_payment_failed');
            }
        }

        $this->billingSyncService->syncIfStale($user, true);

        return response()->json(['ok' => true, 'message' => $message]);
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

        // Never start a second subscription while Stripe still charges for one.
        $live = $this->liveStripeSubscriptions($user, ['active', 'trialing', 'past_due', 'unpaid']);
        if ($live !== []) {
            throw new SubscriptionStillLiveException(array_map(fn ($sub) => (string) $sub->id, $live));
        }

        $builder = $user->newSubscription('default', $priceId);

        if ($couponId) {
            $builder->withCoupon($couponId);
        }

        $builder->create($paymentMethod);
    }

    /**
     * Ends a trial and charges the new plan today. The change is applied only
     * if the payment succeeds; a declined card leaves the trial exactly as it was.
     * Returns null when paid, or the response to send when it was not.
     */
    private function endTrialAndCharge(User $user, BillingSubscription $subscription, string $priceId, ?string $couponId): ?\Illuminate\Http\JsonResponse
    {
        $stripeId = (string) $subscription->stripe_id;
        $stripe = Cashier::stripe();
        $current = $stripe->subscriptions->retrieve($stripeId, []);
        $item = $current->items->data[0] ?? null;
        $oldPrice = (string) ($item->price->id ?? $subscription->stripe_price ?? '');

        // Before the update: the invoice.paid webhook can land first and lock the trial record.
        $this->trialOutcomes->followPrice($stripeId, $priceId);

        $payload = [
            'items' => [array_filter(['id' => (string) ($item->id ?? ''), 'price' => $priceId])],
            'trial_end' => 'now',
            'proration_behavior' => 'always_invoice',
            'payment_behavior' => 'pending_if_incomplete',
            'cancel_at_period_end' => false,
            'expand' => ['latest_invoice.payments'],
        ];
        if ($couponId) {
            $payload['discounts'] = [['coupon' => $couponId]];
        }

        try {
            $updated = $stripe->subscriptions->update($stripeId, $payload);
        } catch (\Throwable $exception) {
            $this->trialOutcomes->followPrice($stripeId, $oldPrice);
            throw $exception;
        }

        if (empty($updated->pending_update)) {
            return null;
        }

        // Not paid yet: the trial and its plan stay as they were.
        $this->trialOutcomes->followPrice($stripeId, $oldPrice);
        $invoice = $updated->latest_invoice ?? null;
        $intent = $this->invoicePaymentIntent($invoice);

        if ($intent && in_array((string) $intent->status, ['requires_action', 'requires_confirmation'], true)) {
            $this->trialOutcomes->followPrice($stripeId, $priceId);

            return response()->json([
                'ok' => false,
                'requires_action' => true,
                'client_secret' => (string) $intent->client_secret,
                'message' => __('ui.backend.billing_auth_required'),
            ], 402);
        }

        // Declined: void the invoice so the pending change is dropped and nothing is owed.
        $invoiceId = is_object($invoice) ? (string) ($invoice->id ?? '') : (string) $invoice;
        if ($invoiceId !== '') {
            try {
                $stripe->invoices->voidInvoice($invoiceId, []);
            } catch (\Throwable $exception) {
                Log::warning('Could not void the invoice of a declined trial upgrade.', [
                    'user_id' => $user->id,
                    'invoice' => $invoiceId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
        $this->billingSyncService->syncIfStale($user, true);

        return response()->json([
            'ok' => false,
            'message' => __('ui.backend.billing_trial_upgrade_declined', [
                'date' => $this->formatDateLabel($subscription->trial_ends_at?->toDateString()),
            ]),
        ], 422);
    }

    private function invoicePaymentIntent(mixed $invoice): ?object
    {
        if (! is_object($invoice)) {
            return null;
        }

        $payments = $invoice->payments->data ?? [];
        $last = is_array($payments) && $payments !== [] ? end($payments) : null;
        $intentId = $last->payment->payment_intent ?? null;
        if (is_object($intentId)) {
            return $intentId;
        }
        if (! is_string($intentId) || $intentId === '') {
            return null;
        }

        try {
            return Cashier::stripe()->paymentIntents->retrieve($intentId, []);
        } catch (\Throwable) {
            return null;
        }
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

    /**
     * The member's subscription Stripe still runs, saved to our copy, when ours
     * has none. One that charges now wins over one that is behind on payment.
     */
    private function liveSubscriptionInStripe(User $user): ?BillingSubscription
    {
        $live = collect($this->liveStripeSubscriptions($user, ['active', 'trialing', 'past_due', 'unpaid']))
            ->sortBy(fn ($sub) => in_array((string) $sub->status, ['active', 'trialing'], true) ? 0 : 1)
            ->first();
        if (! $live) {
            return null;
        }

        $this->billingSyncService->persistBillingSubscription($user, $live);

        return BillingSubscription::query()->where('stripe_id', (string) $live->id)->first();
    }

    /**
     * The member's Stripe subscriptions in these statuses, newest first.
     *
     * @param  list<string>  $statuses
     * @return list<object>
     */
    private function liveStripeSubscriptions(User $user, array $statuses): array
    {
        $customerId = trim((string) $user->stripe_id);
        if ($customerId === '') {
            return [];
        }

        $list = Cashier::stripe()->subscriptions->all(['customer' => $customerId, 'status' => 'all', 'limit' => 20]);

        return collect(isset($list->data) ? $list->data : [])
            ->filter(fn ($sub) => in_array((string) ($sub->status ?? ''), $statuses, true))
            ->sortByDesc(fn ($sub) => (int) ($sub->created ?? 0))
            ->values()
            ->all();
    }

    /**
     * Whether the subscription already is this plan and cycle, at today's price
     * or at an older price the member kept.
     */
    private function isOnPlan(BillingSubscription $subscription, string $priceId, string $planSlug, string $cycle): bool
    {
        if ($subscription->hasPrice($priceId)) {
            return true;
        }

        $current = $this->billingSubscriptionPlan($subscription);

        return $current !== null && (string) $current['plan']->slug === $planSlug && $current['cycle'] === $cycle;
    }

    private function retireStaleSubscription(BillingSubscription $subscription): void
    {
        $stripeId = trim((string) $subscription->stripe_id);
        $stripeStatus = strtolower((string) $subscription->stripe_status);

        // Stripe decides, not our copy: a subscription we marked canceled can still be charging.
        if ($stripeId !== '') {
            try {
                $stripeStatus = strtolower((string) (Cashier::stripe()->subscriptions->retrieve($stripeId, [])->status ?? $stripeStatus));
            } catch (\Throwable) {
                // Stripe could not be read: keep our copy's status.
            }
        }

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

        if ($stripeId !== '') {
            $this->voidOpenInvoices($stripeId);
        }

        $subscription->items()->delete();
        $subscription->delete();
    }

    /**
     * Canceling only pauses collection on open invoices; void them so an old
     * invoice can never be paid on top of the new subscription.
     */
    private function voidOpenInvoices(string $stripeSubscriptionId): void
    {
        try {
            $open = Cashier::stripe()->invoices->all(['subscription' => $stripeSubscriptionId, 'status' => 'open', 'limit' => 20]);
            foreach ($open->data ?? [] as $invoice) {
                Cashier::stripe()->invoices->voidInvoice((string) $invoice->id, []);
            }
        } catch (\Throwable $exception) {
            Log::warning('Unable to void open invoices of a replaced subscription.', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'error' => $exception->getMessage(),
            ]);
        }
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

        return Plan::query()->subscriptionPlans()
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
     * The card invoices, and a summary of the last one paid.
     *
     * @return array{rows: array<int, array<string, string>>, last_paid: ?array<string, string>}
     */
    private function loadInvoices(User $user, bool $stripeReady, array $countryContext = [], string $locale = 'en'): array
    {
        if (! $stripeReady || ! $user->hasStripeId()) {
            return ['rows' => [], 'last_paid' => null];
        }

        try {
            $lastPaid = null;
            // Paid and unpaid ones: an open invoice is what a past-due member has to pay.
            $rows = collect($user->invoices(true))->map(function ($invoice) use ($countryContext, $locale, &$lastPaid) {
                $stripeInvoice = $invoice->asStripeInvoice();
                $status = strtolower((string) ($stripeInvoice->status ?? 'paid'));
                if ($status === 'draft') {
                    return null;
                }
                $currency = strtoupper((string) ($stripeInvoice->currency ?? 'USD'));
                $totalCents = (int) ($stripeInvoice->total ?? 0);
                $invoiceDate = $invoice->date();

                // Invoices come newest first: the first paid one is the last payment.
                if ($lastPaid === null && $status === 'paid' && (int) ($stripeInvoice->amount_paid ?? 0) > 0) {
                    $paidAt = (int) ($stripeInvoice->status_transitions->paid_at ?? 0);
                    $lastPaid = [
                        'amount' => $this->formatChargedAmount((int) $stripeInvoice->amount_paid, $currency, $countryContext, $locale),
                        'date' => $paidAt > 0 ? $this->formatCarbonDate(Carbon::createFromTimestamp($paidAt)) : ($invoiceDate ? $this->formatCarbonDate($invoiceDate) : ''),
                        'method' => __('ui.manage_subscription.paid_by_card'),
                        'discount' => $this->discountLabel($stripeInvoice, $currency),
                    ];
                }

                return [
                    'id' => (string) $invoice->id,
                    'date' => $invoiceDate ? $this->formatCarbonDate($invoiceDate) : '-',
                    'status' => strtoupper($status),
                    'total' => $this->formatChargedAmount($totalCents, $currency, $countryContext, $locale),
                    'purpose' => $this->resolveInvoicePurposeLabel($stripeInvoice, $locale),
                    'pay_url' => $status === 'open' ? (string) ($stripeInvoice->hosted_invoice_url ?? '') : '',
                ];
            })->filter()->values()->all();

            return ['rows' => $rows, 'last_paid' => $lastPaid];
        } catch (\Throwable $exception) {
            Log::warning('Unable to load Stripe invoices for manage subscription page.', [
                'user_id' => $user->id,
                'stripe_id' => $user->stripe_id,
                'error' => $exception->getMessage(),
            ]);

            return ['rows' => [], 'last_paid' => null];
        }
    }

    private function loadDefaultPaymentMethod(User $user, bool $stripeReady, ?BillingSubscription $subscription = null): mixed
    {
        if (! $stripeReady || ! $user->hasStripeId()) {
            return null;
        }

        try {
            // The card renewals use: the subscription's own card wins over the customer's.
            if ($subscription && trim((string) $subscription->stripe_id) !== '') {
                $stripeSubscription = Cashier::stripe()->subscriptions->retrieve((string) $subscription->stripe_id, ['expand' => ['default_payment_method']]);
                $own = $stripeSubscription->default_payment_method ?? null;
                if (is_object($own) && isset($own->card)) {
                    return $own;
                }
            }

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
                'charged' => $this->formatChargedAmount($totalCents, $currency, $countryContext, $locale),
                'discount' => $this->discountLabel($stripeInvoice, $currency),
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
                'product' => __('ui.manage_subscription.invoice_product'),
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

            abort(422, __('ui.manage_subscription.invoice_download_failed'));
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
            // UTC, the time Stripe bills in, so dates match the member's receipts and charges.
            ->setTimezone('UTC')
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

    /**
     * What the card was actually charged (Stripe charges USD), with today's
     * local-currency estimate beside it, so past amounts never change.
     */
    private function formatChargedAmount(int $amountCents, string $stripeCurrency, array $countryContext, string $locale): string
    {
        $charged = $this->formatInvoiceTotalLabel($amountCents, $stripeCurrency);
        $local = $this->formatLocalizedInvoiceAmount($amountCents, $stripeCurrency, $countryContext, $locale);

        return $local !== $charged ? $charged.' (≈ '.$local.')' : $charged;
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
        return $this->billingSubscriptionPlan($subscription)['plan'] ?? null;
    }

    /**
     * The plan and cycle of a member's Stripe subscription, also when it is on
     * a price the plan has since replaced.
     *
     * @return array{plan: Plan, cycle: string}|null
     */
    private function billingSubscriptionPlan(BillingSubscription $subscription): ?array
    {
        $priceId = trim((string) $subscription->stripe_price);

        if ($priceId === '') {
            $firstItem = $subscription->items()->latest('id')->first();
            $priceId = trim((string) ($firstItem?->stripe_price ?? ''));
        }

        return $priceId === '' ? null : $this->stripePrices->planFor($priceId, true);
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

    private function trialNotice(User $user, string $locale = 'en'): ?string
    {
        if (! $this->planAccessService->userHasUnpaidTrialWindow((int) $user->id)) {
            return null;
        }

        $trial = BillingTrial::query()
            ->where('user_id', $user->id)
            ->whereNull('paid_at')
            ->whereIn('status', [BillingTrial::STATUS_TRIALING, BillingTrial::STATUS_CANCELED])
            ->latest('id')
            ->first();
        // The limits that are really applied during the trial.
        $quota = $this->planAccessService->resolveQuotaPlanForUserId((int) $user->id);
        $plan = $trial?->plan ?: $this->trialEligibility->creatorPlan();
        $planName = $plan ? ($plan->getLocalizedName($locale) ?: (string) $plan->name) : '';

        if ($trial && $trial->status === BillingTrial::STATUS_CANCELED) {
            return __('ui.billing.trial_canceled_notice', [
                'plan' => $planName,
                'date' => $trial->trial_ends_at ? $this->formatCarbonDate($trial->trial_ends_at) : '—',
            ]);
        }

        $credits = \App\Support\PlanUsageGuard::planChars($quota);
        $minutes = \App\Support\Credits::minutesFor($credits);
        $minuteCap = (int) ($quota?->monthly_minutes ?? 0);
        if ((bool) ($quota?->getAttribute('enforce_trial_minute_cap') ?? false) && $minuteCap > 0) {
            $minutes = min($minutes, $minuteCap);
        }

        return __('ui.billing.trial_active_notice', [
            'plan' => $planName,
            'quota' => $quota ? ($quota->getLocalizedName($locale) ?: (string) $quota->name) : '',
            'credits' => number_format($credits),
            'per_request' => number_format(\App\Support\PlanUsageGuard::maxCharsPerRequest($quota)),
            'minutes' => number_format($minutes),
        ]);
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
