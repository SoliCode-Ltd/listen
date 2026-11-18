<?php

namespace App\Services\Billing;

use App\Http\Middleware\CaptureAdClickId;
use App\Models\ChatGptAdsEvent;
use App\Models\ManualPaymentRequest;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\LocalizedPricingService;
use App\Support\StripeInvoice;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sales sent to ChatGPT Ads from the server (OpenAI's Conversions API), as a
 * "subscription_created" event, next to the pixel in the member's browser:
 * - a card payment for a new plan or a plan change, once Stripe says its invoice is
 *   paid, also when the member's browser blocked the pixel. The browser's event has the
 *   same id (the page makes one per checkout), so OpenAI counts the sale once;
 * - a payment by hand (InstaPay / Vodafone Cash) once the team approves it; the page
 *   never reports those.
 * Only for members where the consent rules do not apply (CaptureAdClickId::mayTrack),
 * seen at checkout: what is sent is where they came from (the ad click, their browser,
 * the page), their e-mail address and account number as SHA-256 hashes, the plan and
 * what was paid. Nothing is kept or sent while it is off in SEO Settings.
 */
class ChatGptAdsConversions
{
    public const ENDPOINT = 'https://bzr.openai.com/v1/events';

    public const GROUP = 'ads';

    public const ENABLED = 'chatgpt_ads_capi_enabled';

    public const PIXEL_ID = 'chatgpt_ads_pixel_id';

    /** Kept encrypted. */
    public const API_KEY = 'chatgpt_ads_capi_key';

    /** OpenAI takes events from the last 7 days. */
    public const MAX_AGE_DAYS = 7;

    /** A card checkout's details are used for a payment within this long of it. */
    private const CHECKOUT_HOURS = 24;

    private const MAX_ATTEMPTS = 6;

    public function enabled(): bool
    {
        return (string) SiteSetting::get(self::ENABLED, '0') === '1' && $this->pixelId() !== '' && $this->apiKey() !== '';
    }

    public function pixelId(): string
    {
        $id = trim((string) SiteSetting::get(self::PIXEL_ID, ''));

        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1 ? $id : '';
    }

    public function apiKey(): string
    {
        $stored = trim((string) SiteSetting::get(self::API_KEY, ''));
        if ($stored === '') {
            return '';
        }

        try {
            return trim(Crypt::decryptString($stored));
        } catch (\Throwable) {
            return '';
        }
    }

    public function saveApiKey(string $key): void
    {
        SiteSetting::set(self::API_KEY, Crypt::encryptString(trim($key)), self::GROUP);
    }

    /** An id the browser makes for one checkout, given back with its pixel event. */
    public static function cleanEventId(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^[A-Za-z0-9_-]{8,64}$/', $value) === 1 ? $value : null;
    }

    /**
     * Where the member is buying from: kept with the checkout, for the sale to come.
     * Null when it may not be sent (the consent rules, or the country is not known).
     *
     * @return array<string, mixed>|null
     */
    public function context(?Request $request, array $extra = []): ?array
    {
        if (! $request || ! $this->enabled() || ! CaptureAdClickId::mayTrack($request)) {
            return null;
        }

        $referer = (string) $request->headers->get('referer', '');
        $sameSite = $referer !== '' && parse_url($referer, PHP_URL_HOST) === $request->getHost();

        return array_filter(array_merge([
            'oppref' => CaptureAdClickId::chatGptClick($request),
            'obref' => CaptureAdClickId::cleanOppref($request->cookie(CaptureAdClickId::PIXEL_BROWSER_COOKIE)),
            'ip' => app(LocalizedPricingService::class)->clientIp($request),
            'user_agent' => mb_substr(trim((string) $request->userAgent()), 0, 512),
            'source_url' => $sameSite ? strtok($referer, '#') : route('billing.page'),
            'captured_at' => now()->toIso8601String(),
        ], $extra), fn ($value) => $value !== null && $value !== '');
    }

    /** A card checkout: kept on the account for when Stripe says the invoice is paid. */
    public function rememberCheckout(User $user, Request $request, string $plan, string $cycle): void
    {
        $context = $this->context($request, [
            'plan_id' => $plan.'_'.$cycle,
            'event_id' => self::cleanEventId($request->input('ads_event_id')),
        ]);
        if ($context === null) {
            return;
        }

        $user->forceFill(['chatgpt_ads_context' => $context])->save();
    }

    public function handleStripeEvent(object $event): void
    {
        $type = (string) ($event->type ?? '');
        $invoice = $event->data->object ?? null;
        if (! in_array($type, ['invoice.paid', 'invoice.payment_succeeded'], true) || ! is_object($invoice)) {
            return;
        }

        $this->recordPaidInvoice($invoice);
    }

    public function recordPaidInvoice(object $invoice): ?ChatGptAdsEvent
    {
        $invoiceId = trim((string) ($invoice->id ?? ''));
        $amountPaid = (int) ($invoice->amount_paid ?? 0);
        // A new plan's first payment, or what a plan change charged; never a renewal.
        if (! $this->enabled() || $invoiceId === '' || $amountPaid <= 0
            || ! in_array(StripeInvoice::billingReason($invoice), ['subscription_create', 'subscription_update'], true)) {
            return null;
        }

        $customerId = trim((string) ($invoice->customer ?? ''));
        $user = $customerId !== '' ? User::query()->where('stripe_id', $customerId)->first() : null;
        $context = $user ? $this->recentCheckout($user) : null;
        if (! $user || $context === null) {
            return null;
        }

        $reference = 'invoice:'.$invoiceId;
        if (ChatGptAdsEvent::query()->where('reference', $reference)->exists()) {
            return null;
        }

        $paidAt = (int) ($invoice->status_transitions->paid_at ?? 0);
        $eventId = (string) ($context['event_id'] ?? '') ?: 'stripe_'.$invoiceId;
        $event = $this->keep($user, ChatGptAdsEvent::SOURCE_CARD, $reference, $eventId, $context, (string) ($context['plan_id'] ?? ''),
            $amountPaid, (string) ($invoice->currency ?? 'usd'), $paidAt > 0 ? Carbon::createFromTimestamp($paidAt) : now());

        if ($event) {
            // The checkout's id is used once: another payment later is a sale of its own.
            unset($context['event_id']);
            $user->forceFill(['chatgpt_ads_context' => $context])->save();
        }

        return $event;
    }

    public function recordManualPayment(ManualPaymentRequest $request): ?ChatGptAdsEvent
    {
        $context = is_array($request->chatgpt_ads_context) ? $request->chatgpt_ads_context : null;
        $plan = $request->plan;
        if (! $this->enabled() || $request->status !== ManualPaymentRequest::STATUS_APPROVED || ! $request->user
            || $context === null || ! $plan || $plan->isPack()) {
            return null;
        }

        return $this->keep($request->user, ChatGptAdsEvent::SOURCE_MANUAL, 'manual:'.$request->id, 'manual_'.$request->id, $context,
            $plan->slug.'_'.$request->billing_cycle, (int) $request->expected_amount_minor, (string) $request->currency,
            $request->reviewed_at ?? now());
    }

    /** Sends the sales still waiting (a try failed, or the page could not send it at once). */
    public function sendPending(int $limit = 50): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $sent = 0;
        ChatGptAdsEvent::query()
            ->where('status', ChatGptAdsEvent::STATUS_PENDING)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (ChatGptAdsEvent $event) use (&$sent): void {
                $sent += $this->send($event) ? 1 : 0;
            });

        return $sent;
    }

    public function send(ChatGptAdsEvent $event): bool
    {
        $event->refresh();
        if ($event->status !== ChatGptAdsEvent::STATUS_PENDING || ! $this->enabled()) {
            return false;
        }

        $at = (int) ($event->payload['timestamp_ms'] ?? 0);
        if ($at < now()->subDays(self::MAX_AGE_DAYS)->addMinutes(10)->getTimestampMs()) {
            $event->forceFill(['status' => ChatGptAdsEvent::STATUS_EXPIRED, 'error' => 'Older than ChatGPT Ads takes (7 days).'])->save();

            return false;
        }

        try {
            $response = $this->post(['integration_source' => 'listen', 'events' => [$event->payload]]);
        } catch (\Throwable $exception) {
            return $this->failedTry($event, null, $exception->getMessage());
        }

        if ($response->successful()) {
            $event->forceFill([
                'status' => ChatGptAdsEvent::STATUS_SENT, 'attempts' => $event->attempts + 1,
                'response_status' => $response->status(), 'error' => null, 'sent_at' => now(),
            ])->save();

            return true;
        }

        $body = mb_substr(trim($response->body()), 0, 1000);
        // Refused (a wrong key or a field it does not take): trying again would not help.
        if ($response->status() < 500 && $response->status() !== 429) {
            $event->forceFill([
                'status' => ChatGptAdsEvent::STATUS_FAILED, 'attempts' => $event->attempts + 1,
                'response_status' => $response->status(), 'error' => $body,
            ])->save();
            Log::warning('ChatGPT Ads refused a purchase event.', ['reference' => $event->reference, 'status' => $response->status(), 'body' => $body]);

            return false;
        }

        return $this->failedTry($event, $response->status(), $body);
    }

    /**
     * Checks the key and the event's shape with OpenAI, saving nothing ("validate_only").
     *
     * @return array{ok: bool, status: int|null, message: string}
     */
    public function check(): array
    {
        if ($this->pixelId() === '' || $this->apiKey() === '') {
            return ['ok' => false, 'status' => null, 'message' => 'Save the pixel ID and the Conversions API key first.'];
        }

        $sample = $this->payload('listen_key_check_'.now()->timestamp, [
            'source_url' => route('billing.page'), 'ip' => '8.8.8.8', 'user_agent' => 'Mozilla/5.0',
        ], 'key-check@listenai.studio', '0', 'creator_monthly', 1900, 'USD', now());

        try {
            $response = $this->post(['validate_only' => true, 'integration_source' => 'listen', 'events' => [$sample]]);
        } catch (\Throwable $exception) {
            return ['ok' => false, 'status' => null, 'message' => $exception->getMessage()];
        }

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'message' => mb_substr(trim($response->body()), 0, 500),
        ];
    }

    /** @return array<string, int> */
    public function counts(int $days = self::MAX_AGE_DAYS): array
    {
        $rows = ChatGptAdsEvent::query()->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all();

        return array_map('intval', $rows);
    }

    /** The most recent refusal or failed try, for the admin. */
    public function lastError(): ?ChatGptAdsEvent
    {
        return ChatGptAdsEvent::query()->whereNotNull('error')->latest('updated_at')->first();
    }

    private function recentCheckout(User $user): ?array
    {
        $context = is_array($user->chatgpt_ads_context) ? $user->chatgpt_ads_context : null;
        $at = isset($context['captured_at']) ? rescue(fn () => Carbon::parse($context['captured_at']), null, false) : null;

        return $at !== null && $at->greaterThanOrEqualTo(now()->subHours(self::CHECKOUT_HOURS)) ? $context : null;
    }

    /** @param array<string, mixed> $context */
    private function keep(User $user, string $source, string $reference, string $eventId, array $context, string $planId, int $amountMinor, string $currency, CarbonInterface $at): ?ChatGptAdsEvent
    {
        try {
            $event = ChatGptAdsEvent::query()->firstOrCreate(['reference' => $reference], [
                'user_id' => $user->id,
                'source' => $source,
                'event_id' => $eventId,
                'payload' => $this->payload($eventId, $context, (string) $user->email, (string) $user->id, $planId, $amountMinor, $currency, $at),
                'status' => ChatGptAdsEvent::STATUS_PENDING,
            ]);
        } catch (\Throwable $exception) {
            // A report: a payment never fails because of it.
            Log::warning('Could not keep a purchase for ChatGPT Ads.', ['reference' => $reference, 'error' => $exception->getMessage()]);

            return null;
        }

        if ($event->wasRecentlyCreated) {
            // At once, once the page or Stripe has its answer; listen:chatgpt-ads-send tries again.
            $id = (int) $event->id;
            dispatch(function () use ($id): void {
                $event = ChatGptAdsEvent::query()->find($id);
                if ($event) {
                    rescue(fn () => app(ChatGptAdsConversions::class)->send($event), null, false);
                }
            })->afterResponse();
        }

        return $event;
    }

    /**
     * The event as OpenAI takes it (developers.openai.com/ads/conversions-api).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function payload(string $eventId, array $context, string $email, string $userId, string $planId, int $amountMinor, string $currency, CarbonInterface $at): array
    {
        $email = mb_strtolower(trim($email));
        $oppref = CaptureAdClickId::cleanOppref($context['oppref'] ?? null);
        $capturedAt = isset($context['captured_at']) ? rescue(fn () => Carbon::parse($context['captured_at']), null, false) : null;
        // A click counts for 30 days.
        if ($oppref !== null && $capturedAt !== null && $capturedAt->lessThan(now()->subDays(30))) {
            $oppref = null;
        }

        $user = array_filter([
            'emails_sha256' => $email !== '' ? [hash('sha256', $email)] : null,
            'external_ids_sha256' => $userId !== '' ? [hash('sha256', $userId)] : null,
            'ip_address' => filter_var($context['ip'] ?? null, FILTER_VALIDATE_IP) ?: null,
            'user_agent' => ($context['user_agent'] ?? '') !== '' ? (string) $context['user_agent'] : null,
            'obref' => CaptureAdClickId::cleanOppref($context['obref'] ?? null),
        ]);

        $data = ['type' => 'plan_enrollment'];
        if ($planId !== '') {
            $data['plan_id'] = $planId;
        }
        if ($amountMinor > 0 && preg_match('/^[A-Za-z]{3}$/', $currency) === 1) {
            $data['amount'] = $amountMinor;
            $data['currency'] = strtoupper($currency);
        }

        return array_filter([
            'id' => $eventId,
            'type' => 'subscription_created',
            'timestamp_ms' => $at->getTimestampMs(),
            'action_source' => 'web',
            'source_url' => (string) ($context['source_url'] ?? route('billing.page')),
            'oppref' => $oppref,
            'user' => $user ?: null,
            'data' => $data,
        ], fn ($value) => $value !== null);
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): Response
    {
        return Http::withToken($this->apiKey())
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(10)
            ->post(self::ENDPOINT.'?pid='.rawurlencode($this->pixelId()), $body);
    }

    private function failedTry(ChatGptAdsEvent $event, ?int $status, string $error): bool
    {
        $attempts = $event->attempts + 1;
        $event->forceFill([
            'status' => $attempts >= self::MAX_ATTEMPTS ? ChatGptAdsEvent::STATUS_FAILED : ChatGptAdsEvent::STATUS_PENDING,
            'attempts' => $attempts,
            'response_status' => $status,
            'error' => mb_substr($error, 0, 1000),
        ])->save();

        return false;
    }
}
