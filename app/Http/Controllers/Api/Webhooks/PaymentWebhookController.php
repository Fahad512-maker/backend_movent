<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\CompanyPaymentGateway;
use App\Models\Payment;
use App\Services\InvoicePaymentService;
use App\Services\PaymentGateways\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// Public, unauthenticated — each gateway calls this directly. The URL itself
// carries which tenant it belongs to (/webhooks/{gateway}/{company_admin_id})
// since each Company Admin registers their OWN webhook endpoint against
// their OWN gateway account; signature verification then proves the request
// really came from that tenant's gateway, not just anyone who guesses the URL.
//
// A tenant can now hold multiple accounts of the same gateway type, so this
// URL alone can be ambiguous. Two forms are supported:
//   /webhooks/{gateway}/{companyAdminId}              — resolves to that
//     type's DEFAULT account for the tenant. Kept working unchanged so any
//     webhook URL a Company Admin already pasted into a live gateway
//     dashboard before multi-account support existed keeps firing.
//   /webhooks/{gateway}/{companyAdminId}/{companyGatewayId} — resolves to
//     that exact account. This is the URL shown for any additional (2nd+)
//     account of the same type.
//
// Since gateway accounts became per-company (2026-09-11) the legacy 2-segment
// form can name SEVERAL accounts — one tenant, one gateway type, but a
// separate account per company, each flagged is_default within its own
// company. Guessing one would break the other companies' webhooks, so
// candidateGatewayRows() returns all of them and the signature itself decides
// which account the event really belongs to: only the account holding the
// right webhook secret can verify it.
class PaymentWebhookController extends Controller
{
    public function handle(Request $request, string $gateway, int $companyAdminId, ?int $companyGatewayId = null): JsonResponse
    {
        if (!array_key_exists($gateway, CompanyPaymentGateway::GATEWAYS)) {
            return response()->json(['error' => 'Unknown gateway'], 404);
        }

        $candidates = $this->candidateGatewayRows($gateway, $companyAdminId, $companyGatewayId);

        if ($candidates->isEmpty()) {
            return response()->json(['error' => 'Gateway not configured for this account'], 404);
        }

        $driver     = PaymentGatewayManager::driver($gateway);
        $gatewayRow = null;
        $event      = null;

        foreach ($candidates as $candidate) {
            $parsed = $driver->parseWebhook($request, $candidate->config);
            if ($parsed->verified) {
                $gatewayRow = $candidate;
                $event      = $parsed;
                break;
            }
        }

        if (!$gatewayRow) {
            // Never say why — an attacker probing signature verification
            // shouldn't learn anything from the response.
            Log::warning('Payment gateway webhook: signature verification failed', [
                'gateway' => $gateway, 'company_admin_id' => $companyAdminId,
                'candidates_tried' => $candidates->count(),
            ]);
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        // Idempotency — gateways retry webhook delivery on anything but a
        // clean 2xx, so the exact same event id can arrive more than once.
        if ($event->eventId && InvoicePaymentService::webhookAlreadyProcessed($gateway, $event->eventId)) {
            return response()->json(['status' => 'already processed']);
        }

        if ($event->outcome === 'ignored') {
            if ($event->eventId) InvoicePaymentService::markWebhookProcessed($gateway, $event->eventId);
            return response()->json(['status' => 'ignored']);
        }

        $payment = $this->matchPayment($gateway, $companyAdminId, $event->gatewaySessionId, $event->invoiceNumber);

        if (!$payment) {
            Log::warning('Payment gateway webhook: no matching pending payment found', [
                'gateway' => $gateway, 'company_admin_id' => $companyAdminId, 'event_id' => $event->eventId,
            ]);
            if ($event->eventId) InvoicePaymentService::markWebhookProcessed($gateway, $event->eventId);
            return response()->json(['status' => 'no matching payment']);
        }

        // Cross-tenant guard — the matched payment's invoice must genuinely
        // belong to the tenant this webhook URL identifies, never trust the
        // URL alone.
        if ((int) $payment->invoice->company->admin_id !== $companyAdminId) {
            Log::error('Payment gateway webhook: payment/tenant mismatch — refusing to process', [
                'gateway' => $gateway, 'company_admin_id' => $companyAdminId, 'payment_id' => $payment->id,
            ]);
            return response()->json(['error' => 'Tenant mismatch'], 403);
        }

        if ($event->outcome === 'succeeded') {
            $transactionId = $event->transactionId ?? $event->gatewaySessionId;

            // PayPal orders need an explicit capture before the funds are
            // actually taken — CHECKOUT.ORDER.APPROVED fires before that's
            // happened. captureOrder() is itself idempotent (PayPal reports
            // ORDER_ALREADY_CAPTURED harmlessly if PAYMENT.CAPTURE.COMPLETED
            // already did it, or the customer's return-callback beat us to it).
            if ($gateway === 'paypal') {
                $result = PaymentGatewayManager::driver('paypal')->captureOrder($event->gatewaySessionId, $gatewayRow->config);
                if (!($result['success'] ?? false)) {
                    if ($event->eventId) InvoicePaymentService::markWebhookProcessed($gateway, $event->eventId);
                    return response()->json(['status' => 'capture failed, will retry on next webhook']);
                }
                $transactionId = $result['transaction_id'];
            }

            InvoicePaymentService::finalizeGatewaySuccess($payment, $transactionId);
        } elseif ($event->outcome === 'failed') {
            InvoicePaymentService::finalizeGatewayFailure($payment, $event->failureReason);
        }

        if ($event->eventId) InvoicePaymentService::markWebhookProcessed($gateway, $event->eventId);

        return response()->json(['status' => 'ok']);
    }

    /**
     * The accounts this URL could plausibly mean, ordered most-likely-first.
     *
     * An account-scoped URL names exactly one row (ownership-checked against
     * the tenant in the URL), so it yields at most one candidate. The legacy
     * form names every active account of that type under the tenant — one per
     * company since accounts became per-company — with each company's
     * is_default first, since a tenant that still has only the one account
     * resolves identically to how it always did.
     */
    private function candidateGatewayRows(string $gateway, int $companyAdminId, ?int $companyGatewayId)
    {
        $query = CompanyPaymentGateway::where('company_admin_id', $companyAdminId)
            ->where('gateway', $gateway);

        if ($companyGatewayId !== null) {
            return $query->where('id', $companyGatewayId)->get();
        }

        return $query->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();
    }

    private function matchPayment(string $gateway, int $companyAdminId, ?string $sessionId, ?string $invoiceNumber): ?Payment
    {
        if ($sessionId) {
            return Payment::where('gateway', $gateway)
                ->where('gateway_session_id', $sessionId)
                ->whereHas('invoice.company', fn ($q) => $q->where('admin_id', $companyAdminId))
                ->first();
        }

        // Authorize.net's Accept Hosted webhook payload carries no session
        // id we can trace back — fall back to matching the invoice number
        // its own transaction details report, scoped to this tenant only.
        if ($invoiceNumber) {
            return Payment::where('gateway', $gateway)
                ->where('status', 'pending')
                ->whereHas('invoice', function ($q) use ($invoiceNumber, $companyAdminId) {
                    $q->where('invoice_number', $invoiceNumber)
                      ->whereHas('company', fn ($cq) => $cq->where('admin_id', $companyAdminId));
                })
                ->latest('id')
                ->first();
        }

        return null;
    }
}
