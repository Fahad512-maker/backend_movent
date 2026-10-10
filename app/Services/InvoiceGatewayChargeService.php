<?php

namespace App\Services;

use App\Models\CompanyPaymentGateway;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentGateways\GatewayCurrencySupport;
use App\Services\PaymentGateways\PaymentGatewayManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Inline (non-redirect) invoice payment — mirrors the exact pattern already
// used by Api\Admin\SubscriptionPaymentController for signup/module-purchase
// payments (Stripe Elements / PayPal Buttons / Authorize.net Accept.js, all
// converging on a synchronous charge call, no webhook involved), except this
// resolves gateway credentials from the invoice's own company
// (CompanyPaymentGateway) rather than the platform-level gateway settings,
// and always computes the charge amount from the invoice record itself —
// never from the request. Shared by both Api\PublicInvoiceController (token
// link, no auth) and Api\Client\InvoiceController (authenticated client
// portal) so the actual charge/finalize logic exists in exactly one place.
class InvoiceGatewayChargeService
{
    // Resolves which specific gateway account a charge/init call should use.
    // If the caller (frontend) named a specific account, that account must
    // belong to this invoice's tenant, be active, match the requested
    // gateway type, and — if the invoice has an explicit allow-list of its
    // own — be one of the invoice's selected accounts. If no account id was
    // given (older callers, or invoices with no explicit selection), falls
    // back to the tenant's default account of that type, exactly like
    // before this feature existed.
    private static function gatewayRow(Invoice $invoice, string $gateway, ?int $companyGatewayId = null): CompanyPaymentGateway
    {
        if (!array_key_exists($gateway, CompanyPaymentGateway::GATEWAYS)) {
            throw new \RuntimeException('Unknown payment gateway.');
        }

        if ($companyGatewayId !== null) {
            $row = PaymentGatewayManager::accountById($invoice->company, $companyGatewayId);
            if (!$row || $row->gateway !== $gateway) {
                throw new \RuntimeException('This payment gateway is not available for this invoice.');
            }

            $allowed = $invoice->paymentGatewayAccounts;
            if ($allowed->isNotEmpty() && !$allowed->contains('id', $row->id)) {
                throw new \RuntimeException('This payment gateway is not available for this invoice.');
            }

            return $row;
        }

        $row = PaymentGatewayManager::activeRowFor($invoice->company, $gateway);
        if (!$row) {
            throw new \RuntimeException('This payment gateway is not available for this invoice.');
        }

        return $row;
    }

    private static function outstanding(Invoice $invoice): float
    {
        return round((float) $invoice->total_amount - (float) $invoice->paid_amount, 2);
    }

    // Decides what currency/amount a gateway call should actually use for
    // this invoice: unchanged if the gateway already supports the invoice's
    // own currency (exchange_rate stays null — no conversion happened),
    // otherwise converted to USD via CurrencyConversionService. The
    // original figures are always preserved separately so callers can keep
    // Payment.amount/currency (and therefore invoice balance math) in the
    // invoice's original currency regardless of what was actually charged.
    private static function resolveChargeAmount(Invoice $invoice, string $gateway, float $originalAmount): array
    {
        $originalCurrency = $invoice->currency ?: 'USD';

        if (GatewayCurrencySupport::supports($gateway, $originalCurrency)) {
            return [
                'currency'          => $originalCurrency,
                'amount'            => $originalAmount,
                'original_currency' => $originalCurrency,
                'original_amount'   => $originalAmount,
                'exchange_rate'     => null,
            ];
        }

        $converted = CurrencyConversionService::convert($originalAmount, $originalCurrency, 'USD');

        return [
            'currency'          => 'USD',
            'amount'            => $converted['amount'],
            'original_currency' => $originalCurrency,
            'original_amount'   => $originalAmount,
            'exchange_rate'     => $converted['rate'],
        ];
    }

    // GET .../gateways/{gateway}/init — public-safe credentials only, mirrors
    // Api\Admin\SubscriptionPaymentController::init()'s exact field selection
    // per gateway, just sourced from the company's own gateway config.
    public static function publicInit(Invoice $invoice, string $gateway, ?int $companyGatewayId = null): array
    {
        $config = self::gatewayRow($invoice, $gateway, $companyGatewayId)->config ?? [];
        $mode   = $config['mode'] ?? 'sandbox';

        return match ($gateway) {
            'stripe' => [
                'publishable_key' => $config['publishable_key'] ?? '',
                'mode'            => $mode,
            ],
            'paypal' => [
                'client_id' => $config['client_id'] ?? '',
                'mode'      => $mode,
            ],
            'authorize_net' => [
                'api_login_id' => $config['api_login_id'] ?? '',
                'client_key'   => $config['client_key']   ?? '',
                'mode'         => $mode,
            ],
        };
    }

