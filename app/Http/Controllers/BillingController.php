<?php

namespace App\Http\Controllers;

use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\User;
use App\Services\BillingSyncService;
use App\Services\PlanAccessService;
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

        return view('billing', [
            'activePlan' => $activePlan,
            'currentPlanSlug' => (string) ($activePlan?->slug ?? 'free'),
            'activeTierLabel' => VoiceTier::label((string) ($activePlan?->voice_tier ?: 'standard')),
            'plans' => $this->resolveCheckoutPlans($stripeReady),
            'selectedPlan' => in_array($selectedPlan, ['creator', 'pro'], true) ? $selectedPlan : '',
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

        return view('manage-subscription', [
            'activePlan' => $activePlan,
            'activeTierLabel' => VoiceTier::label((string) ($activePlan?->voice_tier ?: 'standard')),
            'stripeReady' => $stripeReady,
            'stripeKey' => $stripeKey,
            'defaultPaymentMethod' => $defaultPaymentMethod,
            'invoices' => $invoices,
            'hasActiveSubscription' => (bool) ($subscription?->valid() ?? false),
            'subscriptionStatus' => $this->subscriptionStatusLabel($subscription),
            'subscriptionEndsAt' => $this->formatDateLabel($subscription?->ends_at?->toDateString()),
            'subscriptionRenewsAt' => $this->formatDateLabel($periodEnd),
            'upcomingCharge' => $this->resolveUpcomingCharge($subscription),
        ]);
    }

    public function subscribe(Request $request)
    {
        if (! $this->isStripeReady()) {
            return response()->json([
                'ok' => false,
                'message' => 'Billing is not configured yet.',
            ], 422);
        }

        $user = $request->user();

        $validated = $request->validate([
            'plan' => ['required', 'in:creator,pro'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
            'payment_method' => ['nullable', 'string', 'max:255'],
        ]);

        $priceId = trim((string) config("services.stripe.prices.{$validated['plan']}.{$validated['billing_cycle']}", ''));
        if ($priceId === '') {
            return response()->json([
                'ok' => false,
                'message' => 'Billing price is not configured for this plan.',
            ], 422);
        }

        $paymentMethod = trim((string) ($validated['payment_method'] ?? ''));

        try {
            $this->ensureStripeCustomer($user);

            if ($paymentMethod !== '') {
                $this->assertCardPaymentMethod($paymentMethod);
                $user->updateDefaultPaymentMethod($paymentMethod);
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
                'message' => 'Additional payment authentication is required.',
            ], 402);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $firstMessage = is_array($errors['payment_method'] ?? null)
                ? (string) ($errors['payment_method'][0] ?? '')
                : '';

            return response()->json([
                'ok' => false,
                'message' => $firstMessage !== '' ? $firstMessage : 'Please add a payment method before subscribing.',
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
                'message' => 'Unable to update your subscription right now. Please try again in a moment.',
            ], 422);
        }

        $this->billingSyncService->syncIfStale($user, true);
        $activePlan = $this->planAccessService->resolveActivePlanForUserId((int) $user->id);

        if (! $activePlan || $activePlan->slug !== $validated['plan']) {
            return response()->json([
                'ok' => false,
                'message' => 'Payment was received but the plan is not active yet. Please refresh in a few seconds.',
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Subscription updated successfully.',
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
                'message' => 'Billing is not configured yet.',
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
                'message' => 'Unable to update card right now. Please try again in a moment.',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Payment method updated.',
        ]);
    }

    public function cancel(Request $request)
    {
        $user = $request->user();

        $subscription = $this->currentDefaultSubscription($user);
        if (! $subscription || ! $subscription->valid()) {
            return response()->json([
                'ok' => false,
                'message' => 'No active subscription found.',
            ], 422);
        }

        try {
            $subscription->cancel();
            $this->billingSyncService->syncIfStale($user, true);
        } catch (\Throwable $exception) {
            Log::warning('Unable to cancel Stripe subscription renewal.', [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Unable to cancel renewal right now. Please try again in a moment.',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Subscription will end at the close of your current billing period.',
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
                'payment_method' => 'Please add a payment method before subscribing.',
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
                'payment_method' => 'Invalid payment method. Please use a valid credit card.',
            ]);
        }

        $type = strtolower((string) ($paymentMethod->type ?? ''));
        if ($type !== 'card') {
            throw ValidationException::withMessages([
                'payment_method' => 'Only credit card payments are supported.',
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveCheckoutPlans(bool $stripeReady): array
    {
        return Plan::query()
            ->whereIn('slug', ['creator', 'pro'])
            ->where('is_active', true)
            ->orderBy('price_cents')
            ->get()
            ->map(function (Plan $plan) use ($stripeReady) {
                $slug = (string) $plan->slug;

                $monthlyPriceId = trim((string) config("services.stripe.prices.{$slug}.monthly", ''));
                $yearlyPriceId = trim((string) config("services.stripe.prices.{$slug}.yearly", ''));

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

                return [
                    'id' => (string) $invoice->id,
                    'date' => $invoice->date()?->format('M j, Y') ?: '-',
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
            $currency = strtoupper((string) ($stripeInvoice->currency ?? $upcoming->currency() ?? 'USD'));
            $totalCents = (int) ($stripeInvoice->total ?? 0);

            return [
                'total' => $this->formatInvoiceTotalLabel($totalCents, $currency),
                'currency' => $currency,
                'date' => $periodEndTs > 0 ? Carbon::createFromTimestamp($periodEndTs)->format('M j, Y') : null,
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

    private function subscriptionStatusLabel(?BillingSubscription $subscription): string
    {
        if (! $subscription) {
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
            return Carbon::parse($date)->format('M j, Y');
        } catch (\Throwable) {
            return $date;
        }
    }

    private function formatInvoiceTotalLabel(int $amountCents, string $currency): string
    {
        $formatted = Cashier::formatAmount(abs($amountCents), strtolower(trim($currency) !== '' ? $currency : 'USD'));

        if ($amountCents < 0) {
            return 'Credit '.$formatted;
        }

        return $formatted;
    }

    private function resolveInvoicePurposeLabel(mixed $stripeInvoice): string
    {
        if (! is_object($stripeInvoice)) {
            return 'Subscription';
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

        return 'Subscription';
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

        $prices = (array) config('services.stripe.prices', []);
        foreach ($prices as $slug => $cycles) {
            foreach ((array) $cycles as $configuredPriceId) {
                if ($priceId === trim((string) $configuredPriceId)) {
                    return (string) $slug;
                }
            }
        }

        return null;
    }
}
