<?php

namespace App\Http\Controllers\Api\Client;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Client\Concerns\ScopesToPermittedClient;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CompanyPaymentGateway;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\ClientPaymentMethodService;
use App\Services\CurrencyConversionService;
use App\Services\InvoiceGatewayChargeService;
use App\Services\InvoicePaymentService;
use App\Services\PaymentGateways\GatewayCurrencySupport;
use App\Services\PaymentGateways\PaymentGatewayManager;
use App\Support\FrontendUrl;
use App\Support\PublicFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InvoiceController extends Controller
{
    use ScopesToPermittedClient;

    /**
     * Return every client ID linked to the logged-in user that currently
     * has the 'invoices' portal module enabled. A single user account can
     * be a client in multiple companies.
     */
    private function clientIds(Request $request): array
    {
        return $this->permittedClientIds($request, 'invoices');
    }

    public function index(Request $request): JsonResponse
    {
        $query = Invoice::whereIn('client_id', $this->clientIds($request))
            ->whereNotIn('status', ['draft', 'cancelled']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where('invoice_number', 'like', '%' . $request->search . '%');
        }

        $invoices = $query->orderByDesc('created_at')
            ->get(['id', 'invoice_number', 'total_amount', 'paid_amount', 'currency', 'status', 'due_date', 'created_at']);

        return ApiResponse::success($invoices);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::where('id', $id)
            ->whereIn('client_id', $this->clientIds($request))
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->with(['items', 'client:id,name,email,company_name,address', 'brand'])
            ->firstOrFail();

        // Who issued this, as it should read to the client: the brand for a
        // Brand Invoice, the company otherwise. Same block and same key names
        // the staff/admin detail screen gets (Api\Admin\InvoiceController::
        // brandingPayload()), so the portal can't show the company's name on
        // an invoice that went out under a brand.
        $profile = $invoice->brandingProfile();

        $data = $invoice->toArray();
        $data['branding'] = [
            'invoice_type' => $profile['invoice_type'] ?? 'company',
            'name'         => $profile['name'] ?? null,
            'logo_url'     => PublicFile::url($profile['logo_path'] ?? null),
            'email'        => $profile['email'] ?? null,
            'phone'        => $profile['phone'] ?? null,
            'website'      => $profile['website'] ?? null,
            'address'      => $profile['address'] ?? null,
            'country'      => $profile['country'] ?? null,
            'brand'        => $profile['brand'] ?? null,
        ];

        return ApiResponse::success($data);
    }

    public function downloadPdf(Request $request, int $id): JsonResponse
    {
        Invoice::where('id', $id)
            ->whereIn('client_id', $this->clientIds($request))
            ->firstOrFail();

        return ApiResponse::success(['url' => "/api/client/invoices/{$id}/pdf-file"]);
    }

    public function getGateways(Request $request, int $id): JsonResponse
    {
        $clientIds = $this->clientIds($request);

        $invoice = Invoice::where('id', $id)
            ->whereIn('client_id', $clientIds)
            ->whereIn('status', ['sent', 'overdue', 'partially_paid'])
            ->firstOrFail();

        // Use the client that owns this invoice to get the correct company
        $client  = Client::where('id', $invoice->client_id)->firstOrFail();
        $company = $client->company;
        // Brand Invoice → the brand's identity, else the company's. Same
        // resolution the share link and the emailed copy use.
        $profile = $invoice->brandingProfile();

        [$activeGateways, $gatewayUnavailableMessage] = $this->resolveInvoiceGateways($invoice);

        $bank = null;
        if ($profile['bank_name'] || $profile['bank_account_number']) {
            $bank = array_filter([
                'bank_name'      => $profile['bank_name'],
                'account_name'   => $profile['bank_account_name'],
                'account_number' => $profile['bank_account_number'],
                'iban'           => $profile['bank_iban'],
                'swift'          => $profile['bank_swift'],
            ]);
        }

        return ApiResponse::success([
            'invoice' => [
                'id'             => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'total_amount'   => $invoice->total_amount,
                'paid_amount'    => $invoice->paid_amount,
                'currency'       => $invoice->currency,
                // Same progress block as everywhere else. This screen has
                // always charged the full outstanding balance, so there is no
                // partial-payment flag to send — the tenant's Payment Policy
                // can only ever make it stricter, never looser.
                'payment_progress' => $invoice->paymentProgress(),
                // What the payment is FOR — the client is about to hand over
                // money on this screen and the line items aren't shown here.
                'invoice_purpose' => $invoice->invoice_purpose,
            ],
            // Who the client is paying, as it should read to them: the brand
            // for a Brand Invoice, the company otherwise.
            // logo goes out as a URL, not the raw storage path it used to be
            // — the portal has no way to resolve a path into something an
            // <img> can load. Same fix as Api\PublicInvoiceController.
            'issuer' => [
                'name'         => $profile['name'] ?? null,
                'logo'         => PublicFile::url($profile['logo_path'] ?? null),
                'email'        => $profile['email'] ?? null,
                'phone'        => $profile['phone'] ?? null,
                'website'      => $profile['website'] ?? null,
                'address'      => $profile['address'] ?? null,
                'country'      => $profile['country'] ?? null,
                'invoice_type' => $profile['invoice_type'] ?? 'company',
                'brand'        => $profile['brand'] ?? null,
            ],
            'gateways'                     => $activeGateways,
            'gateway_unavailable_message'  => $gatewayUnavailableMessage,
            'bank'                         => $bank ?: null,
        ]);
    }

    // Mirrors Api\PublicInvoiceController::resolveInvoiceGateways() — the
    // invoice's own explicit account selection (filtered to still-active
    // accounts) if one was ever made, else the tenant's per-type defaults.
    private function resolveInvoiceGateways(Invoice $invoice): array
    {
        $allowed = $invoice->paymentGatewayAccounts;

        if ($allowed->isEmpty()) {
            $rows = CompanyPaymentGateway::resolveActiveGateways($invoice->client->company)
                ->groupBy('gateway')
                ->map(fn($rows) => $rows->firstWhere('is_default', true) ?? $rows->first());

            return [$this->formatGatewayAccounts($rows, $invoice), null];
        }

        $active = $allowed->where('is_active', true);

        if ($active->isEmpty()) {
            return [[], 'No active payment gateway is available for this invoice. Please contact support.'];
        }

        return [$this->formatGatewayAccounts($active, $invoice), null];
    }

    private function formatGatewayAccounts($rows, Invoice $invoice): array
    {
        $outstanding = round((float) $invoice->total_amount - (float) $invoice->paid_amount, 2);

        return $rows->map(function ($g) use ($invoice, $outstanding) {
            $supportsInvoiceCurrency = GatewayCurrencySupport::supports($g->gateway, $invoice->currency);
            $conversionPreview       = null;

            if (!$supportsInvoiceCurrency && $outstanding > 0) {
                try {
                    $converted = CurrencyConversionService::convert($outstanding, $invoice->currency ?: 'USD', 'USD');
                    $conversionPreview = [
                        'amount'   => $converted['amount'],
                        'currency' => 'USD',
                        'rate'     => $converted['rate'],
                    ];
                } catch (\Throwable $e) {
                    Log::warning('Conversion preview failed for client invoice gateway', [
                        'invoice_id' => $invoice->id, 'gateway' => $g->gateway, 'error' => $e->getMessage(),
                    ]);
                }
            }

            return [
                'id'                        => $g->id,
                'type'                      => $g->gateway,
                'label'                     => $g->label ?: (CompanyPaymentGateway::GATEWAYS[$g->gateway] ?? $g->gateway),
                'supports_invoice_currency' => $supportsInvoiceCurrency,
                'conversion_preview'        => $conversionPreview,
            ];
        })->values()->toArray();
    }

    public function payRequest(Request $request, int $id): JsonResponse
    {
        $onlineGatewayKeys = array_keys(CompanyPaymentGateway::GATEWAYS);
        $allowedMethods    = array_merge(['bank_transfer', 'cash', 'cheque', 'other'], $onlineGatewayKeys);

        $request->validate([
            'method'     => 'required|in:' . implode(',', $allowedMethods),
            'gateway_ref'=> 'nullable|string|max:255',
            'notes'      => 'nullable|string|max:1000',
        ]);

        $invoice = Invoice::where('id', $id)
            ->whereIn('client_id', $this->clientIds($request))
            ->whereIn('status', ['sent', 'overdue', 'partially_paid'])
            ->firstOrFail();

        // Prevent duplicate submissions — same rule as the public link flow
        // (Api\PublicInvoiceController::pay()): one unresolved claim at a time.
        if ($invoice->payments()->where('status', 'pending')->exists()) {
            return ApiResponse::error('A payment is already awaiting confirmation for this invoice.', 422);
        }

        $isGatewayKey = in_array($request->method, $onlineGatewayKeys);

        // Map frontend method to DB enum: gateway/bank_transfer/cash/card/cheque
        $dbMethod = $isGatewayKey ? 'gateway' : ($request->method === 'other' ? 'cash' : $request->method);
        $gateway  = $isGatewayKey ? $request->method : null;
        $amount   = round((float) $invoice->total_amount - (float) $invoice->paid_amount, 2);

        // No receipt yet — issued once the admin confirms this claim (see
        // Admin\PaymentController::confirm()), matching the public link flow.
        $payment = Payment::create([
            'invoice_id'     => $invoice->id,
            'recorded_by'    => $request->user()->id,
            'amount'         => $amount,
            // Always the invoice's own currency — this claim's amount is
            // computed straight from the invoice's own outstanding balance,
            // never a separately-entered figure. Stamping it (not leaving it
            // null) is what lets reporting/aggregation code group payments
            // by currency correctly instead of assuming one.
            'currency'       => $invoice->currency,
            'method'         => $dbMethod,
            'gateway'        => $gateway,
            'gateway_ref'    => $request->gateway_ref,
            'status'         => 'pending',
            'payment_date'   => now()->toDateString(),
            'notes'          => $request->notes ?? 'Payment submitted via client portal',
        ]);

        // Invoice status/paid_amount is untouched here — it only changes once
        // the company admin verifies and confirms this payment claim.

        InvoicePaymentService::logPayment($invoice, $payment, 'client_portal', $request->ip());
        InvoicePaymentService::notifyAdmin($invoice, $payment);

        return ApiResponse::success([
            'amount'         => $amount,
            'invoice_status' => $invoice->status,
        ], 'Payment request submitted. Awaiting verification.');
    }

    // POST /client/invoices/{id}/gateways/{gateway}/initiate
    // Real hosted checkout (Stripe/PayPal/Authorize.net) from the logged-in
    // client portal — mirrors Api\PublicInvoiceController::initiate(); only
    // the gateway's webhook (or, for immediate UX, the return-callback)
    // finalizes the payment, both idempotently.
    public function initiateGatewayCheckout(Request $request, int $id, string $gateway): JsonResponse
    {
        if (!array_key_exists($gateway, CompanyPaymentGateway::GATEWAYS)) {
            return ApiResponse::error('Unknown payment gateway', 422);
        }

        $invoice = Invoice::where('id', $id)
            ->whereIn('client_id', $this->clientIds($request))
            ->whereIn('status', ['sent', 'overdue', 'partially_paid'])
            ->with('company')
            ->firstOrFail();

        $existingPending = $invoice->payments()->where('status', 'pending')->latest('id')->first();
        if ($existingPending && $existingPending->gateway !== $gateway) {
            return ApiResponse::error('A payment is already awaiting confirmation for this invoice.', 422);
        }

        $companyGatewayId = $request->input('company_gateway_id') ? (int) $request->input('company_gateway_id') : null;
        $gatewayRow = $this->resolveGatewayForInvoice($invoice, $gateway, $companyGatewayId);
        if (!$gatewayRow) {
            return ApiResponse::error('This payment gateway is not available for this invoice.', 422);
        }

        $amount = round((float) $invoice->total_amount - (float) $invoice->paid_amount, 2);
        if ($amount <= 0) {
            return ApiResponse::error('Invoice is already paid', 422);
        }

        $frontendUrl = FrontendUrl::base();
        $returnUrl   = "{$frontendUrl}/client/invoices/{$id}/pay/return/{$gateway}";
        $cancelUrl   = "{$frontendUrl}/client/invoices/{$id}/pay?cancelled=1";

        try {
            $payment = $existingPending ?? Payment::create([
                'invoice_id'          => $invoice->id,
                'recorded_by'         => $request->user()->id,
                'amount'              => $amount,
                'currency'            => $invoice->currency,
                'method'              => 'gateway',
                'gateway'             => $gateway,
                'company_gateway_id'  => $gatewayRow->id,
                'status'              => 'pending',
                'payment_date'        => now()->toDateString(),
            ]);

            $checkout = PaymentGatewayManager::driver($gateway)
                ->createCheckout($invoice, $amount, $gatewayRow->config, $returnUrl, $cancelUrl);

            $payment->update(['gateway_session_id' => $checkout->sessionId]);

            InvoicePaymentService::logPayment($invoice, $payment, 'client_portal_gateway_checkout', $request->ip());

            return ApiResponse::success(array_merge($checkout->toArray(), ['payment_id' => $payment->id]), 'Checkout started');
        } catch (\Throwable $e) {
            Log::error('Client portal payment gateway checkout initiation failed', [
                'gateway' => $gateway, 'invoice_id' => $invoice->id, 'error' => $e->getMessage(),
            ]);
            return ApiResponse::error('Could not start payment. Please try again or choose another payment method.', 500);
        }
    }

    // Shared lookup for the inline-payment endpoints below.
    private function invoiceForGatewayAction(Request $request, int $id): Invoice
    {
        return Invoice::where('id', $id)
            ->whereIn('client_id', $this->clientIds($request))
            ->whereIn('status', ['sent', 'overdue', 'partially_paid'])
            ->with('company')
            ->firstOrFail();
    }

    // GET /client/invoices/{id}/gateways/{gateway}/init — public-safe
    // credentials for mounting Stripe Elements / PayPal Buttons / Authorize.net
    // Accept.js inline on this page (no redirect, unlike initiateGatewayCheckout() above).
    public function initGateway(Request $request, int $id, string $gateway): JsonResponse
    {
        try {
            $invoice = $this->invoiceForGatewayAction($request, $id);
            $companyGatewayId = $request->query('company_gateway_id') ? (int) $request->query('company_gateway_id') : null;
            return ApiResponse::success(InvoiceGatewayChargeService::publicInit($invoice, $gateway, $companyGatewayId));
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    // POST /client/invoices/{id}/gateways/paypal/create-order
    public function createPaypalOrder(Request $request, int $id): JsonResponse
    {
        try {
            $invoice = $this->invoiceForGatewayAction($request, $id);
            $companyGatewayId = $request->input('company_gateway_id') ? (int) $request->input('company_gateway_id') : null;
            return ApiResponse::success(InvoiceGatewayChargeService::createPaypalOrder($invoice, $companyGatewayId));
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    // POST /client/invoices/{id}/gateways/{gateway}/charge — inline charge,
    // finalized synchronously from this request's own response (no webhook
    // involved), mirroring Api\Admin\SubscriptionPaymentController::process().
    public function chargeGateway(Request $request, int $id, string $gateway): JsonResponse
    {
        $data = $request->validate(match ($gateway) {
            'stripe'        => ['payment_method_id' => 'required|string'],
            'paypal'        => ['paypal_order_id' => 'required|string'],
            'authorize_net' => ['opaque_data_descriptor' => 'required|string', 'opaque_data_value' => 'required|string'],
            default         => [],
        });

        try {
            $invoice = $this->invoiceForGatewayAction($request, $id);
            $companyGatewayId = $request->input('company_gateway_id') ? (int) $request->input('company_gateway_id') : null;
            $result  = InvoiceGatewayChargeService::charge(
                $invoice, $gateway, $data, 'client_portal_gateway_inline', $request->user()->id, $request->ip(), $companyGatewayId
            );
            return ApiResponse::success($result, 'Payment successful');
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    // GET /client/invoices/{id}/gateways/{gateway}/return
    // Mirrors Api\PublicInvoiceController::returnFromGateway() — immediate-UX
    // confirmation only; the webhook remains the authoritative, idempotent
    // finalizer either way.
    public function returnFromGateway(Request $request, int $id, string $gateway): JsonResponse
    {
        if (!array_key_exists($gateway, CompanyPaymentGateway::GATEWAYS)) {
            return ApiResponse::error('Unknown payment gateway', 422);
        }

        $invoice = Invoice::where('id', $id)
            ->whereIn('client_id', $this->clientIds($request))
            ->with('company')
            ->firstOrFail();

        if ($invoice->status === 'paid') {
            return ApiResponse::success(['status' => 'paid'], 'Invoice already paid');
        }

        $fallbackRow = PaymentGatewayManager::activeRowFor($invoice->company, $gateway);
        if (!$fallbackRow) {
            return ApiResponse::error('This payment gateway is not available for this invoice.', 422);
        }

        if ($gateway === 'paypal') {
            $orderId = $request->query('token');
            $payment = $invoice->payments()->where('gateway', 'paypal')->where('gateway_session_id', $orderId)->first();

            if ($payment && $payment->status === 'pending') {
                $gatewayRow = $payment->company_gateway_id
                    ? (PaymentGatewayManager::accountById($invoice->company, $payment->company_gateway_id) ?? $fallbackRow)
                    : $fallbackRow;
                $result = PaymentGatewayManager::driver('paypal')->captureOrder($orderId, $gatewayRow->config);
                if ($result['success'] ?? false) {
                    InvoicePaymentService::finalizeGatewaySuccess($payment, $result['transaction_id']);
                }
            }
        }

        $invoice->refresh();

        // Remember the card this checkout left on file, so the Payment
        // Assistant can offer it for the next invoice.
        //
        // Runs only AFTER the invoice actually moved — and the service swallows
        // every failure — because the payment has already succeeded by this
        // point and failing to remember a card must never surface as a failed
        // payment. Gateways that cannot vault (PayPal, Authorize.net today)
        // return null and nothing is stored.
        //
        // This is the return-to-portal path. A client who pays and closes the
        // browser is still settled by the webhook, but no card is saved for
        // them — a deliberate trade-off, since hooking the webhook would mean
        // touching the shared settlement path for a convenience feature.
        if (in_array($invoice->status, ['paid', 'partially_paid'], true)) {
            $settled = $invoice->payments()
                ->where('gateway', $gateway)
                ->where('status', 'confirmed')
                ->whereNotNull('gateway_session_id')
                ->latest('id')
                ->first();

            if ($settled) {
                ClientPaymentMethodService::rememberFromCheckout($invoice, $gateway, $settled->gateway_session_id);
            }
        }

        return ApiResponse::success(['status' => $invoice->status], 'Payment status');
    }

    // Mirrors Api\PublicInvoiceController::resolveGatewayForInvoice().
    private function resolveGatewayForInvoice(Invoice $invoice, string $gateway, ?int $companyGatewayId): ?CompanyPaymentGateway
    {
        if ($companyGatewayId === null) {
            return PaymentGatewayManager::activeRowFor($invoice->company, $gateway);
        }

        $row = PaymentGatewayManager::accountById($invoice->company, $companyGatewayId);
        if (!$row || $row->gateway !== $gateway) {
            return null;
        }

        $allowed = $invoice->paymentGatewayAccounts;
        if ($allowed->isNotEmpty() && !$allowed->contains('id', $row->id)) {
            return null;
        }

        return $row;
    }
}
