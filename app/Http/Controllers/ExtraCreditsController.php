<?php

namespace App\Http\Controllers;

use App\Models\CharPackPurchase;
use App\Models\ManualPaymentRequest;
use App\Services\Billing\CharPackCardPayment;
use App\Services\Billing\ExtraCredits;
use App\Services\Billing\StripePackGateway;
use App\Services\LocalizedPricingService;
use App\Services\ManualPaymentService;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use App\Support\Credits;
use App\Support\UsageSnapshot;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Extra credits from the studio's dialog (App\Services\Billing\ExtraCredits):
 * what the member can buy and how, a card payment (the card they pay with, or
 * another one, with the bank's check when it asks), and a transfer by InstaPay
 * or Vodafone Cash for members who pay their plan by hand.
 */
class ExtraCreditsController extends Controller
{
    /** How long the saved card read from Stripe is kept for the dialog. */
    private const CARD_CACHE_SECONDS = 600;

    public function __construct(
        private readonly ExtraCredits $extraCredits,
        private readonly LocalizedPricingService $pricing,
        private readonly PlanAccessService $plans,
    ) {}

    /** What the dialog shows: the presets in the member's currency, the ways to pay, the balance. */
    public function offer(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = (int) $user->id;
        $status = $this->extraCredits->status($userId);
        $links = ['upgrade' => route('pricing'), 'billing' => route('billing.page')];

        if ($status !== ExtraCredits::STATUS_OK) {
            return $this->json(['ok' => true, 'status' => $status, 'links' => $links]);
        }

        $locale = app()->getLocale();
        $country = $this->pricing->resolveCountryContext($request);
        $byHand = $this->extraCredits->paysByHand($userId);
        // Members who pay their plan by hand can also send a transfer; everyone can pay by card.
        $manualMethods = $byHand ? $this->transferMethods($locale) : [];

        $presets = [];
        foreach (ExtraCredits::presets() as $minutes => $cents) {
            $local = $this->pricing->convertedQuoteFromUsd($cents, 'monthly', $country, 'stripe', $locale);
            $presets[] = [
                'minutes' => $minutes,
                'minutes_label' => trans_choice('ui.extra_credits.minutes', $minutes),
                'credits' => ExtraCredits::creditsFor($minutes),
                // What the card (or the phone wallet) is charged, in US cents.
                'amount_cents' => $cents,
                'credits_label' => __('ui.extra_credits.credits', ['count' => number_format(ExtraCredits::creditsFor($minutes))]),
                // Shown in the member's own currency, as the pricing page does; the card pays the dollars.
                'price' => $this->pricing->formatWholeAmount((int) $local['amount_minor'], (string) $local['currency'], $locale),
                'transfer_price' => $manualMethods !== []
                    ? $this->pricing->formatAmount($this->pricing->convertUsdMinorToCurrency($cents, LocalizedPricingService::CURRENCY_EGP), LocalizedPricingService::CURRENCY_EGP, $locale)
                    : null,
            ];
        }

        $manual = [];
        foreach ($manualMethods as $method) {
            $destination = $this->pricing->manualPaymentDestination($method, $locale);
            $meta = $this->pricing->paymentMethodMeta($method);
            $manual[] = [
                'method' => $method,
                'label' => (string) $meta['label'],
                'logo_url' => $meta['logo_url'] ?? null,
                'payment_link' => $destination['payment_link'] ?? null,
                'qr_image_url' => $destination['qr_image_url'] ?? null,
                // The team's own transfer instructions (Billing Settings), as on the plan's payment page.
                'instructions' => trim((string) ($destination['instructions'] ?? '')) ?: null,
            ];
        }

        $quota = $this->plans->resolveQuotaPlanForUserId($userId);
        $period = CreditPeriod::current($userId);
        $snapshot = UsageSnapshot::for($userId, $quota, CreditPeriod::counter($userId));
        $pending = ManualPaymentRequest::query()->extraCredits()
            ->where('user_id', $userId)
            ->where('status', ManualPaymentRequest::STATUS_PENDING)
            ->latest('id')
            ->first();

        return $this->json([
            'ok' => true,
            'status' => $status,
            'default_minutes' => array_key_exists(ExtraCredits::DEFAULT_MINUTES, ExtraCredits::presets())
                ? ExtraCredits::DEFAULT_MINUTES
                : (int) array_key_first(ExtraCredits::presets()),
            'presets' => $presets,
            'card_ready' => $this->stripeReady(),
            'stripe_key' => $this->stripeReady() ? (string) config('services.stripe.key') : null,
            'card' => $this->stripeReady() ? $this->savedCard($user) : null,
            'manual' => $manual,
            'balance' => [
                'remaining' => (int) $snapshot['remaining_chars'],
                'extra' => (int) ($snapshot['extra_chars'] ?? 0),
                'minutes_left' => Credits::minutesFor((int) $snapshot['remaining_chars']),
            ],
            // A plan cancelled to end with this period: the extra credits end with it.
            'plan_ends' => $this->extraCredits->planEnds($userId),
            'renews_on' => $this->date($period['ends_at'], $locale),
            'pending' => $pending ? [
                'minutes' => (int) (($pending->price_snapshot ?? [])['minutes'] ?? 0),
                'method' => (string) $pending->payment_method,
            ] : null,
            'links' => $links,
        ]);
    }

    public function payByCard(Request $request, CharPackCardPayment $card): JsonResponse
    {
        $validated = $request->validate([
            'minutes' => ['required', 'integer', Rule::in(array_keys(ExtraCredits::presets()))],
            // Another card from the card form or the phone wallet; without it, the card the member pays with.
            'payment_method' => ['nullable', 'string', 'starts_with:pm_', 'max:255'],
            'save_card' => ['nullable', 'boolean'],
            // The dialog's own mark for this try: sent again after a reply that never
            // arrived, it answers with what the first one did instead of charging twice.
            'attempt' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
        ]);

        $user = $request->user();
        if ($refusal = $this->refusal((int) $user->id)) {
            return $refusal;
        }
        if (! $this->stripeReady()) {
            return response()->json(['ok' => false, 'message' => __('ui.extra_credits.card_unavailable')], 422);
        }

        // One payment at a time for each member, so a second tap is never a second charge.
        $lock = Cache::lock('billing-extra-credits:'.$user->id, 60);
        if (! $lock->get()) {
            return response()->json(['ok' => false, 'message' => __('ui.backend.billing_payment_in_progress')], 409);
        }

        try {
            $attemptKey = filled($validated['attempt'] ?? null) ? 'extra-credits:attempt:'.$user->id.':'.$validated['attempt'] : null;
            $paymentMethod = $validated['payment_method'] ?? null;
            $saveCard = (bool) ($validated['save_card'] ?? false);
            $earlier = $attemptKey !== null ? Cache::get($attemptKey) : null;
            if ($earlier !== null) {
                $purchase = CharPackPurchase::query()->whereKey((int) $earlier)->where('user_id', $user->id)->first();
                if ($purchase) {
                    return $this->respond($card->resumeExtraCredits($user, $purchase, $paymentMethod, $saveCard), $request);
                }
            }

            $minutes = (int) $validated['minutes'];
            $result = $card->startExtraCredits(
                $user,
                $minutes,
                (int) ExtraCredits::priceCents($minutes),
                $paymentMethod,
                $saveCard,
                // Kept before the card is charged: a request cut off while Stripe is
                // answering is found again by its mark instead of being made twice.
                $attemptKey !== null
                    ? fn (CharPackPurchase $purchase) => Cache::put($attemptKey, (int) $purchase->id, now()->addDay())
                    : null,
            );
            // The saved card shown next time is read again: a card just kept, or one that failed.
            Cache::forget($this->cardCacheKey($user));

            return $this->respond($result, $request);
        } finally {
            $lock->release();
        }
    }

    public function confirmCard(Request $request, CharPackCardPayment $card): JsonResponse
    {
        $validated = $request->validate([
            'purchase' => ['required', 'integer'],
        ]);

        $purchase = CharPackPurchase::query()
            ->whereKey($validated['purchase'])
            ->where('user_id', $request->user()->id)
            ->where('kind', CharPackPurchase::KIND_EXTRA_CREDITS)
            ->where('method', CharPackPurchase::METHOD_CARD)
            ->firstOrFail();

        try {
            $result = $card->confirm($purchase);
        } catch (\Throwable $exception) {
            // Stripe could not be asked just now. The bank's check is done, so the payment is not
            // written off: the member is told to wait, and it is added by the webhook or the next look.
            Log::warning('An extra-credits payment could not be looked up after the bank\'s check.', ['user_id' => $request->user()->id, 'purchase_id' => $purchase->id, 'error' => $exception->getMessage()]);
            $result = ['status' => 'processing', 'purchase' => $purchase, 'message' => __('ui.extra_credits.card_processing')];
        }

        return $this->respond($result, $request);
    }

    /** A transfer by InstaPay / Vodafone Cash, with the picture of its receipt, for the team to check. */
    public function transfer(Request $request, ManualPaymentService $manualPayments): JsonResponse
    {
        $user = $request->user();
        $userId = (int) $user->id;
        $methods = $this->extraCredits->paysByHand($userId) ? $this->transferMethods(app()->getLocale()) : [];

        $validated = $request->validate([
            'minutes' => ['required', 'integer', Rule::in(array_keys(ExtraCredits::presets()))],
            'payment_method' => ['required', 'string', Rule::in($methods)],
            'proof_image' => ['required', 'image', 'max:5120'],
        ]);

        if ($refusal = $this->refusal($userId)) {
            return $refusal;
        }

        $plan = $this->plans->resolveActivePlanForUserId($userId);
        $minutes = (int) $validated['minutes'];
        $method = (string) $validated['payment_method'];
        $amount = $this->pricing->convertUsdMinorToCurrency((int) ExtraCredits::priceCents($minutes), LocalizedPricingService::CURRENCY_EGP);
        $quote = $this->pricing->buildQuote($amount, LocalizedPricingService::CURRENCY_EGP, 'monthly', $method, app()->getLocale()) + [
            'country_code' => 'EG',
            'base_usd_minor' => (int) ExtraCredits::priceCents($minutes),
        ];

        try {
            $manualPayments->createExtraCreditsRequest(
                $user,
                $plan,
                $minutes,
                $method,
                $quote,
                $this->pricing->manualPaymentDestination($method, app()->getLocale()),
                $request->file('proof_image'),
            );
        } catch (DomainException $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 409);
        }

        Log::info('Extra credits transfer sent for review.', ['user_id' => $userId, 'minutes' => $minutes, 'method' => $method]);

        return response()->json([
            'ok' => true,
            'pending' => true,
            'credits' => ExtraCredits::creditsFor($minutes),
            'message' => __('ui.extra_credits.transfer_sent', ['credits' => number_format(ExtraCredits::creditsFor($minutes))]),
        ]);
    }

    /**
     * The transfer methods that can be offered: switched on, and with somewhere to
     * send the money (a payment link or a code to scan, from Billing Settings). One
     * with neither would leave the member with an amount and nowhere to pay it.
     *
     * @return list<string>
     */
    private function transferMethods(string $locale): array
    {
        return array_values(array_filter(
            $this->pricing->enabledManualMethods('EG'),
            function (string $method) use ($locale): bool {
                $destination = $this->pricing->manualPaymentDestination($method, $locale);

                return trim((string) ($destination['payment_link'] ?? '')) !== ''
                    || trim((string) ($destination['qr_image_url'] ?? '')) !== '';
            },
        ));
    }

    /** A member who cannot buy now gets why, in the same words as the dialog. */
    private function refusal(int $userId): ?JsonResponse
    {
        return match ($this->extraCredits->status($userId)) {
            ExtraCredits::STATUS_OK => null,
            ExtraCredits::STATUS_RENEWAL_DUE => response()->json(['ok' => false, 'message' => __('ui.extra_credits.renewal_due')], 409),
            ExtraCredits::STATUS_NO_PLAN => response()->json(['ok' => false, 'message' => __('ui.extra_credits.paid_plans_only')], 409),
            default => response()->json(['ok' => false, 'message' => __('ui.extra_credits.not_available')], 409),
        };
    }

    /**
     * @param  array{status:string, purchase:CharPackPurchase, client_secret?:?string, message?:string, reason?:string}  $result
     */
    private function respond(array $result, Request $request): JsonResponse
    {
        $purchase = $result['purchase'];

        return match ($result['status']) {
            // The dialog counts a purchase only on ok:true.
            'paid' => response()->json([
                'ok' => true,
                'purchase' => $purchase->id,
                'credits' => (int) $purchase->chars,
                'message' => __('ui.extra_credits.added', ['credits' => number_format((int) $purchase->chars)]),
                'usage' => UsageSnapshot::for((int) $purchase->user_id, $this->plans->resolveQuotaPlanForUserId((int) $purchase->user_id), CreditPeriod::counter((int) $purchase->user_id)),
            ]),
            'requires_action' => response()->json([
                'ok' => false,
                'requires_action' => true,
                'client_secret' => $result['client_secret'] ?? null,
                'purchase' => $purchase->id,
            ], 402),
            'processing' => response()->json([
                'ok' => false,
                'processing' => true,
                'message' => $result['message'] ?? __('ui.extra_credits.card_processing'),
            ], 202),
            // Stripe's own reason (English) goes beside the message, for the dialog to keep aside.
            default => response()->json(array_merge([
                'ok' => false,
                'message' => $result['message'] ?? __('ui.extra_credits.card_failed'),
            ], ($result['reason'] ?? '') !== '' ? ['reason' => $result['reason']] : []), 422),
        };
    }

    /** @return array{brand:string, last4:string, expires:?string}|null */
    private function savedCard($user): ?array
    {
        if (! $user->stripe_id) {
            return null;
        }

        $card = Cache::remember($this->cardCacheKey($user), self::CARD_CACHE_SECONDS, function () use ($user) {
            try {
                return app(StripePackGateway::class)->savedCard((string) $user->stripe_id) ?? false;
            } catch (\Throwable $exception) {
                Log::warning('The saved card could not be read for the extra credits dialog.', ['user_id' => $user->id, 'error' => $exception->getMessage()]);

                return null;
            }
        });

        if (is_array($card)) {
            return [
                'brand' => (string) $card['brand'],
                'last4' => (string) $card['last4'],
                'expires' => $card['exp_month'] > 0 && $card['exp_year'] > 0
                    ? sprintf('%02d/%02d', $card['exp_month'], $card['exp_year'] % 100)
                    : null,
            ];
        }

        // Stripe could not be asked: the card Billing knows, without its date.
        return $card === null && $user->pm_last_four
            ? ['brand' => (string) ($user->pm_type ?: 'card'), 'last4' => (string) $user->pm_last_four, 'expires' => null]
            : null;
    }

    /**
     * Keyed with the card on the member's account, so a card changed in Billing is
     * read again at once: the card shown is the card that will be charged.
     */
    private function cardCacheKey($user): string
    {
        return 'extra-credits:card:'.(int) $user->id.':'.substr(md5($user->pm_type.'|'.$user->pm_last_four), 0, 10);
    }

    private function date(Carbon $date, string $locale): string
    {
        return $date->copy()->setTimezone('UTC')->locale($locale)->translatedFormat('j F');
    }

    private function stripeReady(): bool
    {
        return trim((string) config('services.stripe.key', '')) !== ''
            && trim((string) config('services.stripe.secret', '')) !== '';
    }

    /** @param  array<string, mixed>  $payload */
    private function json(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'no-store, private');
    }
}
