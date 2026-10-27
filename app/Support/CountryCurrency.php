<?php

namespace App\Support;

final class CountryCurrency
{
    /**
     * @var array<string, string>
     */
    private const CURRENCIES = [
        'AD' => 'EUR', 'AE' => 'AED', 'AF' => 'AFN', 'AG' => 'XCD', 'AI' => 'XCD', 'AL' => 'ALL', 'AM' => 'AMD', 'AO' => 'AOA',
        'AR' => 'ARS', 'AS' => 'USD', 'AT' => 'EUR', 'AU' => 'AUD', 'AW' => 'AWG', 'AX' => 'EUR', 'AZ' => 'AZN', 'BA' => 'BAM',
        'BB' => 'BBD', 'BD' => 'BDT', 'BE' => 'EUR', 'BF' => 'XOF', 'BG' => 'EUR', 'BH' => 'BHD', 'BI' => 'BIF', 'BJ' => 'XOF',
        'BL' => 'EUR', 'BM' => 'BMD', 'BN' => 'BND', 'BO' => 'BOB', 'BQ' => 'USD', 'BR' => 'BRL', 'BS' => 'BSD', 'BT' => 'BTN',
        'BW' => 'BWP', 'BY' => 'BYN', 'BZ' => 'BZD', 'CA' => 'CAD', 'CC' => 'AUD', 'CD' => 'CDF', 'CF' => 'XAF', 'CG' => 'XAF',
        'CH' => 'CHF', 'CI' => 'XOF', 'CK' => 'NZD', 'CL' => 'CLP', 'CM' => 'XAF', 'CN' => 'CNY', 'CO' => 'COP', 'CR' => 'CRC',
        'CU' => 'CUP', 'CV' => 'CVE', 'CW' => 'ANG', 'CX' => 'AUD', 'CY' => 'EUR', 'CZ' => 'CZK', 'DE' => 'EUR', 'DJ' => 'DJF',
        'DK' => 'DKK', 'DM' => 'XCD', 'DO' => 'DOP', 'DZ' => 'DZD', 'EC' => 'USD', 'EE' => 'EUR', 'EG' => 'EGP', 'EH' => 'MAD',
        'ER' => 'ERN', 'ES' => 'EUR', 'ET' => 'ETB', 'FI' => 'EUR', 'FJ' => 'FJD', 'FK' => 'FKP', 'FM' => 'USD', 'FO' => 'DKK',
        'FR' => 'EUR', 'GA' => 'XAF', 'GB' => 'GBP', 'GD' => 'XCD', 'GE' => 'GEL', 'GF' => 'EUR', 'GG' => 'GBP', 'GH' => 'GHS',
        'GI' => 'GIP', 'GL' => 'DKK', 'GM' => 'GMD', 'GN' => 'GNF', 'GP' => 'EUR', 'GQ' => 'XAF', 'GR' => 'EUR', 'GT' => 'GTQ',
        'GU' => 'USD', 'GW' => 'XOF', 'GY' => 'GYD', 'HK' => 'HKD', 'HN' => 'HNL', 'HR' => 'EUR', 'HT' => 'HTG', 'HU' => 'HUF',
        'ID' => 'IDR', 'IE' => 'EUR', 'IL' => 'ILS', 'IM' => 'GBP', 'IN' => 'INR', 'IO' => 'USD', 'IQ' => 'IQD', 'IR' => 'IRR',
        'IS' => 'ISK', 'IT' => 'EUR', 'JE' => 'GBP', 'JM' => 'JMD', 'JO' => 'JOD', 'JP' => 'JPY', 'KE' => 'KES', 'KG' => 'KGS',
        'KH' => 'KHR', 'KI' => 'AUD', 'KM' => 'KMF', 'KN' => 'XCD', 'KP' => 'KPW', 'KR' => 'KRW', 'KW' => 'KWD', 'KY' => 'KYD',
        'KZ' => 'KZT', 'LA' => 'LAK', 'LB' => 'LBP', 'LC' => 'XCD', 'LI' => 'CHF', 'LK' => 'LKR', 'LR' => 'LRD', 'LS' => 'LSL',
        'LT' => 'EUR', 'LU' => 'EUR', 'LV' => 'EUR', 'LY' => 'LYD', 'MA' => 'MAD', 'MC' => 'EUR', 'MD' => 'MDL', 'ME' => 'EUR',
        'MF' => 'EUR', 'MG' => 'MGA', 'MH' => 'USD', 'MK' => 'MKD', 'ML' => 'XOF', 'MM' => 'MMK', 'MN' => 'MNT', 'MO' => 'MOP',
        'MP' => 'USD', 'MQ' => 'EUR', 'MR' => 'MRU', 'MS' => 'XCD', 'MT' => 'EUR', 'MU' => 'MUR', 'MV' => 'MVR', 'MW' => 'MWK',
        'MX' => 'MXN', 'MY' => 'MYR', 'MZ' => 'MZN', 'NA' => 'NAD', 'NC' => 'XPF', 'NE' => 'XOF', 'NF' => 'AUD', 'NG' => 'NGN',
        'NI' => 'NIO', 'NL' => 'EUR', 'NO' => 'NOK', 'NP' => 'NPR', 'NR' => 'AUD', 'NU' => 'NZD', 'NZ' => 'NZD', 'OM' => 'OMR',
        'PA' => 'USD', 'PE' => 'PEN', 'PF' => 'XPF', 'PG' => 'PGK', 'PH' => 'PHP', 'PK' => 'PKR', 'PL' => 'PLN', 'PM' => 'EUR',
        'PR' => 'USD', 'PS' => 'ILS', 'PT' => 'EUR', 'PW' => 'USD', 'PY' => 'PYG', 'QA' => 'QAR', 'RE' => 'EUR', 'RO' => 'RON',
        'RS' => 'RSD', 'RU' => 'RUB', 'RW' => 'RWF', 'SA' => 'SAR', 'SB' => 'SBD', 'SC' => 'SCR', 'SD' => 'SDG', 'SE' => 'SEK',
        'SG' => 'SGD', 'SH' => 'SHP', 'SI' => 'EUR', 'SJ' => 'NOK', 'SK' => 'EUR', 'SL' => 'SLE', 'SM' => 'EUR', 'SN' => 'XOF',
        'SO' => 'SOS', 'SR' => 'SRD', 'SS' => 'SSP', 'ST' => 'STN', 'SV' => 'USD', 'SX' => 'ANG', 'SY' => 'SYP', 'SZ' => 'SZL',
        'TC' => 'USD', 'TD' => 'XAF', 'TG' => 'XOF', 'TH' => 'THB', 'TJ' => 'TJS', 'TK' => 'NZD', 'TL' => 'USD', 'TM' => 'TMT',
        'TN' => 'TND', 'TO' => 'TOP', 'TR' => 'TRY', 'TT' => 'TTD', 'TV' => 'AUD', 'TW' => 'TWD', 'TZ' => 'TZS', 'UA' => 'UAH',
        'UG' => 'UGX', 'US' => 'USD', 'UY' => 'UYU', 'UZ' => 'UZS', 'VA' => 'EUR', 'VC' => 'XCD', 'VE' => 'VES', 'VG' => 'USD',
        'VI' => 'USD', 'VN' => 'VND', 'VU' => 'VUV', 'WF' => 'XPF', 'WS' => 'WST', 'XK' => 'EUR', 'YE' => 'YER', 'YT' => 'EUR',
        'ZA' => 'ZAR', 'ZM' => 'ZMW', 'ZW' => 'USD',
    ];

