<?php

use App\Jobs\TranscribeJob;
use App\Models\ApiIdempotencyKey;
use App\Models\ApiRequestLog;
use App\Services\Api\ApiIdempotency;
use App\Support\Agents\CallRecordings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Billing: a run that is still going is never started again on top of itself (two
// syncs at once would call Stripe twice for every member). Each lock lapses after a
// while, so a run that died never stops the next ones for long.
Schedule::command('listen:billing-sync')->everyFifteenMinutes()->withoutOverlapping(60);
Schedule::command('listen:billing-reminders')->dailyAt('09:00')->withoutOverlapping(120);
Schedule::command('listen:billing-enforce-grace')->hourly()->withoutOverlapping(50);
Schedule::command('plans:expire-discounts')->everyFiveMinutes()->withoutOverlapping(10);
// A declined first payment or a checkout left without paying: the follow-up emails that are due,
// and who bought a plan since (App\Services\Billing\PurchaseFollowUps).
Schedule::command('listen:purchase-follow-ups')->everyFiveMinutes()->withoutOverlapping(10);
// Lower plans whose day has come take over (paid by hand, or by card after a plan paid by hand).
Schedule::command('listen:plan-changes')->everyMinute()->withoutOverlapping(10);

// Developer API: give back the credits of generations that got stuck.
// The overlap lock lapses after 10 minutes, so a run that died never stops the next ones for long.
Schedule::command('api:expire-stuck-generations')->everyFiveMinutes()->withoutOverlapping(10);

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
Schedule::command('listen:expire-stuck-generations')->everyFiveMinutes()->withoutOverlapping(10);

// Dub my video: give back the credits of stuck dubs, move finished videos to Google Drive
// and delete old server copies (App\Console\Commands\PruneVideoDubsCommand).
Schedule::command('dubs:prune')->everyTenMinutes()->withoutOverlapping(30);

// Speech to text: give back the minutes of stuck transcripts and delete uploaded audio after the
// days set in Speech to text → Settings (App\Console\Commands\PruneTranscriptionsCommand).
Schedule::command('transcriptions:prune')->everyTenMinutes()->withoutOverlapping(30);

// Speech to text works on queues of its own (TranscribeJob::QUEUE…), so a long transcript never keeps
// the studio's background voices waiting on theirs. Its workers are started here, three at most at a
// time, each for under a minute (then the next minute's takes over); a step is never cut short, since
// a worker finishes the one it is on. Nothing to start where jobs run as they are sent.
foreach (['transcribe-1', 'transcribe-2', 'transcribe-3'] as $worker) {
    Schedule::command('queue:work', [
        '--queue' => TranscribeJob::QUEUE_PRIORITY.','.TranscribeJob::QUEUE,
        '--name' => $worker,
        '--max-time' => 55,
        '--sleep' => 2,
        '--tries' => 3,
        '--timeout' => 150,
    ])->everyMinute()->withoutOverlapping(5)->runInBackground()
        ->when(fn (): bool => config('queue.default') !== 'sync');
}

// Voice Design: delete the takes nobody kept, so the speech projects keep room for voices
// (App\Console\Commands\PruneDesignedVoicesCommand).
Schedule::command('voice-design:prune')->everyThirtyMinutes()->withoutOverlapping(20);

// Listen Agents: calls whose browser went quiet are charged up to their last sign of life.
Schedule::command('agents:settle-calls')->everyMinute()->withoutOverlapping(5);

// Admin → Suspicious Users' list and its badge, worked out ahead of time (App\Services\DemoSuspects).
Schedule::command('listen:warm-demo-suspects')->everyFiveMinutes()->withoutOverlapping(10);

// The admin dashboard's numbers, rebuilt ahead of time on the live site only (they read
// Stripe; staging runs the live keys). In the background: at a large size a rebuild takes
// a minute, and the jobs due every minute must not wait for it.
Schedule::command('listen:warm-business-stats')->everyFiveMinutes()->withoutOverlapping(15)->runInBackground();

// Listen Agents: the newest posts on agents' links read again every few hours (AgentLinkReads).
Schedule::command('agents:read-links')->hourly()->withoutOverlapping(30);
// Every page of live agents' websites, for what callers ask (AgentSite): never read, or not for a week.
Schedule::command('agents:read-websites')->hourly()->withoutOverlapping(30);
// Listen Agents: call recordings older than CallRecordings::KEEP_DAYS days go (the call's words stay).
Schedule::call(fn () => CallRecordings::prune())->name('agents:prune-recordings')->dailyAt('04:10')->withoutOverlapping(60);
