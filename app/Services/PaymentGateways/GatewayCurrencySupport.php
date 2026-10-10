<?php

namespace App\Services\PaymentGateways;

// Static reference of which settlement currencies each gateway actually
// supports, based on each provider's published docs. Used to decide, at
// charge time, whether an invoice's own currency can be sent to the gateway
// as-is or must first be converted (see InvoiceGatewayChargeService::resolveChargeAmount()).
class GatewayCurrencySupport
{
    // Stripe's supported presentment currencies —
    // https://stripe.com/docs/currencies
    // NOTE: PKR, BDT, and NPR are deliberately excluded — Pakistan,
    // Bangladesh, and Nepal are not Stripe-supported settlement countries,
    // and Stripe's own currency list does not include them as real
    // presentment currencies (a charge sent to Stripe in one of these fails
    // at Stripe's API, which is exactly the bug this whole feature exists
    // to route around).
    private const STRIPE = [
        'usd', 'aed', 'afn', 'all', 'amd', 'ang', 'aoa', 'ars', 'aud', 'awg', 'azn', 'bam', 'bbd', 'bif',
        'bmd', 'bnd', 'bob', 'brl', 'bsd', 'bwp', 'byn', 'bzd', 'cad', 'cdf', 'chf', 'clp', 'cny', 'cop', 'crc',
        'cve', 'czk', 'djf', 'dkk', 'dop', 'dzd', 'egp', 'etb', 'eur', 'fjd', 'fkp', 'gbp', 'gel', 'gip', 'gmd',
        'gnf', 'gtq', 'gyd', 'hkd', 'hnl', 'htg', 'huf', 'idr', 'ils', 'inr', 'isk', 'jmd', 'jpy', 'kes', 'kgs',
        'khr', 'kmf', 'krw', 'kyd', 'kzt', 'lak', 'lbp', 'lkr', 'lrd', 'lsl', 'mad', 'mdl', 'mga', 'mkd', 'mmk',
        'mnt', 'mop', 'mro', 'mur', 'mvr', 'mwk', 'mxn', 'myr', 'mzn', 'nad', 'ngn', 'nio', 'nok', 'nzd',
        'pab', 'pen', 'pgk', 'php', 'pln', 'pyg', 'qar', 'ron', 'rsd', 'rub', 'rwf', 'sar', 'sbd', 'scr',
        'sek', 'sgd', 'shp', 'sle', 'sos', 'srd', 'std', 'szl', 'thb', 'tjs', 'top', 'try', 'ttd', 'twd', 'tzs',
        'uah', 'ugx', 'uyu', 'uzs', 'vnd', 'vuv', 'wst', 'xaf', 'xcd', 'xof', 'xpf', 'yer', 'zar', 'zmw',
    ];

    // PayPal's supported transaction currencies —
    // https://developer.paypal.com/reference/currency-codes/
    private const PAYPAL = [
        'usd', 'aud', 'brl', 'cad', 'cny', 'czk', 'dkk', 'eur', 'hkd', 'huf', 'ils', 'jpy', 'myr', 'mxn', 'twd',
        'nzd', 'nok', 'php', 'pln', 'gbp', 'sgd', 'sek', 'chf', 'thb', 'try',
    ];

    // Authorize.net exposes no per-transaction currency at all — every
    // charge settles in the merchant account's own configured currency,
    // which for this app's gateway accounts today is always USD.
    private const AUTHORIZE_NET = ['usd'];

    public static function supports(string $gateway, ?string $currency): bool
    {
        if (!$currency) {
            return false;
        }

        $currency = strtolower($currency);

        return match ($gateway) {
            'stripe'        => in_array($currency, self::STRIPE, true),
            'paypal'        => in_array($currency, self::PAYPAL, true),
            'authorize_net' => in_array($currency, self::AUTHORIZE_NET, true),
            default         => false,
        };
    }
}
