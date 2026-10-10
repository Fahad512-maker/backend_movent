<?php

namespace App\Services\PaymentGateways;

// Normalized result of parsing+verifying an inbound gateway webhook request,
// so the shared webhook controller doesn't need to know per-gateway payload
// shapes.
class WebhookEvent
{
    public function __construct(
        public readonly bool $verified,
        public readonly ?string $eventId = null,
        // 'succeeded' | 'failed' | 'ignored' (event type we don't act on)
        public readonly string $outcome = 'ignored',
        // Correlates back to the pending Payment row.
        public readonly ?string $gatewaySessionId = null,
        // Fallback correlator when a gateway's webhook payload doesn't carry
        // the original session id back (see AuthorizeNetGateway).
        public readonly ?string $invoiceNumber = null,
        public readonly ?string $transactionId = null,
        public readonly ?string $failureReason = null,
    ) {}
}
