<?php

namespace App\Services\PaymentGateways;

// What a gateway hands back after starting a hosted checkout — enough for
// the frontend to send the customer to the gateway without needing to know
// per-gateway details (redirect vs POST-form navigation).
class CheckoutResult
{
    public function __construct(
        public readonly string $sessionId,
        // 'redirect' — browser navigates to $action (GET).
        // 'post_form' — browser must POST $fields to $action (Authorize.net Accept Hosted).
        public readonly string $navigation,
        public readonly string $action,
        public readonly array $fields = [],
    ) {}

    public function toArray(): array
    {
        return [
            'navigation' => $this->navigation,
            'action'     => $this->action,
            'fields'     => $this->fields,
        ];
    }
}
