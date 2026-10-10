<?php

namespace App\Services\PaymentGateways;

use App\Models\Invoice;
use Illuminate\Http\Request;

interface GatewayContract
{
    /**
     * Start a hosted checkout for the given invoice/amount using this
     * tenant's own gateway credentials ($config, already decrypted).
     */
    public function createCheckout(Invoice $invoice, float $amount, array $config, string $returnUrl, string $cancelUrl): CheckoutResult;

    /**
     * Verify and normalize an inbound webhook request. Must not throw on a
     * bad signature — return an unverified WebhookEvent instead, so the
     * controller can respond and log without leaking internals.
     */
    public function parseWebhook(Request $request, array $config): WebhookEvent;
}