    // POST .../gateways/paypal/create-order — mirrors
    // SubscriptionPaymentController::createPaypalOrder()'s exact PayPal v2
    // order-create call, except the amount is always the invoice's own
    // outstanding balance, never accepted from the request.
    public static function createPaypalOrder(Invoice $invoice, ?int $companyGatewayId = null): array
    {
        $config = self::gatewayRow($invoice, 'paypal', $companyGatewayId)->config ?? [];
        $mode   = $config['mode'] ?? 'sandbox';
        $amount = self::outstanding($invoice);

        if ($amount <= 0) {
            throw new \RuntimeException('Invoice is already paid.');
        }

        $charge = self::resolveChargeAmount($invoice, 'paypal', $amount);

        $baseUrl = $mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';

        $tokenRes = Http::withBasicAuth($config['client_id'] ?? '', $config['client_secret'] ?? '')
            ->asForm()
            ->post("{$baseUrl}/v1/oauth2/token", ['grant_type' => 'client_credentials']);

        if ($tokenRes->failed()) {
            throw new \RuntimeException('PayPal authentication failed. Please try again or choose another payment method.');
        }

        $orderRes = Http::withToken($tokenRes->json('access_token'))
            ->post("{$baseUrl}/v2/checkout/orders", [
                'intent'         => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => (string) $invoice->id,
                    'invoice_id'   => $invoice->invoice_number . '-' . $invoice->id,
                    'amount'       => [
                        'currency_code' => strtoupper($charge['currency']),
                        'value'         => number_format($charge['amount'], 2, '.', ''),
                    ],
                ]],
            ]);

        if ($orderRes->failed()) {
            Log::error('Invoice PayPal createOrder failed', [
                'invoice_id' => $invoice->id, 'status' => $orderRes->status(), 'body' => $orderRes->json(),
            ]);
            throw new \RuntimeException('Could not start PayPal payment. Please try again or choose another payment method.');
        }

        return ['order_id' => $orderRes->json('id')];
    }

    // POST .../gateways/{gateway}/charge — create a pending Payment, charge
    // it synchronously via the existing (already company/platform-agnostic)
    // PaymentGatewayCharger, then finalize through the existing, unmodified
    // InvoicePaymentService pipeline. Returns a small summary for the
    // controller to hand back to the frontend.
    public static function charge(Invoice $invoice, string $gateway, array $data, string $channel, ?int $recordedBy = null, ?string $ip = null, ?int $companyGatewayId = null): array
    {
        if (in_array($invoice->status, ['paid', 'cancelled'])) {
            throw new \RuntimeException($invoice->status === 'paid' ? 'Invoice is already paid.' : 'This invoice is no longer active.');
        }

        $gatewayRow = self::gatewayRow($invoice, $gateway, $companyGatewayId);
        $amount     = self::outstanding($invoice);

        if ($amount <= 0) {
            throw new \RuntimeException('Invoice is already paid.');
        }

        if ($invoice->payments()->where('status', 'pending')->exists()) {
            throw new \RuntimeException('A payment is already being processed for this invoice. Please wait a moment and try again.');
        }

        $charge = self::resolveChargeAmount($invoice, $gateway, $amount);

        $payment = Payment::create([
            'invoice_id'          => $invoice->id,
            'recorded_by'         => $recordedBy,
            // amount/currency stay the invoice's ORIGINAL figures — this is
            // what InvoicePaymentService::applyToInvoice() sums against the
            // invoice balance. converted_* is audit data only.
            'amount'              => $amount,
            'currency'            => $charge['original_currency'],
            'converted_amount'    => $charge['exchange_rate'] !== null ? $charge['amount'] : null,
            'converted_currency'  => $charge['exchange_rate'] !== null ? $charge['currency'] : null,
            'exchange_rate'       => $charge['exchange_rate'],
            'method'              => 'gateway',
            'gateway'             => $gateway,
            'company_gateway_id'  => $gatewayRow->id,
            'gateway_mode'        => $gatewayRow->config['mode'] ?? 'sandbox',
            'status'              => 'pending',
            'payment_date'        => now()->toDateString(),
        ]);

        try {
            $gatewayRef = (new PaymentGatewayCharger)->charge($gateway, $gatewayRow->config, $data + [
                'amount'   => $charge['amount'],
                'currency' => $charge['currency'],
            ]);
        } catch (\Throwable $e) {
            // Deliberately \Throwable, not just \RuntimeException — an
            // online gateway payment must never sit stuck at 'pending'
            // waiting on a manual admin approval that this flow was never
            // designed to have. PaymentGatewayCharger normally throws
            // RuntimeException for a real gateway-reported decline, but a
            // connection-level failure reaching the gateway's API (e.g.
            // Laravel's Http::-facade ConnectionException on a timeout/SSL
            // error, which does NOT extend RuntimeException) previously
            // propagated uncaught, leaving the Payment row created above
            // permanently pending with no gateway_session_id — exactly the
            // "awaiting confirmation" limbo this synchronous, no-webhook
            // flow was never supposed to produce.
            InvoicePaymentService::finalizeGatewayFailure($payment, $e->getMessage());
            throw new \RuntimeException('Payment failed. Please try again or choose another payment method.');
        }

        InvoicePaymentService::finalizeGatewaySuccess($payment, $gatewayRef);
        InvoicePaymentService::logPayment($invoice->fresh(), $payment->fresh(), $channel, $ip);

        $invoice->refresh();
        $payment->refresh();

        return [
            'payment_id'     => $payment->id,
            'gateway_ref'    => $gatewayRef,
            'receipt_number' => $payment->receipt_number,
            'invoice_status' => $invoice->status,
            'amount'         => $amount,
        ];
    }
}
