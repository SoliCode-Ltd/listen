<?php

namespace App\Services\Newsletter;

use App\Mail\CreditsEndedMail;
use App\Models\Newsletter;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Billing\ExtraCredits;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use App\Support\Credits;
use App\Support\LiveServer;
use App\Support\TitanMailbox;
use App\Support\UsageSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;

/**
 * The email a member on a paid plan gets when the period's credits run out,
 * wherever they ran out: the studio, the browser extension or the developer
 * API. It says when the plan's credits renew, and its button opens the studio
 * with the extra-credits dialog (App\Services\Billing\ExtraCredits).
 *
 * Once for each credit period (usage_counters.credits_ended_email_at), and again
 * only after extra credits were added and those ran out too. Only members who
 * can buy extra credits get it, so it is never sent while they are switched
 * off, to free members, during an unpaid renewal's grace, or to a member whose
 * plan is cancelled (nothing renews for them).
 *
 * In Arabic and English, in the newsletter's email design. Its words are edited
 * in Newsletter → Credits ended.
 */
class CreditsEndedEmail
{
    public const GROUP = 'credits_ended';

    /** After a send that failed, how long before it is tried again for that member. */
    private const RETRY_MINUTES = 60;

    /** The send to members who already ran out (Newsletter → Credits ended): who is waiting, and how it went. */
    public const BACKFILL_SETTING = 'credits_ended_backfill';

    private const BACKFILL_LOCK = 'credits-ended-backfill';

    /** Members looked at in one run, at most, so a run stays short even when many are left out. */
    private const BACKFILL_LOOKS_PER_RUN = 60;

    private const SENT = 'sent';

    private const SKIPPED = 'skipped';

    private const LATER = 'later';

    private const FAILED = 'failed';

