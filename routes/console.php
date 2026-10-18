<?php

use App\Models\ApiRequestLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('listen:billing-sync')->everyFifteenMinutes();
Schedule::command('listen:billing-reminders')->dailyAt('09:00');
Schedule::command('listen:billing-enforce-grace')->hourly();
Schedule::command('plans:expire-discounts')->everyFiveMinutes();

// Developer API request log: keep 90 days.
Schedule::call(fn () => ApiRequestLog::query()
    ->where('created_at', '<', now()->subDays(ApiRequestLog::KEEP_DAYS))
    ->delete())->daily()->name('api-request-log-prune');
