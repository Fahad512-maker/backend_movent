<?php

namespace App\Services\PaymentGateways;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Authorize.net Accept Hosted (hosted payment form) —
// https://developer.authorize.net/api/reference/features/accept_hosted.html
//
// Unlike Stripe/PayPal there is no GET-redirect URL: the frontend must POST
// the one-time token to Authorize.net's hosted-page endpoint itself. There is
// also no pre-known order/session id we can hand the customer that survives
// into the webhook payload, so webhook correlation falls back to the invoice
// number (see parseWebhook()) rather than an exact session-id match.
class AuthorizeNetGateway implements GatewayContract
{
    private function apiBaseUrl(array $config): string
    {
        return ($config['mode'] ?? 'sandbox') === 'live'
            ? 'https://api.authorize.net'
            : 'https://apitest.authorize.net';
    }

    private function hostedFormUrl(array $config): string
    {
        return ($config['mode'] ?? 'sandbox') === 'live'
            ? 'https://accept.authorize.net/payment/payment'
            : 'https://accepttest.authorize.net/payment/payment';
    }

    private function auth(array $config): array
    {
        return [
            'name'           => $config['api_login_id'] ?? '',
            'transactionKey' => $config['transaction_key'] ?? '',
        ];
    }

    // Authorize.net's JSON API sometimes prefixes responses with a UTF-8 BOM.
    private function decode(string $body): array
    {
        return json_decode(ltrim($body, "\xEF\xBB\xBF"), true) ?? [];
    }

    public function createCheckout(Invoice $invoice, float $amount, array $config, string $returnUrl, string $cancelUrl): CheckoutResult
    {
        $response = Http::post($this->apiBaseUrl($config) . '/xml/v1/request.api', [
            'getHostedPaymentPageRequest' => [
                'merchantAuthentication' => $this->auth($config),
                'transactionRequest'     => [
                    'transactionType' => 'authCaptureTransaction',
                    'amount'          => number_format($amount, 2, '.', ''),
                    'order'           => [
                        'invoiceNumber' => substr($invoice->invoice_number, 0, 20),
                        'description'   => "Invoice {$invoice->invoice_number}",
                    ],
                ],
                'hostedPaymentSettings' => [
                    'setting' => [
                        ['settingName' => 'hostedPaymentReturnOptions', 'settingValue' => json_encode(['showReceipt' => false, 'url' => $returnUrl, 'urlText' => 'Continue', 'cancelUrl' => $cancelUrl])],
                        ['settingName' => 'hostedPaymentButtonOptions', 'settingValue' => json_encode(['text' => 'Pay Now'])],
                    ],
                ],
            ],
        ]);

        $data = $this->decode($response->body());

        if (($data['messages']['resultCode'] ?? '') !== 'Ok' || empty($data['token'])) {
            Log::warning('Authorize.net hosted page token request failed', ['invoice_id' => $invoice->id, 'response' => $data['messages'] ?? null]);
            throw new \RuntimeException($data['messages']['message'][0]['text'] ?? 'Authorize.net checkout could not be started');
        }

        return new CheckoutResult(
            sessionId: $data['token'],
            navigation: 'post_form',
            action: $this->hostedFormUrl($config),
            fields: ['token' => $data['token']],
        );
    }

    /**
     * Look up a transaction's authoritative status/amount/invoice number —
     * webhook payloads only carry the transaction id, never trust the
     * amount/status a webhook claims without confirming it via this call.
     */
    public function getTransactionDetails(string $transactionId, array $config): array
    {
        $response = Http::post($this->apiBaseUrl($config) . '/xml/v1/request.api', [
            'getTransactionDetailsRequest' => [
                'merchantAuthentication' => $this->auth($config),
                'transId'                => $transactionId,
            ],
        ]);

        return $this->decode($response->body());
    }

    public function parseWebhook(Request $request, array $config): WebhookEvent
    {
        if (!$this->verifySignature($request, $config)) {
            return new WebhookEvent(verified: false);
        }

        $event      = $request->json()->all();
        $eventType  = $event['eventType'] ?? '';
        $transId    = $event['payload']['id'] ?? null;

        if (!$transId) {
            return new WebhookEvent(verified: true, eventId: $event['notificationId'] ?? null, outcome: 'ignored');
        }

        if ($eventType === 'net.authorize.payment.authcapture.created') {
            $details     = $this->getTransactionDetails($transId, $config);
            $transaction = $details['transaction'] ?? [];
            $status      = $transaction['transactionStatus'] ?? '';

            if (!in_array($status, ['capturedPendingSettlement', 'settledSuccessfully'])) {
                return new WebhookEvent(verified: true, eventId: $event['notificationId'] ?? null, outcome: 'ignored');
            }

            return new WebhookEvent(
                verified: true,
                eventId: $event['notificationId'] ?? null,
                outcome: 'succeeded',
                invoiceNumber: $transaction['order']['invoiceNumber'] ?? null,
                transactionId: $transId,
            );
        }

        if (in_array($eventType, ['net.authorize.payment.authcapture.declined', 'net.authorize.payment.void.created'])) {
            $details     = $this->getTransactionDetails($transId, $config);
            $transaction = $details['transaction'] ?? [];

            return new WebhookEvent(
                verified: true,
                eventId: $event['notificationId'] ?? null,
                outcome: 'failed',
                invoiceNumber: $transaction['order']['invoiceNumber'] ?? null,
                transactionId: $transId,
                failureReason: $eventType,
            );
        }

        return new WebhookEvent(verified: true, eventId: $event['notificationId'] ?? null, outcome: 'ignored');
    }

    private function verifySignature(Request $request, array $config): bool
    {
        $signatureKey = $config['signature_key'] ?? '';
        $header       = $request->header('X-ANET-Signature', '');
        if (!$signatureKey || !$header) return false;

        $hash = str_starts_with(strtolower($header), 'sha512=') ? substr($header, 7) : $header;

        $expected = hash_hmac('sha512', $request->getContent(), hex2bin($signatureKey) ?: '');

        return hash_equals(strtolower($expected), strtolower($hash));
    }
}