    public function __construct(
        private readonly ExtraCredits $extraCredits,
        private readonly PlanAccessService $plans,
    ) {}

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => '1',
            // English first, then Arabic.
            'order' => 'en_ar',
            'subject_en' => 'You have used all your {plan} credits',
            'subject_ar' => 'انتهى رصيد خطة {plan}',
            'headline_en' => 'Your credits have run out',
            'headline_ar' => 'انتهى رصيدك',
            'body_en' => "Hi {name}, you have used all the credits on your {plan} plan. They renew on {date}.\n\nTo keep going now, add extra credits: choose how many minutes you need and pay once. What you don't use carries over each time your plan renews.",
            'body_ar' => "أهلاً {name}، استخدمت كل رصيد خطة {plan}. يتجدد رصيدك يوم {date}.\n\nلتكمل الآن، أضف رصيداً إضافياً: اختر عدد الدقائق التي تحتاجها وادفع مرة واحدة. ما لا تستخدمه ينتقل معك مع كل تجديد لخطتك.",
            'cta_label_en' => 'Add credits',
            'cta_label_ar' => 'أضف رصيداً',
            'footer_en' => 'Using the API? Requests are answered with 402 until credits are added or your plan renews. Questions? Just reply to this email.',
            'footer_ar' => 'تستخدم الـ API؟ تُرفض الطلبات بالرمز 402 حتى تضيف رصيداً أو تتجدد خطتك. لديك سؤال؟ ردّ على هذه الرسالة.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function settings(): array
    {
        $settings = [];
        foreach (self::defaults() as $key => $default) {
            $value = SiteSetting::get(self::GROUP.'_'.$key);
            $settings[$key] = is_string($value) && trim($value) !== '' ? $value : $default;
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data): void
    {
        foreach (array_keys(self::defaults()) as $key) {
            if (array_key_exists($key, $data)) {
                $value = is_bool($data[$key]) ? ($data[$key] ? '1' : '0') : trim((string) $data[$key]);
                SiteSetting::set(self::GROUP.'_'.$key, $value, self::GROUP);
            }
        }
    }

    public function enabled(): bool
    {
        return $this->settings()['enabled'] === '1';
    }

    /**
     * A member's credits just ran out (less than a minute of voice is left).
     * Sends the email when it is due; never throws, so the request that ran
     * out is answered as it would be without it. Returns whether it was sent.
     */
    public function memberRanOut(int $userId): bool
    {
        try {
            return $this->deliver($userId) === self::SENT;
        } catch (\Throwable $e) {
            Log::warning('The credits-ended email check failed.', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Whether the email would go to this member now: nothing is claimed or sent.
     * Only a member with a usage row for this period can have run out, so no row
     * is made for anyone while counting.
     */
    public function isDue(int $userId): bool
    {
        if (! $this->canSendAtAll() || Cache::has($this->retryKey($userId))) {
            return false;
        }

        $period = CreditPeriod::current($userId);
        $unsent = UsageCounter::query()
            ->where('user_id', $userId)
            ->where('period_ym', $period['key'])
            ->whereNull('credits_ended_email_at')
            ->exists();

        return $unsent && $this->dueMember($userId) !== null;
    }

    /**
     * Sends it when it is due: 'sent', 'skipped' (not due, or already sent this
     * period), 'later' (a send failed less than an hour ago) or 'failed'.
     */
    private function deliver(int $userId): string
    {
        if (! $this->canSendAtAll()) {
            return self::SKIPPED;
        }
        if (Cache::has($this->retryKey($userId))) {
            return self::LATER;
        }

        $user = $this->dueMember($userId);
        if (! $user) {
            return self::SKIPPED;
        }

        $period = CreditPeriod::current($userId);
        // Claimed first, so two requests refused at the same moment send one email.
        $claimed = UsageCounter::query()
            ->where('user_id', $userId)
            ->where('period_ym', $period['key'])
            ->whereNull('credits_ended_email_at')
            ->update(['credits_ended_email_at' => now()]);
        if ($claimed !== 1) {
            return self::SKIPPED;
        }

        try {
            $this->send($user, $this->plans->resolveActivePlanForUserId($userId), $period['ends_at']);
        } catch (\Throwable $e) {
            // Not sent: free to be tried again, a little later.
            UsageCounter::query()->where('user_id', $userId)->where('period_ym', $period['key'])->update(['credits_ended_email_at' => null]);
            Cache::put($this->retryKey($userId), true, now()->addMinutes(self::RETRY_MINUTES));
            Log::warning('The credits-ended email could not be sent.', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return self::FAILED;
        }

        Log::info('Credits-ended email sent.', ['user_id' => $userId, 'period' => $period['key']]);

        return self::SENT;
    }

    /** The switches that hold for every member: the email on, extra credits on sale, the live site. */
    private function canSendAtAll(): bool
    {
        // Staging runs on a copy of live members: they never get emails from it.
        return ExtraCredits::ready() && $this->enabled() && ExtraCredits::enabled() && LiveServer::allowsCustomerActions();
    }

    /** The member, when they can buy extra credits and have less than a minute of voice left. */
    private function dueMember(int $userId): ?User
    {
        if ($this->extraCredits->status($userId) !== ExtraCredits::STATUS_OK) {
            return null;
        }

        $user = User::query()->find($userId);
        if (! $user || ! filter_var((string) $user->email, FILTER_VALIDATE_EMAIL) || PaidWelcomeEmail::isPhoneSignupAddress((string) $user->email)) {
            return null;
        }

        // A plan cancelled to end with its period has no renewal to speak of: its member is not written to.
        if ($this->extraCredits->planEnds($userId)) {
            return null;
        }

        // Looked at again now: credits handed back since (a voice that failed) mean they did not run out.
        $left = (int) UsageSnapshot::for($userId, $this->plans->resolveQuotaPlanForUserId($userId), CreditPeriod::counter($userId))['remaining_chars'];

        return $left < Credits::PER_MINUTE ? $user : null;
    }

    // ---------- members who ran out before this email existed

    /**
     * Members on a paid plan whose credits have run out and who have not had
     * this email for the period: those who ran out before it existed, or whose
     * last request was not refused. Lowest member id first.
     *
     * @return list<int>
     */
    public function alreadyOut(): array
    {
        if (! $this->canSendAtAll()) {
            return [];
        }

        $ids = Subscription::query()
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->whereHas('plan', fn ($plan) => $plan->where('price_cents', '>', 0))
            ->distinct()
            ->orderBy('user_id')
            ->pluck('user_id');

        return $ids->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $this->isDue($id))->values()->all();
    }

    public const MAIL_SENT = 'sent';

    public const MAIL_WAITING = 'waiting';

    public const MAIL_NOT_SENT = 'not_sent';

    public const MAIL_HELD = 'held';

    /**
     * Every member on a paid plan who is out of credits now (less than a minute of
     * voice left in their current credit period), with whether this email went to
     * them for the period: sent (and when), waiting in the send to members who
     * already ran out, not sent yet, or held back and why. For the admin's list
     * (Newsletter → Credits ended); nothing is claimed, sent or written.
     *
     * @return list<array{user_id:int, email:string, name:string, plan:string, renews_at:?string, state:string, sent_at:?string, why:string}>
     */
    public function ranOutMembers(): array
    {
        if (! ExtraCredits::ready()) {
            return [];
        }

        $ids = Subscription::query()
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->whereHas('plan', fn ($plan) => $plan->where('price_cents', '>', 0))
            ->distinct()
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id);

        $run = $this->backfill();
        $waiting = array_flip($run['ids'] ?? []);
        $failures = $run['failures'] ?? [];
        // What holds the email back for everyone, before any member is looked at.
        $switchedOff = match (true) {
            ! $this->enabled() => 'the email is switched off',
            ! ExtraCredits::enabled() => 'extra credits are switched off',
            ! LiveServer::allowsCustomerActions() => 'this server does not email members',
            default => null,
        };

        $rows = [];
        foreach ($ids as $userId) {
            $period = CreditPeriod::current($userId);
            // Only a member with a usage row for the period can have run out (no row is made here).
            $counter = UsageCounter::query()->where('user_id', $userId)->where('period_ym', $period['key'])->first();
            if (! $counter) {
                continue;
            }
            $left = (int) UsageSnapshot::for($userId, $this->plans->resolveQuotaPlanForUserId($userId), $counter)['remaining_chars'];
            if ($left >= Credits::PER_MINUTE) {
                continue;
            }
            $user = User::query()->find($userId, ['id', 'name', 'email']);
            if (! $user) {
                continue;
            }

            $sentAt = $counter->credits_ended_email_at;
            [$state, $why] = match (true) {
                $sentAt !== null => [self::MAIL_SENT, ''],
                isset($waiting[$userId]) => [self::MAIL_WAITING, ''],
                $switchedOff !== null => [self::MAIL_HELD, $switchedOff],
                default => $this->heldBack($user, (int) ($failures[(string) $userId] ?? 0)),
            };

            $rows[] = [
                'user_id' => $userId,
                'email' => (string) $user->email,
                'name' => (string) $user->name,
                'plan' => (string) ($this->plans->resolveActivePlanForUserId($userId)?->name ?? ''),
                'renews_at' => $period['ends_at'] instanceof \DateTimeInterface ? $period['ends_at']->format('Y-m-d') : null,
                'state' => $state,
                'sent_at' => $sentAt?->toIso8601String(),
                'why' => $why,
            ];
        }

        // What still needs the owner first, then those in line, then those who have it (latest first).
        $order = [self::MAIL_NOT_SENT => 0, self::MAIL_HELD => 1, self::MAIL_WAITING => 2, self::MAIL_SENT => 3];
        usort($rows, fn (array $a, array $b): int => [$order[$a['state']], (string) $b['sent_at'], $a['user_id']] <=> [$order[$b['state']], (string) $a['sent_at'], $b['user_id']]);

        return $rows;
    }

    /**
     * For a member who is out and has not had the email: not sent yet (it can go),
     * or held back, with the reason in the admin's words.
     *
     * @return array{0: string, 1: string}
     */
    private function heldBack(User $user, int $failures): array
    {
        $userId = (int) $user->id;
        if ($failures >= 3) {
            return [self::MAIL_HELD, 'their address refused it three times'];
        }
        if (Cache::has($this->retryKey($userId))) {
            return [self::MAIL_HELD, 'the last try failed; it is tried again within the hour'];
        }
        if (! filter_var((string) $user->email, FILTER_VALIDATE_EMAIL) || PaidWelcomeEmail::isPhoneSignupAddress((string) $user->email)) {
            return [self::MAIL_HELD, 'they signed up without an email address'];
        }

        return match ($this->extraCredits->status($userId)) {
            ExtraCredits::STATUS_RENEWAL_DUE => [self::MAIL_HELD, 'their renewal payment is overdue'],
            ExtraCredits::STATUS_NO_PLAN => [self::MAIL_HELD, 'their plan is not one that can buy extra credits (a trial, a pack or a free plan)'],
            ExtraCredits::STATUS_OFF => [self::MAIL_HELD, 'extra credits are switched off'],
            default => $this->extraCredits->planEnds($userId)
                ? [self::MAIL_HELD, 'their plan is cancelled and ends with this period']
                : [self::MAIL_NOT_SENT, ''],
        };
    }

    /**
     * Why it cannot be sent to them now, in the admin's words, or null.
     */
    public function backfillBlocker(): ?string
    {
        return match (true) {
            ! ExtraCredits::ready() => 'Run the migrations first.',
            ! LiveServer::allowsCustomerActions() => 'This is not the live site: it never emails members.',
            ! $this->enabled() => '"Send the email automatically" is off above. Switch it on and save first.',
            ! ExtraCredits::enabled() => '"Sell extra credits" is off in Billing Settings, so the email\'s button would lead nowhere. Switch it on first.',
            default => null,
        };
    }

    /**
     * Starts sending it to the members who already ran out, a few a minute
     * (listen:credits-ended-backfill). Returns how many are waiting for it.
     */
    public function startBackfill(?int $startedBy = null): int
    {
        if ($this->backfillBlocker() !== null) {
            return 0;
        }

        return (int) Cache::lock(self::BACKFILL_LOCK, 300)->block(30, function () use ($startedBy): int {
            $state = $this->backfill();
            if ($state !== null && $state['ids'] !== []) {
                return 0;
            }

            $ids = $this->alreadyOut();
            if ($ids === []) {
                return 0;
            }

            $this->saveBackfill([
                'ids' => $ids,
                'total' => count($ids),
                'sent' => 0,
                'skipped' => 0,
                'not_reached' => 0,
                'failures' => [],
                'started_at' => now()->toIso8601String(),
                'started_by' => $startedBy,
                'finished_at' => null,
                'stopped' => false,
            ]);
            Log::info('Credits-ended email: sending to members who already ran out.', ['members' => count($ids), 'by' => $startedBy]);

            return count($ids);
        });
    }

    public function stopBackfill(): void
    {
        Cache::lock(self::BACKFILL_LOCK, 300)->block(30, function (): void {
            $state = $this->backfill();
            if ($state === null || $state['ids'] === []) {
                return;
            }

            $state['not_reached'] = count($state['ids']);
            $state['ids'] = [];
            $state['stopped'] = true;
            $state['finished_at'] = now()->toIso8601String();
            $this->saveBackfill($state);
        });
    }

    /**
     * The send to members who already ran out: waiting ids, counts and times,
     * or null when it was never started.
     *
     * @return array{ids:list<int>, total:int, sent:int, skipped:int, not_reached:int, failures:array<string,int>, started_at:?string, started_by:?int, finished_at:?string, stopped:bool}|null
     */
    public function backfill(): ?array
    {
        $raw = SiteSetting::get(self::BACKFILL_SETTING);
        $state = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        if (! is_array($state)) {
            return null;
        }

        return [
            'ids' => array_values(array_map('intval', (array) ($state['ids'] ?? []))),
            'total' => (int) ($state['total'] ?? 0),
            'sent' => (int) ($state['sent'] ?? 0),
            'skipped' => (int) ($state['skipped'] ?? 0),
            'not_reached' => (int) ($state['not_reached'] ?? 0),
            'failures' => array_map('intval', (array) ($state['failures'] ?? [])),
            'started_at' => $state['started_at'] ?? null,
            'started_by' => isset($state['started_by']) ? (int) $state['started_by'] : null,
            'finished_at' => $state['finished_at'] ?? null,
            'stopped' => (bool) ($state['stopped'] ?? false),
        ];
    }

    /**
     * Sends the next few (at most $limit emails), each checked again just before
     * it goes. A member who bought credits, renewed or already got it meanwhile is
     * left out; a send that fails is tried again later (three times at most).
     *
     * @return array{sent:int, skipped:int, waiting:int}
     */
    public function sendBackfillBatch(int $limit): array
    {
        return Cache::lock(self::BACKFILL_LOCK, 300)->block(5, function () use ($limit): array {
            $state = $this->backfill();
            if ($state === null || $state['ids'] === []) {
                return ['sent' => 0, 'skipped' => 0, 'waiting' => 0];
            }

            $queue = $state['ids'];
            $later = [];
            $sent = 0;
            $skipped = 0;
            $looked = 0;
            while ($queue !== [] && $sent < $limit && $looked < self::BACKFILL_LOOKS_PER_RUN) {
                $id = array_shift($queue);
                $looked++;
                $result = $this->deliver($id);
                if ($result === self::SENT) {
                    $sent++;
                } elseif ($result === self::SKIPPED) {
                    $skipped++;
                } elseif ($result === self::LATER) {
                    $later[] = $id;
                } else {
                    $tries = (int) ($state['failures'][(string) $id] ?? 0) + 1;
                    $state['failures'][(string) $id] = $tries;
                    if ($tries >= 3) {
                        $skipped++;
                    } else {
                        $later[] = $id;
                    }
                    // The mailbox may be refusing for now: the rest wait for the next run.
                    break;
                }
            }

            $state['ids'] = array_values(array_merge($queue, $later));
            $state['sent'] += $sent;
            $state['skipped'] += $skipped;
            if ($state['ids'] === []) {
                $state['finished_at'] = now()->toIso8601String();
            }
            $this->saveBackfill($state);

            return ['sent' => $sent, 'skipped' => $skipped, 'waiting' => count($state['ids'])];
        });
    }

    /** @param  array<string, mixed>  $state */
    private function saveBackfill(array $state): void
    {
        SiteSetting::set(self::BACKFILL_SETTING, json_encode($state), self::GROUP);
    }

    /**
     * Sends the email. With $testTo it goes to that address instead (a test copy
     * can go from any server). Throws when it could not be sent.
     */
    public function send(User $user, ?Plan $plan, \DateTimeInterface $renewsAt, ?string $testTo = null): void
    {
        $to = strtolower(trim((string) ($testTo ?? $user->email)));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('There is no valid email address to send to.');
        }

        $newsletter = $this->asNewsletter($this->settings(), $plan, $renewsAt, (string) $user->name);
        $renderer = app(NewsletterRenderer::class);
        // An account message, not a newsletter: no unsubscribe link, and the greeting is in the words.
        $recipient = ['name' => (string) $user->name, 'no_greeting' => true];

        $fromAddress = TitanMailbox::ADDRESS;
        if (! app()->environment('testing')) {
            TitanMailbox::apply();
            TitanMailbox::requirePassword();
            $fromAddress = TitanMailbox::username();
        }

        $mail = new CreditsEndedMail(
            htmlBody: $renderer->html($newsletter, Newsletter::LANGUAGE_BOTH, $recipient),
            textBody: $renderer->text($newsletter, Newsletter::LANGUAGE_BOTH, $recipient),
            subjectLine: $renderer->subject($newsletter, Newsletter::LANGUAGE_BOTH),
            fromAddress: $fromAddress,
            fromName: TitanMailbox::fromName(),
            replyToAddress: TitanMailbox::ADDRESS,
        );

        try {
            (app()->environment('testing') ? Mail::mailer() : Mail::mailer('titan'))->to($to)->send($mail);
        } catch (\Throwable $e) {
            throw new RuntimeException(TitanMailbox::explainFailure($e->getMessage()), 0, $e);
        }
    }

    /**
     * The email shown in the admin preview, for a sample member.
     *
     * @param  array<string, string>|null  $settings
     */
    public function previewHtml(?array $settings = null): string
    {
        $settings ??= $this->settings();
        $sample = Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)->orderBy('price_cents')->first();
        $newsletter = $this->asNewsletter($settings, $sample, now()->addDays(12), '');

        return app(NewsletterRenderer::class)->html($newsletter, Newsletter::LANGUAGE_BOTH, ['preview' => true, 'no_greeting' => true]);
    }

    /** Where the email's button leads: the studio, with the extra-credits dialog open. */
    public static function addCreditsUrl(string $lang): string
    {
        return rtrim((string) config('app.url', ''), '/').'/'.$lang.'/dashboard?extra_credits=1';
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function asNewsletter(array $settings, ?Plan $plan, \DateTimeInterface $renewsAt, string $memberName): Newsletter
    {
        $fields = [
            'language' => Newsletter::LANGUAGE_BOTH,
            'bilingual_order' => ($settings['order'] ?? 'en_ar') === 'ar_en' ? 'ar_en' : 'en_ar',
        ];

        foreach (['en', 'ar'] as $lang) {
            $date = Carbon::instance($renewsAt)->utc()->locale($lang)->translatedFormat('j F');
            $fill = function (string $text) use ($plan, $lang, $date, $memberName): string {
                $text = PaidWelcomeEmail::withPlan($text, $plan, $lang);
                $text = str_replace('{date}', $date, $text);

                // {name} is the first name; with none, the greeting closes up ("Hi, ...").
                $first = trim((string) (preg_split('/\s+/u', trim($memberName))[0] ?? ''));
                $text = $first !== '' ? str_replace('{name}', $first, $text) : preg_replace('/\s*\{name\}/u', '', $text);

                return preg_replace('/[ \t]{2,}/', ' ', (string) $text) ?? (string) $text;
            };

            foreach (['subject', 'headline', 'body', 'cta_label', 'footer'] as $field) {
                $fields[$field.'_'.$lang] = $fill((string) ($settings[$field.'_'.$lang] ?? ''));
            }
            $fields['preheader_'.$lang] = $fields['headline_'.$lang];
            $fields['cta_url_'.$lang] = self::addCreditsUrl($lang);
        }

        return new Newsletter($fields);
    }

    private function retryKey(int $userId): string
    {
        return 'credits_ended_email:retry:'.$userId;
    }
}
