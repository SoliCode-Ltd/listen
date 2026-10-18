<?php

use App\Models\SiteSetting;
use App\Support\CreditPeriod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paid plans' monthly credits follow the plan's own dates from now on
     * (App\Support\CreditPeriod): a period key such as "2026-10-14 08:37"
     * needs more room than "2026-10". Periods that had already started keep
     * the counter they were using, so nobody gets new credits from this.
     *
     * To go back to calendar months, delete the site setting
     * credit_periods_from (or roll this migration back).
     */
    public function up(): void
    {
        foreach (['usage_counters', 'char_gifts', 'api_key_usages'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'period_ym')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->string('period_ym', 20)->change();
                });
            }
        }

        // In the test suite each test turns it on itself.
        if (! app()->runningUnitTests() && CreditPeriod::switchedAt() === null) {
            SiteSetting::set(CreditPeriod::SWITCH_SETTING, now()->utc()->toDateTimeString(), 'billing');
        }
    }

    public function down(): void
    {
        SiteSetting::query()->where('key', CreditPeriod::SWITCH_SETTING)->delete();
        Cache::forget('site_setting.'.CreditPeriod::SWITCH_SETTING);
    }
};
