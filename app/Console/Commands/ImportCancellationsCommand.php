<?php

namespace App\Console\Commands;

use App\Models\BillingSubscription;
use App\Models\SubscriptionCancellation;
use App\Models\User;
use App\Services\Billing\CancellationLog;
use App\Support\LiveServer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Cashier;

/**
 * Brings into Billing → Cancellations the cancellations made before the cancel dialog asked
 * why (and any the webhook missed): every subscription of a member that is set to end or has
 * ended, read from Stripe one by one (never the whole Stripe account, which other sites share).
 *
 * Only cancellations count: a subscription Stripe ended because a payment failed or was
 * disputed, one the site ended itself (unpaid past its grace days, an unfinished checkout
 * replaced, a free trial an admin or a blocked account ended), or one the member turned
 * back on, is left out. Already imported subscriptions are
 * skipped, so it can run any number of times. Reads Stripe only; writes this table only.
 *
 *   php artisan cancellations:import          a preview: what it would add, nothing written
 *   php artisan cancellations:import --write  adds them (the live site only)
 */
class ImportCancellationsCommand extends Command
{
    protected $signature = 'cancellations:import {--write : Add them (otherwise only shows what would be added)}';

    protected $description = 'Bring past cancellations from Stripe into Billing → Cancellations (read-only preview unless --write)';

    public function handle(CancellationLog $log): int
    {
        $write = (bool) $this->option('write');
        if ($write && ! LiveServer::allowsCustomerActions()) {
            $this->error('Writes only on the live site (its database is the one members use). Run without --write for a preview.');

            return self::FAILURE;
        }

        $candidates = BillingSubscription::query()
            ->whereNotNull('stripe_id')
            ->where(fn ($q) => $q->whereNotNull('ends_at')->orWhere('stripe_status', 'canceled'))
            ->whereNotIn('stripe_id', SubscriptionCancellation::query()->whereNotNull('stripe_subscription_id')->select('stripe_subscription_id'))
            ->orderBy('id')
            ->get();

        $counts = ['added' => 0, 'payment_ended' => 0, 'site_ended' => 0, 'kept' => 0, 'no_member' => 0, 'not_found' => 0, 'unknown' => 0];
        $this->line(($write ? 'Adding' : 'Preview (nothing written):').' '.$candidates->count().' subscriptions set to end or ended, not yet listed.');

        foreach ($candidates as $local) {
            $user = User::query()->find($local->user_id);
            if (! $user) {
                $counts['no_member']++;

                continue;
            }
            try {
                $stripe = Cashier::stripe()->subscriptions->retrieve((string) $local->stripe_id, ['expand' => ['latest_invoice']]);
            } catch (\Throwable $e) {
                $counts['not_found']++;
                $this->line('  not read from Stripe: subscription #'.$local->id.' ('.mb_substr($e->getMessage(), 0, 80).')');

                continue;
            }

            $reason = (string) ($stripe->cancellation_details->reason ?? '');
            if (in_array($reason, CancellationLog::NOT_CANCELLATIONS, true)) {
                $counts['payment_ended']++;

                continue;
            }
            $status = strtolower((string) ($stripe->status ?? ''));
            $scheduled = (bool) ($stripe->cancel_at_period_end ?? false) || ! empty($stripe->cancel_at);
            if ($status !== 'canceled' && ! $scheduled) {
                // Set to end once, then turned back on: still renewing.
                $counts['kept']++;

                continue;
            }
            if ($status === 'canceled' && $reason !== 'cancellation_requested' && ! $scheduled) {
                // Ended with no recorded request (older subscriptions): not counted as a cancellation.
                $counts['unknown']++;

                continue;
            }
            if ($status === 'canceled' && ! $scheduled && $log->siteEndedAtOnce((string) $stripe->id, $stripe->latest_invoice ?? null)) {
                // Ended at once by the site: a renewal left unpaid past its grace days, an
                // unfinished checkout replaced, or a free trial an admin or a blocked account ended.
                $counts['site_ended']++;

                continue;
            }

            $when = (int) ($stripe->canceled_at ?? 0) ?: (int) ($stripe->ended_at ?? 0);
            $this->line(sprintf('  %s member #%d, cancelled %s, %s',
                $write ? 'added' : 'would add',
                $user->id,
                $when > 0 ? Carbon::createFromTimestamp($when)->toDateString() : 'date unknown',
                $stripe->cancellation_details->feedback ?? 'no reason'
            ));
            if ($write) {
                $log->recordFromStripe($user, $stripe, SubscriptionCancellation::SOURCE_IMPORT);
            }
            $counts['added']++;
        }

        $this->newLine();
        $this->line(($write ? 'Added' : 'Would add').': '.$counts['added']);
        $this->line('Left out: '.$counts['payment_ended'].' ended by a failed or disputed payment, '.$counts['site_ended'].' ended by the site (unpaid, replaced, or a trial it ended), '.$counts['kept'].' turned back on, '
            .$counts['unknown'].' ended with no recorded request, '.$counts['no_member'].' with no member, '.$counts['not_found'].' not read from Stripe.');

        return self::SUCCESS;
    }
}
