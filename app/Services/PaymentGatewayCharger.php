<?php

namespace App\Services;

use App\Models\PaymentGateway;
use App\Support\FrontendUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Extracted verbatim from Admin\SubscriptionPaymentController's private gateway
// helpers so both the subscription-renewal flow and the module-purchase flow
// charge through the exact same, already-battle-tested mechanics.
class PaymentGatewayCharger
{
    public function platformGateway(string $name): PaymentGateway
    {
        $gw = PaymentGateway::where('name', $name)->where('is_active', true)->first();
        if (!$gw) {
            throw new \RuntimeException("Gateway '{$name}' is not configured or not active. Please contact support.");
        }
        return $gw;
    }

    public function chargeStripe(array $config, array $data): string
    {
        $currency = strtolower($data['currency']);
        // PKR and other zero-decimal currencies are passed as-is (not multiplied by 100)
        $zeroDecimal = ['bif','clp','djf','gnf','jpy','kmf','krw','mga','pyg','rwf','ugx','vnd','vuv','xaf','xof','xpf','pkr'];
        $amount = in_array($currency, $zeroDecimal)
            ? (int) $data['amount']
            : (int) round((float) $data['amount'] * 100);

        $res = Http::withBasicAuth($config['secret_key'] ?? '', '')
            ->asForm()
            ->post('https://api.stripe.com/v1/payment_intents', [
                'amount'                                     => $amount,
                'currency'                                   => $currency,
                'payment_method'                             => $data['payment_method_id'],
                'confirm'                                    => 'true',
                'return_url'                                 => FrontendUrl::to('payment'),
                'automatic_payment_methods[enabled]'         => 'true',
                'automatic_payment_methods[allow_redirects]' => 'never',
            ]);

        if ($res->failed()) {
            $msg = $res->json('error.message') ?? 'Stripe charge failed';
            throw new \RuntimeException($msg);
        }

        $status = $res->json('status');
        if (!in_array($status, ['succeeded', 'processing'])) {
            $msg = match ($status) {
                'requires_action'         => 'This card requires 3D Secure authentication. Please use a different card or contact your bank.',
                'requires_payment_method' => 'Card was declined. Please try a different card.',
                default                   => "Payment not completed (status: {$status}). Please try again.",
            };
            throw new \RuntimeException($msg);
        }

        return $res->json('id');
    }

    public function capturePaypal(array $config, string $orderId): string
    {
        $mode    = $config['mode'] ?? 'sandbox';
        $baseUrl = $mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';

        $tokenRes = Http::withBasicAuth($config['client_id'] ?? '', $config['client_secret'] ?? '')
            ->asForm()
            ->post("{$baseUrl}/v1/oauth2/token", ['grant_type' => 'client_credentials']);

        if ($tokenRes->failed()) {
            throw new \RuntimeException('PayPal authentication failed during payment capture');
        }

        $token = $tokenRes->json('access_token');

        // Verify order is in APPROVED state before capturing
        $orderRes = Http::withToken($token)->get("{$baseUrl}/v2/checkout/orders/{$orderId}");
        $orderStatus = $orderRes->json('status');
        if ($orderStatus !== 'APPROVED') {
            throw new \RuntimeException(
                $orderStatus === 'COMPLETED'
                    ? 'This payment has already been captured.'
                    : 'Payment was not approved in PayPal. Please complete the payment in the PayPal window and try again.'
            );
        }

        $captureRes = Http::withToken($token)
            ->withBody('{}', 'application/json')
            ->post("{$baseUrl}/v2/checkout/orders/{$orderId}/capture");

        if ($captureRes->failed()) {
            Log::error('PayPal capture failed', [
                'order_id' => $orderId,
                'status'   => $captureRes->status(),
                'body'     => $captureRes->json(),
            ]);
            $issue = $captureRes->json('details.0.issue') ?? '';
            $msg = match ($issue) {
                'ORDER_ALREADY_CAPTURED' => 'This payment has already been captured.',
                'INSTRUMENT_DECLINED'    => 'Payment was declined by PayPal. Please try a different payment method.',
                default                  => $captureRes->json('details.0.description')
                    ?? $captureRes->json('message')
                    ?? 'PayPal payment capture failed',
            };
            throw new \RuntimeException($msg);
        }

        $captureStatus = $captureRes->json('status');
        if ($captureStatus !== 'COMPLETED') {
            throw new \RuntimeException("PayPal payment was not completed (status: {$captureStatus})");
        }

        return $captureRes->json('id');
    }

