<?php

namespace App\Http\Controllers;

use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\BillingSyncService;
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
            'plans' => $this->resolveCheckoutPlans($stripeReady),
            'selectedPlan' => in_array($selectedPlan, $validPlanSlugs, true) ? $selectedPlan : '',
            'stripeReady' => $stripeReady,
            'stripeKey' => $stripeKey,
            'defaultPaymentMethod' => $defaultPaymentMethod,
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
        $invoices = $this->loadInvoices($user, $stripeReady);

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
            'upcomingCharge' => $this->resolveUpcomingCharge($subscription),
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
                        if ($paymentMethod !== '') {
                            $this->setSubscriptionPaymentMethod($user, $subscription, $paymentMethod);
                        }
                    } catch (SubscriptionUpdateFailure $exception) {
                        // Incomplete / stale local records must be retired before recreating.
                        $this->retireStaleSubscription($subscription);
                        $this->createNewSubscription($user, $priceId, $paymentMethod);
                    }
                } elseif ($paymentMethod !== '') {
                    $this->setSubscriptionPaymentMethod($user, $subscription, $paymentMethod);
                }
            } else {
                if ($subscription) {
                    $this->retireStaleSubscription($subscription);
                }

                $this->createNewSubscription($user, $priceId, $paymentMethod);
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

        $this->billingSyncService->syncIfStale($user, true);
        $activePlan = $this->planAccessService->resolveActivePlanForUserId((int) $user->id);

        if (! $activePlan || $activePlan->slug !== $validated['plan']) {
            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.billing_plan_not_active_yet'),
            ], 409);
        }

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

    private function createNewSubscription(User $user, string $priceId, string $paymentMethod): void
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

        $user->newSubscription('default', $priceId)->create($paymentMethod);
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
    private function resolveCheckoutPlans(bool $stripeReady): array
    {
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
            ->map(function (Plan $plan) use ($stripeReady) {
                $slug = (string) $plan->slug;

                $monthlyPriceId = $this->resolveStripePriceId($slug, 'monthly');
                $yearlyPriceId = $this->resolveStripePriceId($slug, 'yearly');

                $monthlyFallback = max(0, (int) $plan->price_cents);
                $yearlyFallback = max(0, (int) round($monthlyFallback * 12 * 0.8));

                $monthly = $this->resolvePriceMeta($monthlyPriceId, $stripeReady, $monthlyFallback, 'month');
                $yearly = $this->resolvePriceMeta($yearlyPriceId, $stripeReady, $yearlyFallback, 'year');

                return [
                    'slug' => $slug,
                    'name' => (string) $plan->name,
                    'voice_tier' => VoiceTier::label((string) $plan->voice_tier),
                    'monthly' => $monthly,
                    'yearly' => $yearly,
                ];
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
    private function resolvePriceMeta(string $priceId, bool $stripeReady, int $fallbackAmountCents, string $fallbackInterval): array
    {
        $currency = 'USD';
        $interval = $fallbackInterval;
        $intervalCount = 1;
        // Always use the DB price — the admin dashboard is the source of truth.
        $amountCents = $fallbackAmountCents;

        if ($stripeReady && $priceId !== '') {
            $stripePrice = $this->fetchStripePrice($priceId);
            if ($stripePrice) {
                // Only take interval/currency from Stripe, NOT the amount.
                $currency = (string) $stripePrice['currency'];
                $interval = (string) $stripePrice['interval'];
                $intervalCount = (int) $stripePrice['interval_count'];
            }
        }

        return [
            'price_id' => $priceId,
            'amount_cents' => $amountCents,
            'amount_formatted' => Cashier::formatAmount($amountCents, strtolower($currency)),
            'currency' => $currency,
            'interval' => $interval,
            'interval_count' => $intervalCount,
        ];
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
    private function loadInvoices(User $user, bool $stripeReady): array
    {
        if (! $stripeReady || ! $user->hasStripeId()) {
            return [];
        }

        try {
            return collect($user->invoices())->map(function ($invoice) {
                $stripeInvoice = $invoice->asStripeInvoice();
                $currency = strtoupper((string) ($stripeInvoice->currency ?? 'USD'));
                $totalCents = (int) ($stripeInvoice->total ?? 0);
                $invoiceDate = $invoice->date();

                return [
                    'id' => (string) $invoice->id,
                    'date' => $invoiceDate ? $this->formatCarbonDate($invoiceDate) : '-',
                    'status' => strtoupper((string) ($stripeInvoice->status ?? 'paid')),
                    'total' => $this->formatInvoiceTotalLabel($totalCents, $currency),
                    'purpose' => $this->resolveInvoicePurposeLabel($stripeInvoice),
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
    private function resolveUpcomingCharge(?BillingSubscription $subscription): ?array
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
                'total' => $this->formatInvoiceTotalLabel($totalCents, $currency),
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

    private function resolveInvoicePurposeLabel(mixed $stripeInvoice): string
    {
        if (! is_object($stripeInvoice)) {
            return __('ui.manage_subscription.subscription');
        }

        $lines = $stripeInvoice->lines->data ?? [];
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $description = trim((string) ($line->description ?? ''));
                if ($description !== '') {
                    return $description;
                }
            }
        }

        $description = trim((string) ($stripeInvoice->description ?? ''));
        if ($description !== '') {
            return $description;
        }

        return __('ui.manage_subscription.subscription');
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
}
