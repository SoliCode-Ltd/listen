<?php

namespace App\Support;

/**
 * The symbol a price is written with, for the currencies the formatting library would
 * otherwise write as their three-letter code ("TRY 15.00" becomes "₺15.00", "15.00 ₺" in
 * Arabic). Each is the symbol the currency's own country uses, as that country's own
 * locale data writes it (Latin letters where the country also writes it that way).
 *
 * Left out on purpose:
 *  - Arab currencies: the Arabic pages already write them in Arabic letters ("15 ر.س."),
 *    and the English pages keep the code ("SAR 15"), as shops in the region write them.
 *  - Currencies whose own symbol is a bare "$" (Argentine, Chilean, Colombian pesos and
 *    other dollars): on a site priced in US dollars, "$" alone would read as US dollars.
 *  - Currencies whose own symbol is their code (CHF, RON, RSD, TMT, ZWG, KPW).
 *  - South Sudan's pound, whose "£" would read as the British pound.
 *
 * Only how the price is written: the amount and the charge (always in US dollars) are
 * untouched.
 */
final class CurrencySymbols
{
    /** @var array<string, string> */
    private const SYMBOLS = [
        'AFN' => '؋',
        'ALL' => 'Lekë',
        'AMD' => '֏',
        'AOA' => 'Kz',
        'AWG' => 'Afl.',
        'AZN' => '₼',
        'BAM' => 'KM',
        'BDT' => '৳',
        'BIF' => 'FBu',
        'BOB' => 'Bs',
        'BTN' => 'Nu.',
        'BWP' => 'P',
        'BYN' => 'Br',
        'CDF' => 'FC',
        'CRC' => '₡',
        'CZK' => 'Kč',
        'DJF' => 'Fdj',
        'DKK' => 'kr.',
        'DOP' => 'RD$',
        'ERN' => 'Nfk',
        'ETB' => 'Br',
        'FKP' => '£',
        'GEL' => '₾',
        'GHS' => 'GH₵',
        'GIP' => '£',
        'GMD' => 'D',
        'GNF' => 'FG',
        'GTQ' => 'Q',
        'HNL' => 'L',
        'HTG' => 'G',
        'HUF' => 'Ft',
        'IDR' => 'Rp',
        'ISK' => 'kr.',
        'KES' => 'Ksh',
        'KGS' => 'сом',
        'KHR' => '៛',
        'KMF' => 'CF',
        'KZT' => '₸',
        'LAK' => '₭',
        'LKR' => 'Rs',
        'MDL' => 'L',
        'MGA' => 'Ar',
        'MKD' => 'ден.',
        'MMK' => 'K',
        'MNT' => '₮',
        'MOP' => 'MOP$',
        'MUR' => 'Rs',
        'MVR' => 'Rf',
        'MWK' => 'MK',
        'MYR' => 'RM',
        'MZN' => 'MTn',
        'NGN' => '₦',
        'NIO' => 'C$',
        'NOK' => 'kr',
        'NPR' => 'Rs',
        'PAB' => 'B/.',
        'PEN' => 'S/',
        'PGK' => 'K',
        'PHP' => '₱',
        'PKR' => 'Rs',
        'PLN' => 'zł',
        'PYG' => 'Gs.',
        'RUB' => '₽',
        'RWF' => 'RF',
        'SCR' => 'SR',
        'SEK' => 'kr',
        'SHP' => '£',
        'SLE' => 'Le',
        'SOS' => 'S',
        'STN' => 'Db',
        'SZL' => 'E',
        'THB' => '฿',
        'TJS' => 'сом.',
        'TOP' => 'T$',
        'TRY' => '₺',
        'TZS' => 'TSh',
        'UAH' => '₴',
        'UGX' => 'USh',
        'UZS' => 'soʻm',
        'VES' => 'Bs.S',
        'VUV' => 'VT',
        'WST' => 'WS$',
        'ZAR' => 'R',
        'ZMW' => 'K',
    ];

    /** The symbol to write this currency with, or null to keep the library's own form. */
    public static function for(string $currency): ?string
    {
        return self::SYMBOLS[strtoupper(trim($currency))] ?? null;
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::SYMBOLS;
    }
}