    // Authorize.Net's JSON API prefixes every response body with a UTF-8 BOM
    // (\xEF\xBB\xBF), even though the Content-Type is application/json. PHP's
    // json_decode() is not BOM-tolerant, so $response->json() (which decodes
    // the raw body as-is) silently returns null for every field — every call
    // below then falls through to the generic "Card charge failed" message
    // regardless of what Authorize.Net actually returned. Stripe/PayPal don't
    // have this quirk, which is why only Authorize.Net appeared broken.
    private function decodeAuthorizeNetResponse(string $rawBody): array
    {
        $clean = ltrim($rawBody, "\xEF\xBB\xBF");
        return json_decode($clean, true) ?? [];
    }

    public function chargeAuthorizeNet(array $config, array $data): string
    {
        $mode     = $config['mode'] ?? 'sandbox';
        $endpoint = $mode === 'live'
            ? 'https://api.authorize.net/xml/v1/request.api'
            : 'https://apitest.authorize.net/xml/v1/request.api';

        if (empty($config['api_login_id']) || empty($config['transaction_key'])) {
            Log::warning('[authorize_net] missing credentials', ['mode' => $mode]);
            throw new \RuntimeException('Authorize.Net is not configured properly.');
        }

        if (config('app.debug')) {
            Log::debug('[authorize_net] charge attempt', ['mode' => $mode, 'amount' => $data['amount'] ?? null]);
        }

        $payload = [
            'createTransactionRequest' => [
                'merchantAuthentication' => [
                    'name'           => $config['api_login_id']   ?? '',
                    'transactionKey' => $config['transaction_key'] ?? '',
                ],
                'refId'              => 'sub-' . now()->timestamp,
                'transactionRequest' => [
                    'transactionType' => 'authCaptureTransaction',
                    'amount'          => number_format((float) $data['amount'], 2, '.', ''),
                    'payment'         => [
                        'opaqueData' => [
                            'dataDescriptor' => $data['opaque_data_descriptor'],
                            'dataValue'      => $data['opaque_data_value'],
                        ],
                    ],
                ],
            ],
        ];

        try {
            $res = Http::timeout(30)->acceptJson()->post($endpoint, $payload);
        } catch (\Throwable $e) {
            Log::error('[authorize_net] request exception', ['mode' => $mode, 'error' => $e->getMessage()]);
            throw new \RuntimeException('Authorize.Net request failed. Please try again.');
        }

        if ($res->failed()) {
            Log::error('[authorize_net] http failure', ['mode' => $mode, 'status' => $res->status()]);
            throw new \RuntimeException('Authorize.Net request failed. Please try again.');
        }

        $json = $this->decodeAuthorizeNetResponse($res->body());

        if (config('app.debug')) {
            Log::debug('[authorize_net] response code', [
                'result_code'    => data_get($json, 'messages.resultCode'),
                'response_code'  => data_get($json, 'transactionResponse.responseCode'),
            ]);
        }

        // Check the envelope-level resultCode first
        $resultCode = data_get($json, 'messages.resultCode');
        if ($resultCode === 'Error') {
            $text = data_get($json, 'messages.message.0.text') ?? 'Authorize.Net configuration error';
            Log::warning('[authorize_net] envelope error', ['mode' => $mode, 'message' => $text]);
            throw new \RuntimeException($text);
        }

        $responseCode = data_get($json, 'transactionResponse.responseCode');
        if ($responseCode !== '1') {
            $errorText = data_get($json, 'transactionResponse.errors.0.errorText')
                ?? data_get($json, 'transactionResponse.messages.0.description')
                ?? 'Card charge failed';
            Log::warning('[authorize_net] transaction declined', ['mode' => $mode, 'response_code' => $responseCode, 'message' => $errorText]);
            throw new \RuntimeException($errorText);
        }

        $transId = data_get($json, 'transactionResponse.transId');

        if (config('app.debug')) {
            Log::debug('[authorize_net] charge succeeded', ['mode' => $mode, 'transaction_id' => $transId]);
        }

        return $transId;
    }

    // Convenience dispatcher used by both the renewal and module-purchase flows.
    public function charge(string $gateway, array $config, array $data): string
    {
        return match ($gateway) {
            'stripe'        => $this->chargeStripe($config, $data),
            'paypal'        => $this->capturePaypal($config, $data['paypal_order_id']),
            'authorize_net' => $this->chargeAuthorizeNet($config, $data),
        };
    }
}
