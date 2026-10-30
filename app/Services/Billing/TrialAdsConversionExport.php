<?php

namespace App\Services\Billing;

use App\Models\BillingTrial;
use App\Models\SiteSetting;
use App\Support\Countries;

/**
 * Paid trials that came from a Google Ads click, in the Google Ads
 * "Conversions from clicks" upload format.
 */
class TrialAdsConversionExport
{
    public const HEADER = ['Google Click ID', 'Conversion Name', 'Conversion Time', 'Conversion Value', 'Conversion Currency'];

    public static function conversionName(): string
    {
        $name = trim((string) SiteSetting::get('google_ads_trial_paid_conversion_name', ''));

        return $name !== '' ? $name : 'Trial converted';
    }

    /**
     * @return list<list<string>>
     */
    public function rows(int $days = 90): array
    {
        $name = self::conversionName();

        return BillingTrial::query()
            ->where('status', BillingTrial::STATUS_PAID)
            ->where('is_admin_test', false)
            ->whereNotNull('gclid')
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', now()->subDays($days))
            // Never a member from where the consent rules for ads apply.
            ->whereDoesntHave('user', fn ($user) => $user->whereIn('country_code', Countries::ADS_CONSENT_REQUIRED))
            ->orderBy('paid_at')
            ->get()
            ->map(fn (BillingTrial $trial): array => [
                (string) $trial->gclid,
                $name,
                $trial->paid_at->copy()->utc()->format('Y-m-d H:i:sP'),
                number_format(((int) ($trial->amount_paid_cents ?? $trial->amount_cents)) / 100, 2, '.', ''),
                strtoupper((string) ($trial->currency ?: 'usd')),
            ])
            ->values()
            ->all();
    }

    public function csv(int $days = 90): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::HEADER);
        foreach ($this->rows($days) as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
