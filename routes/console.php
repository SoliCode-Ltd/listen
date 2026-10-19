<?php

use App\Models\ApiIdempotencyKey;
use App\Models\ApiRequestLog;
use App\Services\Api\ApiIdempotency;
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
