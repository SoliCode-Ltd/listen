<?php

namespace App\Http\Controllers;

use App\Services\Billing\CharPackCardPayment;
use App\Services\Billing\TrialOutcomeService;
use App\Services\BillingLifecycleService;
use App\Services\StripeWebhookEmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        BillingLifecycleService $billingLifecycleService,
        StripeWebhookEmailService $stripeWebhookEmailService,
        TrialOutcomeService $trialOutcomeService,
    )
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');
        $secret = trim((string) config('services.stripe.webhook_secret', ''));

        if ($secret === '' && ! app()->environment(['local', 'testing'])) {
            Log::error('Stripe webhook secret is missing in a non-local environment.');

            return response('Webhook secret is not configured.', 503);
        }

        try {
            if ($secret !== '') {
                $event = Webhook::constructEvent($payload, $signature, $secret);
            } else {
                $event = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
            }
        } catch (SignatureVerificationException $exception) {
            Log::warning('Stripe webhook signature verification failed.', [
                'error' => $exception->getMessage(),
            ]);

            return response('Invalid signature.', 400);
        } catch (\Throwable $exception) {
            Log::warning('Stripe webhook payload parse failed.', [
                'error' => $exception->getMessage(),
            ]);

            return response('Invalid payload.', 400);
        }

        $type = (string) ($event->type ?? '');
        $eventId = trim((string) ($event->id ?? ''));
        $eventCacheKey = $eventId !== '' ? "stripe_webhook_event_processed:{$eventId}" : null;

        if ($eventCacheKey !== null) {
            $wasAdded = Cache::add($eventCacheKey, now()->timestamp, now()->addDays(14));
            if (! $wasAdded) {
                return response('Webhook already handled', 200);
            }
        }

        $customerId = trim((string) ($event->data->object->customer ?? ''));

        try {
            match ($type) {
                'checkout.session.completed' => $billingLifecycleService->syncUserByStripeCustomerId($customerId),
                'invoice.payment_failed' => $billingLifecycleService->handlePaymentFailed($customerId),
                'invoice.paid', 'invoice.payment_succeeded' => $billingLifecycleService->handlePaymentRecovered($customerId),
                'customer.subscription.created',
                'customer.subscription.updated',
                'customer.subscription.deleted',
                'customer.deleted' => $billingLifecycleService->syncUserByStripeCustomerId(
                    $type === 'customer.deleted'
                        ? trim((string) ($event->data->object->id ?? ''))
                        : $customerId
                ),
                // One-time character packs.
                'payment_intent.succeeded' => app(CharPackCardPayment::class)->handlePaymentSucceeded($event->data->object),
                'charge.refunded' => app(CharPackCardPayment::class)->handleChargeRefunded($event->data->object),
                default => null,
            };

            $trialOutcomeService->handleStripeEvent($event);
            $stripeWebhookEmailService->sendForEvent($event);
        } catch (\Throwable $exception) {
            if ($eventCacheKey !== null) {
                Cache::forget($eventCacheKey);
            }

            Log::warning('Stripe webhook business handling failed.', [
                'event_id' => $eventId,
                'type' => $type,
                'customer_id' => $customerId,
                'error' => $exception->getMessage(),
            ]);

            return response('Webhook processing failed.', 500);
        }

        return response('Webhook Handled', 200);
    }
}
