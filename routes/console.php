<?php

use App\Jobs\TranscribeJob;
use App\Models\ApiIdempotencyKey;
use App\Models\ApiRequestLog;
use App\Services\Api\ApiIdempotency;
use App\Support\Agents\CallRecordings;
use App\Support\LiveServer;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Start times are spread over the minutes, so the jobs never all start at once (each one starts
// the whole app: on a busy server a minute where a dozen start together is a spike). None starts
// on a minute that is a multiple of five (:00, :05, …), when other sites' timed jobs on the same
// server start, and the heavy ones each have a minute of their own. The every-minute jobs and the
// speech-to-text workers keep their minutes.

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Speech to text works on queues of its own (TranscribeJob::QUEUE…), so a long transcript never keeps
// the studio's background voices waiting on theirs. Its three workers are started here, before every
// other command, so none of those can hold them up. A worker stops after its time and the schedule
// starts it again, but never while it is still running (withoutOverlapping): one still running at its
// next start misses that start. So each stops well before it: transcribe-1 every minute, after 45 s;
// transcribe-2 and transcribe-3 every two minutes, a minute apart, after 95 s, so one of those two is
// always there to take a new file, even while transcribe-1 waits for its next minute. A worker
// finishes the step it is on before it stops. Nothing to start where jobs run as they are sent.
foreach ([
    ['transcribe-1', '* * * * *', 45],
    ['transcribe-2', '*/2 * * * *', 95],
    ['transcribe-3', '1-59/2 * * * *', 95],
] as [$worker, $starts, $seconds]) {
    Schedule::command('queue:work', [
        '--queue' => TranscribeJob::QUEUE_PRIORITY.','.TranscribeJob::QUEUE,
        '--name' => $worker,
        '--max-time' => $seconds,
        '--sleep' => 2,
        '--tries' => 3,
        '--timeout' => 150,
    ])->cron($starts)->withoutOverlapping(5)->runInBackground()
        ->when(fn (): bool => config('queue.default') !== 'sync');
}

// Billing: a run that is still going is never started again on top of itself (two
// syncs at once would call Stripe twice for every member). Each lock lapses after a
// while, so a run that died never stops the next ones for long.
Schedule::command('listen:billing-sync')->cron('13-59/15 * * * *')->withoutOverlapping(60);
Schedule::command('listen:billing-reminders')->dailyAt('09:00')->withoutOverlapping(120);
Schedule::command('listen:billing-enforce-grace')->hourlyAt(18)->withoutOverlapping(50);
// Extra credits left when a period ends: on to the next period, or ended with the plan (ExtraCredits).
// After the grace job, so a plan it ends is already over.
Schedule::command('listen:extra-credits-settle')->hourlyAt(27)->withoutOverlapping(50);
// The owner's send of the credits-ended email to members who already ran out: a few a minute while it is going.
Schedule::command('listen:credits-ended-backfill')->everyMinute()->withoutOverlapping(10);
Schedule::command('plans:expire-discounts')->cron('1-59/5 * * * *')->withoutOverlapping(10);
// A declined first payment or a checkout left without paying: the follow-up emails that are due,
// and who bought a plan since (App\Services\Billing\PurchaseFollowUps).
Schedule::command('listen:purchase-follow-ups')->cron('2-59/5 * * * *')->withoutOverlapping(10);
// Paid plans for ChatGPT Ads that could not be sent the moment they were paid (ChatGptAdsConversions).
Schedule::command('listen:chatgpt-ads-send')->cron('3-59/5 * * * *')->withoutOverlapping(10);
// Lower plans whose day has come take over (paid by hand, or by card after a plan paid by hand).
Schedule::command('listen:plan-changes')->everyMinute()->withoutOverlapping(10);

// Developer API: give back the credits of generations that got stuck.
// The overlap lock lapses after 10 minutes, so a run that died never stops the next ones for long.
Schedule::command('api:expire-stuck-generations')->cron('3-59/5 * * * *')->withoutOverlapping(10);

// Developer API request log (90 days) and Idempotency-Key records (past their
// 24 hours), removed in small batches so the tables are never locked for long.
Schedule::call(function (): void {
    ApiRequestLog::pruneOlderThan(now()->subDays(ApiRequestLog::KEEP_DAYS));
    if (ApiIdempotency::available()) {
        ApiIdempotencyKey::pruneExpired();
    }
})->daily()->name('api-request-log-prune');

// "Sign in with Listen" (App\Mcp\SignInWithListen): sign-in codes and tokens that
// expired or were revoked (a disconnected app's) more than a week ago.
Schedule::command('passport:purge --hours=168')->dailyAt('03:30')->withoutOverlapping(30);

// Studio and extension: give back the credits of members' generations that got stuck.
// The overlap lock lapses after 10 minutes, so a run that died never stops the next ones for long.
Schedule::command('listen:expire-stuck-generations')->cron('1-59/5 * * * *')->withoutOverlapping(10);

// Dub my video: give back the credits of stuck dubs, move finished videos to Google Drive
// and delete old server copies (App\Console\Commands\PruneVideoDubsCommand).
Schedule::command('dubs:prune')->cron('9-59/10 * * * *')->withoutOverlapping(30);

// Speech to text: give back the minutes of stuck transcripts and delete uploaded audio after the
// days set in Speech to text → Settings (App\Console\Commands\PruneTranscriptionsCommand).
Schedule::command('transcriptions:prune')->cron('7-59/10 * * * *')->withoutOverlapping(30);

// Voice Design: delete the takes nobody kept, so the speech projects keep room for voices
// (App\Console\Commands\PruneDesignedVoicesCommand).
Schedule::command('voice-design:prune')->cron('16,46 * * * *')->withoutOverlapping(20);

// Listen Agents: calls whose browser went quiet are charged up to their last sign of life.
Schedule::command('agents:settle-calls')->everyMinute()->withoutOverlapping(5);

// Admin → Suspicious Users' list and its badge, worked out ahead of time (App\Services\DemoSuspects),
// every ten minutes: well within the 15 minutes the list is kept, so an admin never waits for it.
Schedule::command('listen:warm-demo-suspects')->cron('4-59/10 * * * *')->withoutOverlapping(10);

// The admin dashboard's numbers, rebuilt ahead of time on the live site only (they read
// Stripe; staging runs the live keys), every ten minutes: within the 15 minutes they are kept
// (BusinessStatsService), so an admin never waits for them. In the background: at a large size
// a rebuild takes a minute, and the jobs due every minute must not wait for it. Staging does
// not even start it.
Schedule::command('listen:warm-business-stats')->cron('2-59/10 * * * *')->withoutOverlapping(15)->runInBackground()
    ->when(fn (): bool => LiveServer::allowsCustomerActions());

// Listen Agents: the newest posts on agents' links read again every few hours (AgentLinkReads).
Schedule::command('agents:read-links')->hourlyAt(33)->withoutOverlapping(30);
// Every page of live agents' websites, for what callers ask (AgentSite): never read, or not for a week.
Schedule::command('agents:read-websites')->hourlyAt(48)->withoutOverlapping(30);
// Listen Agents: call recordings older than CallRecordings::KEEP_DAYS days go (the call's words stay).
Schedule::call(fn () => CallRecordings::prune())->name('agents:prune-recordings')->dailyAt('04:10')->withoutOverlapping(60);
