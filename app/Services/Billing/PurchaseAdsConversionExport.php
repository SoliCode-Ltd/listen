<?php

namespace App\Services\Billing;

use App\Models\AdsPurchase;
use App\Models\SiteSetting;
use App\Support\Countries;

/**
 * Purchases that came from a Google Ads click (AdsPurchases), in the Google Ads
 * "Conversions from clicks" upload format. Payments by hand and card payments
 * each go to their own conversion action, named in SEO settings.
 */
class PurchaseAdsConversionExport
{
    public const HEADER = TrialAdsConversionExport::HEADER;

    /** Currencies counted without cents, or in thousandths, as Stripe and the banks write them. */
    private const ZERO_DECIMAL = ['JPY', 'KRW', 'VND', 'CLP', 'IDR', 'IQD'];

    private const THREE_DECIMAL = ['BHD', 'KWD', 'OMR', 'JOD', 'TND', 'LYD'];

    public static function conversionName(string $source): string
    {
        [$key, $default] = $source === AdsPurchase::SOURCE_MANUAL
            ? ['google_ads_manual_purchase_conversion_name', 'Manual purchase']
            : ['google_ads_card_purchase_conversion_name', 'Card purchase (server)'];
        $name = trim((string) SiteSetting::get($key, ''));

        return $name !== '' ? $name : $default;
    }

    /**
     * @return list<list<string>>
     */
    public function rows(int $days = AdsPurchases::CLICK_DAYS): array
    {
        $names = [
            AdsPurchase::SOURCE_CARD => self::conversionName(AdsPurchase::SOURCE_CARD),
            AdsPurchase::SOURCE_MANUAL => self::conversionName(AdsPurchase::SOURCE_MANUAL),
        ];

        return AdsPurchase::query()
            ->where('purchased_at', '>=', now()->subDays($days))
            // Never a member from where the consent rules for ads apply.
            ->whereDoesntHave('user', fn ($user) => $user->whereIn('country_code', Countries::ADS_CONSENT_REQUIRED))
            ->orderBy('purchased_at')
            ->orderBy('id')
            ->get()
            ->map(fn (AdsPurchase $purchase): array => [
                (string) $purchase->gclid,
                $names[$purchase->source] ?? $names[AdsPurchase::SOURCE_CARD],
                $purchase->purchased_at->copy()->utc()->format('Y-m-d H:i:sP'),
                self::amount($purchase->value_minor, $purchase->currency),
                strtoupper($purchase->currency),
            ])
            ->values()
            ->all();
    }

    public function csv(int $days = AdsPurchases::CLICK_DAYS): string
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

    private static function amount(int $minor, string $currency): string
    {
        $currency = strtoupper($currency);
        if (in_array($currency, self::ZERO_DECIMAL, true)) {
            return number_format($minor, 2, '.', '');
        }
        $divisor = in_array($currency, self::THREE_DECIMAL, true) ? 1000 : 100;

        return number_format($minor / $divisor, 2, '.', '');
    }
}
