<?php

namespace App\Services\Newsletter;

use App\Mail\NewsletterMail;
use App\Models\Newsletter;
use App\Models\NewsletterDelivery;
use App\Models\NewsletterSuppression;
use App\Models\User;
use App\Support\TitanMailbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;

class NewsletterSender
{
    private const RUNNER_LOCK = 'newsletter_sender_lock';

    private const RUNNER_HEARTBEAT = 'newsletter_sender_heartbeat';

    private const SPAWN_ERROR = 'newsletter_sender_spawn_error';

    public function __construct(
        private readonly NewsletterAudience $audience,
        private readonly NewsletterRenderer $renderer,
        private readonly NewsletterPace $pace,
    ) {}

    /**
     * Send one preview copy. This never selects the saved audience.
     */
    public function sendExample(Newsletter $newsletter, string $email, string $locale): void
    {
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\s,;]/', $email)) {
            throw new InvalidArgumentException('Enter one email address for the example.');
        }

        $locale = in_array($locale, [
            Newsletter::LANGUAGE_EN,
            Newsletter::LANGUAGE_AR,
            Newsletter::LANGUAGE_BOTH,
        ], true) ? $locale : Newsletter::LANGUAGE_EN;

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        $delivered = $this->deliver($newsletter, [
            'email' => $email,
            'name' => $user ? (string) $user->name : '',
            'user_id' => $user?->id,
        ], $locale, true);

        if (! $delivered) {
            $error = (string) $newsletter->deliveries()
                ->where('email', $email)
                ->where('is_test', true)
                ->latest('id')
                ->value('error');

            throw new RuntimeException($error !== '' ? $error : 'The example email could not be sent.');
        }

        $newsletter->forceFill([
            'last_test_sent_at' => now(),
            'last_test_email' => $email,
        ])->save();
    }

    /**
     * Queue the next batch and, in tests, send it immediately.
     *
     * @return array{sent: int, failed: int, queued: int}
     */
    public function sendToAudience(Newsletter $newsletter, bool $confirmed = false): array
    {
        $result = $this->sendNow($newsletter, $this->pace->perClick(), $confirmed);

        return [
            'sent' => $result['sent'],
            'failed' => $result['failed'],
            'queued' => $result['queued'],
        ];
    }

    /**
     * Line up the next people who have not received this newsletter.
     * Mail goes out in the background through newsletter:work.
     *
     * @return array{queued: int, sent: int, failed: int, waiting: int}
     */
    public function queueNextBatch(Newsletter $newsletter, int $batchSize, bool $confirmed = false, bool $startRunner = true): array
    {
        if (! $confirmed) {
            throw new RuntimeException('Audience send must be confirmed in the Newsletter admin.');
        }

        if (! $newsletter->getKey()) {
            throw new RuntimeException('Save the newsletter before sending it.');
        }

        $batchSize = max(1, min($batchSize, $this->pace->dailyLimit()));

        $result = Cache::lock('newsletter-batch', 20)->block(10, function () use ($newsletter, $batchSize): array {
            return DB::transaction(function () use ($newsletter, $batchSize): array {
                $locked = Newsletter::query()->whereKey($newsletter->getKey())->lockForUpdate()->firstOrFail();
                $alreadyQueued = $locked->queuedDeliveries()->count();

                if ($alreadyQueued > 0) {
                    throw new RuntimeException('A batch is already going out for this newsletter ('.$alreadyQueued.' still queued). The next click continues after this batch finishes.');
                }

                $remainingToday = $this->pace->dailyRemaining();
                if ($remainingToday < 1) {
                    throw new RuntimeException('The mailbox has already used its '.$this->pace->dailyLimit().' sends for today. The next batch can start tomorrow.');
                }

                $recipients = $this->audience->pending($locked, min($batchSize, $remainingToday));

                foreach ($recipients as $recipient) {
                    NewsletterDelivery::query()->create([
                        'newsletter_id' => $locked->getKey(),
                        'user_id' => $recipient['user_id'],
                        'email' => $recipient['email'],
                        'locale' => (string) $locked->language,
                        'is_test' => false,
                        'status' => NewsletterDelivery::STATUS_QUEUED,
                        'error' => null,
                        'sent_at' => null,
                    ]);
                }

                if ($recipients !== []) {
                    $locked->forceFill(['status' => Newsletter::STATUS_SENDING])->save();
                }

                $fresh = $locked->fresh() ?? $locked;
                $progress = $this->progress($fresh);

                return [
                    'queued' => count($recipients),
                    'sent' => $progress['sent'],
                    'failed' => $progress['failed'],
                    'waiting' => $this->audience->waitingCount($fresh),
                ];
            });
        });

        if ($startRunner && ($result['queued'] ?? 0) > 0) {
            $this->ensureRunning();
        }

        return $result;
    }

    /**
     * Send the next messages before the admin request ends.
     * Uses the same mail call as an example send. Does not touch the scheduler.
     *
     * @return array{sent: int, failed: int, queued: int, waiting: int, hold: ?string}
     */
    public function sendNow(Newsletter $newsletter, int $count, bool $confirmed = false): array
    {
        if (! $confirmed) {
            throw new RuntimeException('Audience send must be confirmed in the Newsletter admin.');
        }

        $count = max(1, min($count, $this->pace->perClick()));

        if (! app()->environment('testing')) {
            set_time_limit(180);
        }

        if ($newsletter->queuedDeliveries()->count() < 1) {
            $this->queueNextBatch($newsletter, $count, true, false);
        }

        $sent = $this->drain($count, true);
        $fresh = $newsletter->fresh() ?? $newsletter;
        $progress = $this->progress($fresh);

        return [
            'sent' => $sent,
            'failed' => $progress['failed'],
            'queued' => $progress['queued'],
            'waiting' => $this->audience->waitingCount($fresh),
            'hold' => $this->pace->holdReason(),
        ];
    }

    /**
     * The batch is only a queue until a process sends it. Start that process
     * when the scheduler is not already doing it.
     */
    public function ensureRunning(): bool
    {
        if (app()->environment('testing')) {
            return false;
        }

        if ($this->pace->queued() < 1 || $this->pace->holdReason() !== null) {
            return false;
        }

        if ($this->runnerIsAlive()) {
            return true;
        }

        return $this->spawn();
    }

    public function runnerIsAlive(): bool
    {
        if (! Cache::has(self::RUNNER_LOCK)) {
            return false;
        }

        $beat = Cache::get(self::RUNNER_HEARTBEAT);
        if (! is_string($beat) || $beat === '') {
            return true;
        }

        try {
            return Carbon::parse($beat)->greaterThan(now()->subSeconds(90));
        } catch (\Throwable) {
            return false;
        }
    }

    public function spawnError(): ?string
    {
        $error = Cache::get(self::SPAWN_ERROR);

        return is_string($error) && $error !== '' ? $error : null;
    }

    public function claimRunner(): bool
    {
        return Cache::add(self::RUNNER_LOCK, (string) getmypid(), 90);
    }

    public function refreshRunner(): void
    {
        Cache::put(self::RUNNER_LOCK, (string) getmypid(), 90);
        Cache::put(self::RUNNER_HEARTBEAT, now()->toIso8601String(), 120);
    }

    public function releaseRunner(): void
    {
        Cache::forget(self::RUNNER_LOCK);
        Cache::forget(self::RUNNER_HEARTBEAT);
    }

    private function spawn(): bool
    {
        if (! function_exists('exec') || $this->functionDisabled('exec')) {
            Cache::put(self::SPAWN_ERROR, 'The server cannot start the newsletter sender in the background. Queued mail stays waiting until the scheduler runs.', now()->addHour());

            return false;
        }

        $php = $this->phpBinary();
        $log = storage_path('logs/newsletter-sender.log');
        $command = 'nohup '.escapeshellarg($php).' '.escapeshellarg(base_path('artisan')).' newsletter:work >> '.escapeshellarg($log).' 2>&1 &';
        exec($command, $output, $code);

        if ($code !== 0) {
            Cache::put(self::SPAWN_ERROR, 'The newsletter sender did not start (exit '.$code.').', now()->addHour());

            return false;
        }

        Cache::forget(self::SPAWN_ERROR);

        return true;
    }

    private function phpBinary(): string
    {
        $candidates = [];
        if (PHP_SAPI === 'cli') {
            $candidates[] = PHP_BINARY;
        }
        $candidates[] = PHP_BINDIR.DIRECTORY_SEPARATOR.'php';
        $candidates[] = '/usr/bin/php';
        $candidates[] = '/usr/local/bin/php';

        foreach ($candidates as $path) {
            if ($path !== '' && is_executable($path)) {
                return $path;
            }
        }

        return 'php';
    }

    private function functionDisabled(string $name): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return in_array($name, $disabled, true);
    }

    /**
     * Send up to $limit queued messages. Mailbox-wide Titan errors pause the rest.
     */
    public function drain(int $limit, bool $respectPace = true): int
    {
        if ($limit < 1 || ($respectPace && $this->pace->holdReason() !== null)) {
            return 0;
        }

        if ($respectPace) {
            $limit = min($limit, $this->pace->hourlyRemaining());
        }

        if ($limit < 1) {
            return 0;
        }

        $rows = NewsletterDelivery::query()
            ->with('user')
            ->where('is_test', false)
            ->where('status', NewsletterDelivery::STATUS_QUEUED)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $sent = 0;

        foreach ($rows as $row) {
            $newsletter = $row->newsletter;
            if (! $newsletter) {
                $row->delete();

                continue;
            }

            if ($this->releaseIfUnsubscribed($row)) {
                $this->refreshProgress($newsletter);

                continue;
            }

            try {
                $this->transmit($newsletter, [
                    'email' => strtolower((string) $row->email),
                    'name' => (string) ($row->user->name ?? ''),
                    'user_id' => $row->user_id ? (int) $row->user_id : null,
                ], (string) ($row->locale ?: $newsletter->language));

                $row->forceFill([
                    'status' => NewsletterDelivery::STATUS_SENT,
                    'error' => null,
                    'sent_at' => now(),
                ])->save();
                $sent++;
            } catch (\Throwable $exception) {
                $message = $this->pace->explainedHold($exception->getMessage());

                if ($this->pace->isMailboxFailure($exception->getMessage())) {
                    $this->pace->hold($message, $this->pace->holdUntilFor($exception->getMessage()));
                    $this->refreshProgress($newsletter);

                    break;
                }

                $row->forceFill([
                    'status' => NewsletterDelivery::STATUS_FAILED,
                    'error' => $message,
                    'sent_at' => null,
                ])->save();
            }

            $this->refreshProgress($newsletter);
        }

        return $sent;
    }

    public function cancelQueued(Newsletter $newsletter): int
    {
        $deleted = $newsletter->queuedDeliveries()->delete();
        $this->refreshProgress($newsletter->fresh() ?? $newsletter);

        return $deleted;
    }

    /**
     * @return array{sent: int, failed: int, queued: int}
     */
    public function progress(Newsletter $newsletter): array
    {
        $sent = [];
        $failed = [];
        $queued = [];

        foreach ($newsletter->deliveries()->where('is_test', false)->get(['email', 'status']) as $row) {
            $email = strtolower((string) $row->email);
            if ($row->status === NewsletterDelivery::STATUS_SENT) {
                $sent[$email] = true;
            } elseif ($row->status === NewsletterDelivery::STATUS_QUEUED) {
                $queued[$email] = true;
            } elseif ($row->status === NewsletterDelivery::STATUS_FAILED) {
                $failed[$email] = true;
            }
        }

        foreach (array_keys($sent + $queued) as $email) {
            unset($failed[$email]);
        }

        return [
            'sent' => count($sent),
            'failed' => count($failed),
            'queued' => count($queued),
        ];
    }

    /**
     * @param  array{email: string, name: string, user_id: int|null}  $recipient
     */
    private function deliver(Newsletter $newsletter, array $recipient, string $locale, bool $isTest): bool
    {
        try {
            $this->transmit($newsletter, $recipient, $locale);
            $this->log($newsletter, $recipient, $locale, $isTest, NewsletterDelivery::STATUS_SENT, null);

            return true;
        } catch (\Throwable $exception) {
            $this->log(
                $newsletter,
                $recipient,
                $locale,
                $isTest,
                NewsletterDelivery::STATUS_FAILED,
                TitanMailbox::explainFailure($exception->getMessage())
            );

            return false;
        }
    }

    /**
     * @param  array{email: string, name: string, user_id: int|null}  $recipient
     */
    private function transmit(Newsletter $newsletter, array $recipient, string $locale): void
    {
        $html = $this->renderer->html($newsletter, $locale, $recipient, false);
        $text = $this->renderer->text($newsletter, $locale, $recipient, false);
        $subject = $this->renderer->subject($newsletter, $locale);
        if ($subject === '') {
            throw new RuntimeException('Add a subject before sending.');
        }

        $fromName = filled($newsletter->from_name)
            ? (string) $newsletter->from_name
            : TitanMailbox::fromName();
        $replyTo = filter_var((string) $newsletter->reply_to, FILTER_VALIDATE_EMAIL)
            ? (string) $newsletter->reply_to
            : null;
        $unsubscribeUrl = $this->renderer->unsubscribeUrl($recipient['email'], $locale);
        $fromAddress = filter_var((string) $newsletter->from_address, FILTER_VALIDATE_EMAIL)
            ? (string) $newsletter->from_address
            : TitanMailbox::ADDRESS;

        if (! app()->environment('testing')) {
            TitanMailbox::apply();
            TitanMailbox::requirePassword();
            $fromAddress = TitanMailbox::username();
        }

        $pending = new NewsletterMail(
            htmlBody: $html,
            textBody: $text,
            subjectLine: $subject,
            fromAddress: $fromAddress,
            fromName: $fromName,
            replyToAddress: $replyTo,
            unsubscribeUrl: $unsubscribeUrl !== '' ? $unsubscribeUrl : null,
        );

        $mailer = app()->environment('testing') ? Mail::mailer() : Mail::mailer('titan');
        $mailer->to($recipient['email'], $recipient['name'] !== '' ? $recipient['name'] : null)
            ->send($pending);
    }

    private function releaseIfUnsubscribed(NewsletterDelivery $row): bool
    {
        $email = strtolower((string) $row->email);
        $suppressed = NewsletterSuppression::query()->where('email', $email)->exists();
        if (! $suppressed) {
            return false;
        }

        $row->forceFill([
            'status' => NewsletterDelivery::STATUS_FAILED,
            'error' => 'This address unsubscribed before the message was sent.',
            'sent_at' => null,
        ])->save();

        return true;
    }

    private function refreshProgress(Newsletter $newsletter): void
    {
        $sent = $newsletter->deliveries()
            ->where('is_test', false)
            ->where('status', NewsletterDelivery::STATUS_SENT)
            ->count();
        $failed = $newsletter->deliveries()
            ->where('is_test', false)
            ->where('status', NewsletterDelivery::STATUS_FAILED)
            ->count();
        $queued = $newsletter->queuedDeliveries()->count();

        $newsletter->forceFill([
            'status' => $queued > 0
                ? Newsletter::STATUS_SENDING
                : ($sent > 0 ? Newsletter::STATUS_SENT : Newsletter::STATUS_DRAFT),
            'sent_at' => $sent > 0 ? ($newsletter->sent_at ?? now()) : $newsletter->sent_at,
            'sent_count' => $sent,
            'failed_count' => $failed,
        ])->save();
    }

    /**
     * @param  array{email: string, name: string, user_id: int|null}  $recipient
     */
    private function log(
        Newsletter $newsletter,
        array $recipient,
        string $locale,
        bool $isTest,
        string $status,
        ?string $error,
    ): void {
        if (! $newsletter->getKey()) {
            return;
        }

        NewsletterDelivery::query()->create([
            'newsletter_id' => $newsletter->getKey(),
            'user_id' => $recipient['user_id'],
            'email' => $recipient['email'],
            'locale' => $locale,
            'is_test' => $isTest,
            'status' => $status,
            'error' => $error,
            'sent_at' => $status === NewsletterDelivery::STATUS_SENT ? now() : null,
        ]);
    }
}
