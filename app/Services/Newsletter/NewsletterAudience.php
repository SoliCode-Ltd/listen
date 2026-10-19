<?php

namespace App\Services\Newsletter;

use App\Models\Newsletter;
use App\Models\NewsletterDelivery;
use App\Models\NewsletterSuppression;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\RenewalGrace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class NewsletterAudience
{
    /**
     * @param  callable(array{email: string, name: string, user_id: int|null}): (void|bool)  $callback
     */
    public function each(Newsletter $newsletter, callable $callback): void
    {
        if ($newsletter->audience === Newsletter::AUDIENCE_CUSTOM) {
            foreach ($this->customRecipients($newsletter) as $recipient) {
                if ($callback($recipient) === false) {
                    return;
                }
            }

            return;
        }

        $this->userQuery($newsletter)
            ->select(['id', 'name', 'email'])
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($callback) {
                foreach ($users as $user) {
                    $email = strtolower(trim((string) $user->email));
                    if (! $this->isDeliverableEmail($email)) {
                        continue;
                    }

                    if ($callback([
                        'email' => $email,
                        'name' => (string) $user->name,
                        'user_id' => (int) $user->id,
                    ]) === false) {
                        return false;
                    }
                }
            });
    }

    /**
     * People who have not been successfully sent this newsletter and are not already queued.
     * Lowest account id first, so the next batch continues where the last one stopped.
     *
     * @return list<array{email: string, name: string, user_id: int|null}>
     */
    public function pending(Newsletter $newsletter, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $skip = $this->queuedAddresses($newsletter) + $this->sentThisRound($newsletter);
        $found = [];

        $this->each($newsletter, function (array $recipient) use (&$found, $limit, $skip): bool {
            if (isset($skip[$recipient['email']])) {
                return true;
            }

            $found[] = $recipient;

            return count($found) < $limit;
        });

        return $found;
    }

    public function waitingCount(Newsletter $newsletter): int
    {
        $skip = $this->queuedAddresses($newsletter) + $this->sentThisRound($newsletter);
        $count = 0;

        $this->each($newsletter, function (array $recipient) use (&$count, $skip): void {
            if (! isset($skip[$recipient['email']])) {
                $count++;
            }
        });

        return $count;
    }

    /**
     * A phone sign-in's internal address (PhoneAuthBridgeService), not a real mailbox.
     */
    public static function isPhonePlaceholder(string $email): bool
    {
        return str_ends_with(strtolower(trim($email)), '@users.listen.local');
    }

    /**
     * Addresses already in line for this newsletter: queued, or going out right now.
     *
     * @return array<string, true>
     */
    private function queuedAddresses(Newsletter $newsletter): array
    {
        if (! $newsletter->getKey()) {
            return [];
        }

        return NewsletterDelivery::query()
            ->where('newsletter_id', $newsletter->getKey())
            ->where('is_test', false)
            ->whereIn('status', [NewsletterDelivery::STATUS_QUEUED, NewsletterDelivery::STATUS_SENDING])
            ->pluck('email')
            ->map(fn ($email): string => strtolower((string) $email))
            ->flip()
            ->all();
    }

    /**
     * With "skip people who already received this newsletter" off, people who
     * had it before that was turned off can get it again, but only once: those
     * sent it since are skipped, so each batch moves on instead of mailing the
     * first people again. When that moment is not known, everyone sent counts.
     * (With it on, the audience itself already leaves out everyone sent.)
     *
     * @return array<string, true>
     */
    private function sentThisRound(Newsletter $newsletter): array
    {
        if ($newsletter->exclude_already_sent || ! $newsletter->getKey()) {
            return [];
        }

        $from = $newsletter->resendFrom();

        return NewsletterDelivery::query()
            ->where('newsletter_id', $newsletter->getKey())
            ->where('is_test', false)
            ->where('status', NewsletterDelivery::STATUS_SENT)
            ->when($from !== null, fn ($query) => $query->where('sent_at', '>=', $from))
            ->pluck('email')
            ->map(fn ($email): string => strtolower((string) $email))
            ->flip()
            ->all();
    }

    public function count(Newsletter $newsletter): int
    {
        $count = 0;
        $this->each($newsletter, function () use (&$count): void {
            $count++;
        });

        return $count;
    }

    /**
     * @return list<array{email: string, name: string, user_id: int|null}>
     */
    public function customRecipients(Newsletter $newsletter): array
    {
        $emails = $this->parseEmails((string) $newsletter->custom_emails);
        if ($emails === []) {
            return [];
        }

        $users = User::query()
            ->whereIn(DB::raw('lower(email)'), $emails)
            ->get(['id', 'name', 'email', 'is_blocked', 'is_admin'])
            ->keyBy(fn (User $user): string => strtolower((string) $user->email));

        $suppressed = [];
        if ($newsletter->exclude_unsubscribed) {
            $suppressed = NewsletterSuppression::query()
                ->whereIn('email', $emails)
                ->pluck('email')
                ->map(fn ($email): string => strtolower((string) $email))
                ->flip()
                ->all();
        }

        $sent = [];
        if ($newsletter->exclude_already_sent && $newsletter->getKey()) {
            $sent = NewsletterDelivery::query()
                ->where('newsletter_id', $newsletter->getKey())
                ->where('is_test', false)
                ->where('status', NewsletterDelivery::STATUS_SENT)
                ->whereIn('email', $emails)
                ->pluck('email')
                ->map(fn ($email): string => strtolower((string) $email))
                ->flip()
                ->all();
        }

        $recipients = [];
        foreach ($emails as $email) {
            if (! $this->isDeliverableEmail($email)) {
                continue;
            }
            if (isset($suppressed[$email]) || isset($sent[$email])) {
                continue;
            }

            /** @var User|null $user */
            $user = $users->get($email);
            if ($user && $newsletter->exclude_blocked && $user->is_blocked) {
                continue;
            }
            if ($user && $newsletter->exclude_admins && $user->is_admin) {
                continue;
            }

            $recipients[] = [
                'email' => $email,
                'name' => $user ? (string) $user->name : '',
                'user_id' => $user?->id,
            ];
        }

        return $recipients;
    }

    /**
     * @return list<string>
     */
    public function parseEmails(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', strtolower($raw)) ?: [];
        $emails = [];
        foreach ($parts as $part) {
            $email = trim($part);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[$email] = $email;
            }
        }

        return array_values($emails);
    }

    /**
     * @return Builder<User>
     */
    private function userQuery(Newsletter $newsletter): Builder
    {
        $query = User::query()
            ->whereNotNull('email')
            ->where('email', 'like', '%@%.%');

        if ($newsletter->exclude_blocked) {
            $query->where(function (Builder $blocked): void {
                $blocked->where('is_blocked', false)->orWhereNull('is_blocked');
            });
        }

        // Phone sign-ins' internal addresses are never emailed (they bounce).
        $query->whereRaw('lower(email) not like ?', ['%@users.listen.local']);

        if ($newsletter->exclude_admins) {
            $query->where(function (Builder $admins): void {
                $admins->where('is_admin', false)->orWhereNull('is_admin');
            });
        }

        if ($newsletter->exclude_unsubscribed) {
            $query->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('newsletter_suppressions')
                    ->whereRaw('lower(newsletter_suppressions.email) = lower(users.email)');
            });
        }

        if ($newsletter->exclude_already_sent && $newsletter->getKey()) {
            $newsletterId = $newsletter->getKey();
            $query->whereNotExists(function ($sub) use ($newsletterId): void {
                $sub->selectRaw('1')
                    ->from('newsletter_deliveries')
                    ->where('newsletter_deliveries.newsletter_id', $newsletterId)
                    ->where('newsletter_deliveries.is_test', false)
                    ->where('newsletter_deliveries.status', NewsletterDelivery::STATUS_SENT)
                    ->whereRaw('lower(newsletter_deliveries.email) = lower(users.email)');
            });
        }

        $audience = (string) ($newsletter->audience ?: Newsletter::AUDIENCE_FREE);

        if ($audience === Newsletter::AUDIENCE_PAID) {
            $query->whereIn('id', $this->paidUserIdsQuery());
        } elseif ($audience === Newsletter::AUDIENCE_PLAN) {
            $planId = (int) $newsletter->plan_id;
            if ($planId <= 0) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('id', $this->userIdsOnPlanQuery($planId));
            }
        } elseif ($audience === Newsletter::AUDIENCE_ALL) {
            // Every registered account that passed the filters above.
        } elseif ($audience === Newsletter::AUDIENCE_CUSTOM) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereNotIn('id', $this->paidUserIdsQuery());
        }

        return $query;
    }

    private function paidUserIdsQuery(): Builder
    {
        return Subscription::query()
            ->select('subscriptions.user_id')
            ->whereIn('subscriptions.id', $this->latestActiveSubscriptionIdsQuery())
            ->whereHas('plan', fn (Builder $plan) => $plan->where('price_cents', '>', 0));
    }

    private function userIdsOnPlanQuery(int $planId): Builder
    {
        return Subscription::query()
            ->select('subscriptions.user_id')
            ->whereIn('subscriptions.id', $this->latestActiveSubscriptionIdsQuery())
            ->where('subscriptions.plan_id', $planId);
    }

    private function latestActiveSubscriptionIdsQuery(): Builder
    {
        return Subscription::query()
            ->selectRaw('MAX(subscriptions.id)')
            ->whereIn('status', ['active', 'trialing'])
            ->where(function (Builder $period): void {
                $period->whereNull('current_period_end')
                    ->orWhere('current_period_end', '>=', now())
                    // A paid plan kept on through a failed renewal's grace days.
                    ->orWhere(fn (Builder $grace) => RenewalGrace::whereKept($grace, now()));
            })
            ->groupBy('user_id');
    }

    private function isDeliverableEmail(string $email): bool
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return ! self::isPhonePlaceholder($email);
    }
}
