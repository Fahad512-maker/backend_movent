<?php

namespace App\Services\PaymentGateways;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// PayPal Orders v2 (hosted approval page) — https://developer.paypal.com/docs/api/orders/v2/
class PayPalGateway implements GatewayContract
{
    private function baseUrl(array $config): string
    {
        return ($config['mode'] ?? 'sandbox') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    private function accessToken(array $config): string
    {
        $response = Http::asForm()
            ->withBasicAuth($config['client_id'] ?? '', $config['client_secret'] ?? '')
            ->post($this->baseUrl($config) . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        if (!$response->successful()) {
            throw new \RuntimeException('Could not authenticate with PayPal');
        }

        return $response->json('access_token');
    }

    public function createCheckout(Invoice $invoice, float $amount, array $config, string $returnUrl, string $cancelUrl): CheckoutResult
    {
        $token = $this->accessToken($config);

        $response = Http::withToken($token)
            ->post($this->baseUrl($config) . '/v2/checkout/orders', [
                'intent'         => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => (string) $invoice->id,
                    'invoice_id'   => $invoice->invoice_number . '-' . $invoice->id, // must be unique per PayPal account
                    'amount'       => [
                        'currency_code' => strtoupper($invoice->currency ?: 'USD'),
                        'value'         => number_format($amount, 2, '.', ''),
                    ],
                ]],
                'application_context' => [
                    'return_url'  => $returnUrl,
                    'cancel_url'  => $cancelUrl,
                    'user_action' => 'PAY_NOW',
                ],
            ]);

        if (!$response->successful()) {
            Log::warning('PayPal order creation failed', ['status' => $response->status(), 'invoice_id' => $invoice->id]);
            throw new \RuntimeException($response->json('message') ?? 'PayPal checkout could not be started');
        }

        $order   = $response->json();
        $approve = collect($order['links'] ?? [])->firstWhere('rel', 'approve');

        if (!$approve) {
            throw new \RuntimeException('PayPal did not return an approval link');
        }

        return new CheckoutResult(
            sessionId: $order['id'],
            navigation: 'redirect',
            action: $approve['href'],
        );
    }

    /**
     * Finalize an approved order — called from the return-callback route
     * (immediate UX) AND, idempotently, from the webhook handler as the
     * authoritative confirmation. Safe to call twice: PayPal itself rejects
     * capturing an already-captured order, which we treat as already-succeeded.
     */
    public function captureOrder(string $orderId, array $config): array
    {
        $token    = $this->accessToken($config);
        $response = Http::withToken($token)->post($this->baseUrl($config) . "/v2/checkout/orders/{$orderId}/capture");

        $body = $response->json();

        if ($response->successful() && ($body['status'] ?? '') === 'COMPLETED') {
            $capture = $body['purchase_units'][0]['payments']['captures'][0] ?? [];
            return ['success' => true, 'transaction_id' => $capture['id'] ?? $orderId];
        }

        // ORDER_ALREADY_CAPTURED — the webhook/return-callback race already finished this.
        $issue = $body['details'][0]['issue'] ?? '';
        if ($issue === 'ORDER_ALREADY_CAPTURED') {
            return ['success' => true, 'transaction_id' => $orderId, 'already_captured' => true];
        }

        Log::warning('PayPal order capture failed', ['status' => $response->status(), 'order_id' => $orderId]);

        return ['success' => false];
    }

    public function parseWebhook(Request $request, array $config): WebhookEvent
    {
        if (!$this->verifySignature($request, $config)) {
            return new WebhookEvent(verified: false);
        }

        $event    = $request->json()->all();
        $type     = $event['event_type'] ?? '';
        $resource = $event['resource'] ?? [];

        if (in_array($type, ['CHECKOUT.ORDER.APPROVED', 'PAYMENT.CAPTURE.COMPLETED'])) {
            // For an order-approved event the resource IS the order; for a
            // capture-completed event we have to walk back up to the order id.
            $orderId = $resource['id'] ?? ($resource['supplementary_data']['related_ids']['order_id'] ?? null);

            return new WebhookEvent(
                verified: true,
                eventId: $event['id'] ?? null,
                outcome: 'succeeded',
                gatewaySessionId: $orderId,
                transactionId: $resource['id'] ?? null,
            );
        }

        if (in_array($type, ['CHECKOUT.ORDER.VOIDED', 'PAYMENT.CAPTURE.DENIED'])) {
            return new WebhookEvent(
                verified: true,
                eventId: $event['id'] ?? null,
                outcome: 'failed',
                gatewaySessionId: $resource['id'] ?? null,
                failureReason: $type,
            );
        }

        return new WebhookEvent(verified: true, eventId: $event['id'] ?? null, outcome: 'ignored');
    }

    // PayPal's officially recommended server-side verification — round-trips
    // the raw event + headers back to PayPal rather than validating a local
    // certificate chain ourselves.
    private function verifySignature(Request $request, array $config): bool
    {
        $webhookId = $config['webhook_id'] ?? '';
        if (!$webhookId) return false;

        try {
            $token = $this->accessToken($config);
        } catch (\Throwable) {
            return false;
        }

        $response = Http::withToken($token)->post($this->baseUrl($config) . '/v1/notifications/verify-webhook-signature', [
            'auth_algo'         => $request->header('PAYPAL-AUTH-ALGO'),
            'cert_url'          => $request->header('PAYPAL-CERT-URL'),
            'transmission_id'   => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig'  => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
            'webhook_id'        => $webhookId,
            'webhook_event'     => $request->json()->all(),
        ]);

        return $response->successful() && ($response->json('verification_status') === 'SUCCESS');
    }
}