    /**
     * @var list<string>
     */
    private const AFRICAN = [
        'AO', 'BF', 'BI', 'BJ', 'BW', 'CD', 'CF', 'CG', 'CI', 'CM', 'CV', 'DJ', 'DZ', 'EG', 'EH', 'ER', 'ET', 'GA', 'GH', 'GM',
        'GN', 'GQ', 'GW', 'KE', 'KM', 'LR', 'LS', 'LY', 'MA', 'MG', 'ML', 'MR', 'MU', 'MW', 'MZ', 'NA', 'NE', 'NG', 'RW', 'SC',
        'SD', 'SL', 'SN', 'SO', 'SS', 'ST', 'SZ', 'TD', 'TG', 'TN', 'TZ', 'UG', 'ZA', 'ZM', 'ZW',
    ];

    /**
     * @var list<string>
     */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /**
     * @var list<string>
     */
    private const THREE_DECIMAL = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    public static function currency(?string $countryCode): string
    {
        $code = strtoupper(trim((string) $countryCode));

        return self::CURRENCIES[$code] ?? 'USD';
    }

    public static function isAfrican(?string $countryCode): bool
    {
        return in_array(strtoupper(trim((string) $countryCode)), self::AFRICAN, true);
    }

    public static function fractionDigits(string $currency): int
    {
        $currency = strtoupper($currency);
        if (in_array($currency, self::THREE_DECIMAL, true)) {
            return 3;
        }
        if (in_array($currency, self::ZERO_DECIMAL, true)) {
            return 0;
        }

        return 2;
    }

    public static function symbol(string $currency): string
    {
        return match (strtoupper($currency)) {
            'NGN' => '₦',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'GHS' => 'GH₵',
            'KES' => 'KSh',
            'ZAR' => 'R',
            default => strtoupper($currency).' ',
        };
    }
}
