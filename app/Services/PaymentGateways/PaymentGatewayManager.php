<?php

namespace App\Services\PaymentGateways;

use App\Models\Company;
use App\Models\CompanyPaymentGateway;

class PaymentGatewayManager
{
    private const DRIVERS = [
        'stripe'        => StripeGateway::class,
        'paypal'        => PayPalGateway::class,
        'authorize_net' => AuthorizeNetGateway::class,
    ];

    public static function driver(string $gateway): GatewayContract
    {
        if (!isset(self::DRIVERS[$gateway])) {
            throw new \InvalidArgumentException("Unknown payment gateway: {$gateway}");
        }

        return app(self::DRIVERS[$gateway]);
    }

    /**
     * The active gateway row a company should use for a given gateway key —
     * tenant-level first, falling back to a legacy per-company row. Null if
     * that gateway isn't activated for this company at all. With multiple
     * accounts of the same type this resolves to the type's default account
     * (or the first active one if none is flagged default) — callers that
     * know a specific account id should use accountById() instead.
     */
    public static function activeRowFor(Company $company, string $gateway): ?CompanyPaymentGateway
    {
        return CompanyPaymentGateway::defaultAccountForType($company, $gateway);
    }

    // Resolves a specific gateway account by its own id, scoped to this
    // company's tenant and required to be active — the account-aware
    // counterpart to activeRowFor(), used wherever an invoice (or its payer)
    // has chosen a specific account rather than just "the default of this
    // type". Returns null on any mismatch (wrong tenant, inactive, no such
    // row) so callers can give a generic "not available" error without
    // leaking which case it was.
    public static function accountById(Company $company, int $id): ?CompanyPaymentGateway
    {
        return CompanyPaymentGateway::resolveActiveGateways($company)
            ->firstWhere('id', $id);
    }
}
