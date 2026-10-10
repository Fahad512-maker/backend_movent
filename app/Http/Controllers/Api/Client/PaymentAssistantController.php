<?php

namespace App\Http\Controllers\Api\Client;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Client\Concerns\ScopesToPermittedClient;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentAssistantSession;
use App\Services\PaymentAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Client Portal's Payment Assistant — a rule-based invoice payment chat.
 *
 * WHAT THIS DOES NOT DO, deliberately:
 *   - It does not call any AI service. Input is parsed with a regex
 *     (PaymentAssistantService::extractInvoiceNumber) and the flow is a small
 *     state machine.
 *   - It does not charge anything. The final step hands off to the EXISTING
 *     hosted checkout (InvoiceController::initiateGatewayCheckout), so the
 *     card is entered on the gateway's own page, settlement runs through
 *     InvoicePaymentService as it always has, and receipts, notifications and
 *     automatic project creation all keep working unchanged. There is one
 *     payment path in this app and this is a new way in, not a second one.
 *   - It does not touch the existing chats. Sales, project and support
 *     conversations are untouched; this has its own session table and its own
 *     screen.
 *
 * Every response is the WHOLE assistant state, rebuilt from the database. The
 * browser is never the source of truth for the company, the invoice or the
 * amount — those come off the session row and are re-validated here on each
 * request, which is what stops a crafted request from reaching another
 * client's or another company's invoice.
 */
class PaymentAssistantController extends Controller
{
    use ScopesToPermittedClient;

    /** The assistant's current state — the only response shape this controller returns. */
    private function stateResponse(
        Request $request,
        PaymentAssistantSession $session,
        ?string $message = null,
        array $extra = []
    ): JsonResponse {
        $session->refresh()->load(['company:id,name', 'invoice.company:id,name']);

        $payload = [
            'state'        => $session->state,
            'company'      => $session->company ? [
                'id'   => $session->company->id,
                'name' => $session->company->name,
            ] : null,
            'companies'    => $session->company_id ? [] : PaymentAssistantService::payableCompanies($request->user()),
            'invoice'      => null,
            'message'      => $message,
        ];

        // Re-resolved rather than read off the session: the invoice may have
        // been paid elsewhere (public link, an admin recording a transfer)
        // since this session last looked at it.
        if ($session->invoice_id && $session->company_id) {
            $invoice = $this->sessionInvoice($request, $session);
            $payload['invoice'] = $invoice ? PaymentAssistantService::invoiceCard($invoice) : null;
        }

        return ApiResponse::success(array_merge($payload, $extra));
    }

    /**
     * The session's invoice, re-validated against this login and the chosen
     * company. Returns null if it no longer qualifies — never trusts
     * session.invoice_id on its own.
     */
    private function sessionInvoice(Request $request, PaymentAssistantSession $session): ?Invoice
    {
        if (!$session->invoice_id || !$session->company_id) {
            return null;
        }

        return Invoice::where('id', $session->invoice_id)
            ->whereIn('client_id', $this->clientIds($request))
            ->where('company_id', $session->company_id)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->with('company:id,name')
            ->first();
    }

    /** @return array<int, int> */
    private function clientIds(Request $request): array
    {
        return $this->permittedClientIds($request, 'invoices');
    }

    /**
     * The live session for this login, created on first use.
     *
     * A single company is selected automatically (spec §2) — asking "which
     * company?" when there is only one is noise.
     */
    private function session(Request $request): PaymentAssistantSession
    {
        $session = PaymentAssistantSession::firstOrCreate(
            ['user_id' => $request->user()->id],
            [
                'state'                   => PaymentAssistantSession::COMPANY_SELECTION,
                'conversation_id'         => (string) \Illuminate\Support\Str::uuid(),
                'conversation_started_at' => now(),
            ]
        );

        // Sessions created before conversations existed get one on first touch,
        // so every audit row written from here on carries a conversation.
        $session->ensureConversation();

        if (!$session->company_id) {
            $companies = PaymentAssistantService::payableCompanies($request->user());

            if (count($companies) === 1) {
                $session->update([
                    'company_id' => $companies[0]['company_id'],
                    'client_id'  => $companies[0]['client_id'],
                    'state'      => PaymentAssistantSession::WAITING_FOR_INVOICE,
                ]);
                PaymentAssistantService::audit($session, 'company_auto_selected');
            }
        }

        return $session;
    }

    // ── GET /client/payment-assistant ────────────────────────────────────────

    public function show(Request $request): JsonResponse
    {
        $session = $this->session($request);
        PaymentAssistantService::audit($session, 'opened');

        return $this->stateResponse($request, $session, $this->welcomeFor($session));
    }

    private function welcomeFor(PaymentAssistantSession $session): string
    {
        return $session->company_id
            ? 'Welcome to the Payment Assistant. Please enter the invoice number you would like to pay.'
            : 'Which company would you like to make a payment to?';
    }

    // ── POST /client/payment-assistant/company ───────────────────────────────

    public function selectCompany(Request $request): JsonResponse
    {
        $validated = $request->validate(['company_id' => ['required', 'integer']]);
        $session = $this->session($request);

        // Validated against the login's OWN payable companies, not accepted as
        // given — this is the request that decides which invoices become
        // reachable, so an id the client has no Client record for must be
        // rejected outright rather than merely returning nothing later.
        $match = collect(PaymentAssistantService::payableCompanies($request->user()))
            ->firstWhere('company_id', (int) $validated['company_id']);

        if (!$match) {
            return ApiResponse::error('That company is not available for your account.', 422);
        }

        $session->update([
            'company_id' => $match['company_id'],
            'client_id'  => $match['client_id'],
            'invoice_id' => null,
            'state'      => PaymentAssistantSession::WAITING_FOR_INVOICE,
        ]);

        PaymentAssistantService::audit($session, 'company_selected');

        return $this->stateResponse(
            $request,
            $session,
            'Welcome to the Payment Assistant. Please enter the invoice number you would like to pay.'
        );
    }

    // ── POST /client/payment-assistant/message ───────────────────────────────

    /**
     * Whatever the client typed. Rule-based: an invoice number is extracted and
     * looked up; anything unreadable gets a re-prompt.
     */
    public function message(Request $request): JsonResponse
    {
        $validated = $request->validate(['text' => ['required', 'string', 'max:200']]);
        $session = $this->session($request);

        if (!$session->company_id) {
            return $this->stateResponse($request, $session, 'Please choose a company first.');
        }

        $digits = PaymentAssistantService::extractInvoiceNumber($validated['text']);

        if (!$digits) {
            return $this->stateResponse(
                $request,
                $session,
                "I didn't catch an invoice number there. Please enter it like INV-1025."
            );
        }

        PaymentAssistantService::audit($session, 'invoice_searched', null, ['query' => $digits]);

        $invoice = PaymentAssistantService::matchInvoice($digits, $this->clientIds($request), $session->company_id);

        if (!$invoice) {
            $session->clearInvoice();

            // Says only that it was not found for THIS company — never whether
            // the number exists elsewhere, which would leak another client's
            // invoicing.
            return $this->stateResponse(
                $request,
                $session,
                "We couldn't find this invoice for the selected company. Please check the invoice number and try again."
            );
        }

        return $this->loadInvoice($request, $session, $invoice);
    }

    // ── POST /client/payment-assistant/invoice ───────────────────────────────

    /** Picking one straight off the unpaid list. */
    public function selectInvoice(Request $request): JsonResponse
    {
        $validated = $request->validate(['invoice_id' => ['required', 'integer']]);
        $session = $this->session($request);

        if (!$session->company_id) {
            return ApiResponse::error('Please choose a company first.', 422);
        }

        // Ownership and company re-checked here, not taken from the list the
        // browser was holding.
        $invoice = Invoice::where('id', (int) $validated['invoice_id'])
            ->whereIn('client_id', $this->clientIds($request))
            ->where('company_id', $session->company_id)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->with('company:id,name')
            ->first();

        if (!$invoice) {
            return ApiResponse::error('That invoice is not available for your account.', 404);
        }

        return $this->loadInvoice($request, $session, $invoice);
    }

    /** Put an invoice in view and describe it. */
    private function loadInvoice(Request $request, PaymentAssistantSession $session, Invoice $invoice): JsonResponse
    {
        $session->update([
            'invoice_id' => $invoice->id,
            'state'      => PaymentAssistantSession::INVOICE_FOUND,
        ]);

        PaymentAssistantService::audit($session, 'invoice_selected', $invoice->id);

        if ($invoice->status === 'paid' || PaymentAssistantService::amountDue($invoice) <= 0) {
            return $this->stateResponse(
                $request,
                $session,
                "Invoice #{$invoice->invoice_number} has already been paid.",
                ['already_paid' => true, 'payment' => $this->lastPaymentSummary($invoice)]
            );
        }

        if (!PaymentAssistantService::isPayable($invoice)) {
            return $this->stateResponse(
                $request,
                $session,
                "Invoice #{$invoice->invoice_number} is not available for payment right now. Please contact your seller."
            );
        }

        return $this->stateResponse($request, $session, 'Would you like to pay this invoice?');
    }

    /** Receipt-ish detail for an already-paid invoice (spec §6). */
    private function lastPaymentSummary(Invoice $invoice): ?array
    {
        // 'confirmed' is this schema's settled state (payments.status enum is
        // confirmed/pending/failed/refunded) — there is no 'completed'.
        $payment = $invoice->payments()
            ->where('status', 'confirmed')
            ->latest('id')
            ->first();

        return $payment ? [
            'amount'    => round((float) $payment->amount, 2),
            'paid_at'   => $payment->payment_date
                ? (string) $payment->payment_date
                : optional($payment->created_at)->toDateString(),
            // The gateway's own reference when there is one, else the receipt
            // number a manually-recorded payment carries.
            'reference' => $payment->gateway_ref ?: $payment->receipt_number,
        ] : null;
    }

    // ── GET /client/payment-assistant/unpaid ─────────────────────────────────

    public function unpaid(Request $request): JsonResponse
    {
        $session = $this->session($request);

        if (!$session->company_id) {
            return ApiResponse::error('Please choose a company first.', 422);
        }

        return ApiResponse::success([
            'invoices' => PaymentAssistantService::unpaidInvoices($this->clientIds($request), $session->company_id),
        ]);
    }

    // ── POST /client/payment-assistant/confirm ───────────────────────────────

    /**
     * The explicit "yes, charge me" step (spec §18). Even this does NOT charge:
     * it re-validates and hands back the gateway checkout the client must then
     * complete on the gateway's own page.
     *
     * Everything is re-checked here because time has passed since the invoice
     * card was rendered — it may have been paid from the public link, or had a
     * bank transfer recorded against it, in the meantime.
     */
    public function confirm(Request $request): JsonResponse
    {
        $session = $this->session($request);
        $invoice = $this->sessionInvoice($request, $session);

        if (!$invoice) {
            $session->clearInvoice();

            return $this->stateResponse($request, $session, 'Please choose an invoice first.');
        }

        // Duplicate protection (spec §14): re-read the balance rather than
        // trusting anything shown earlier. An invoice settled elsewhere while
        // this assistant sat open must not be charged again.
        if (!PaymentAssistantService::isPayable($invoice)) {
            $session->update(['state' => PaymentAssistantSession::INVOICE_FOUND]);
            PaymentAssistantService::audit($session, 'confirm_blocked_already_paid', $invoice->id);

            return $this->stateResponse(
                $request,
                $session,
                "This invoice has already been paid.",
                ['already_paid' => true, 'payment' => $this->lastPaymentSummary($invoice)]
            );
        }

        $session->update(['state' => PaymentAssistantSession::CONFIRMING]);
        PaymentAssistantService::audit($session, 'payment_initiated', $invoice->id);

        // A card already on file for this company? Then the client confirms
        // once instead of going back through the hosted page. Still never an
        // automatic charge — payWithSavedMethod() below is a separate,
        // explicit request (spec: always require a final Confirm Payment).
        $saved = \App\Services\ClientPaymentMethodService::forClient(
            (int) $session->client_id,
            (int) $session->company_id
        );

        if ($saved->isNotEmpty()) {
            return $this->stateResponse($request, $session, 'Please confirm the payment.', [
                'checkout_invoice_id' => $invoice->id,
                'amount_due'          => PaymentAssistantService::amountDue($invoice),
                'saved_methods'       => $saved->map(fn ($m) => [
                    'id'         => $m->id,
                    'label'      => $m->label(),
                    'is_default' => $m->is_default,
                ])->all(),
            ]);
        }

        // HANDOFF, not a charge.
        //
        // The assistant resolves no gateways and builds no checkout of its own.
        // It hands back the invoice id, and the client's browser then calls the
        // EXISTING endpoints the Pay page already uses —
        // GET  /client/invoices/{id}/gateways
        // POST /client/invoices/{id}/gateways/{gateway}/initiate
        // — so per-invoice gateway accounts, the tenant's default-gateway rule,
        // currency support and conversion previews all keep working, in one
        // place, with no copy here to drift out of step.
        //
        // The card itself is entered on the gateway's own hosted page: this app
        // never receives one, which is why there is no saved-card step in this
        // flow at all.
        return $this->stateResponse($request, $session, 'Choose how you would like to pay.', [
            'checkout_invoice_id' => $invoice->id,
            'amount_due'          => PaymentAssistantService::amountDue($invoice),
        ]);
    }

    // ── POST /client/payment-assistant/pay-saved ─────────────────────────────

    /**
     * The final, explicit "charge my saved card" action.
     *
     * This is the ONLY request in the assistant that moves money, and it is a
     * deliberate, separate step — typing an invoice number and pressing Pay
     * Invoice both stop short of here.
     *
     * Everything is re-derived and re-checked server-side: the invoice from the
     * session, the amount from the invoice, the card from this client's own
     * saved methods for this company. The request body carries only which card,
     * and even that is validated against the client's own list.
     */
    public function payWithSavedMethod(Request $request): JsonResponse
    {
        $validated = $request->validate(['payment_method_id' => ['required', 'integer']]);
        $session = $this->session($request);
        $invoice = $this->sessionInvoice($request, $session);

        if (!$invoice) {
            $session->clearInvoice();

            return $this->stateResponse($request, $session, 'Please choose an invoice first.');
        }

        // Ownership: the card must belong to this client AND this company. Not
        // looked up by id alone — that would let a crafted request reach
        // another client's saved card.
        $method = \App\Services\ClientPaymentMethodService::forClient(
            (int) $session->client_id,
            (int) $session->company_id
        )->firstWhere('id', (int) $validated['payment_method_id']);

        if (!$method) {
            return ApiResponse::error('That payment method is not available for your account.', 404);
        }

        $session->update(['state' => PaymentAssistantSession::PROCESSING]);

        [$ok, $message] = \App\Services\ClientPaymentMethodService::charge($invoice, $method);

        $session->update([
            'state' => $ok ? PaymentAssistantSession::PAYMENT_SUCCESS : PaymentAssistantSession::PAYMENT_FAILED,
        ]);

        PaymentAssistantService::audit(
            $session,
            $ok ? 'gateway_payment_success' : 'gateway_payment_failure',
            $invoice->id
        );

        $fresh = $invoice->fresh();

        return $this->stateResponse($request, $session, $message, [
            'paid'    => $ok,
            'payment' => $ok ? $this->lastPaymentSummary($fresh) : null,
        ]);
    }

    // ── POST /client/payment-assistant/reset ─────────────────────────────────

    /** Start over — keeps the company, drops the invoice. */
    public function reset(Request $request): JsonResponse
    {
        $session = $this->session($request);
        $session->clearInvoice();

        return $this->stateResponse(
            $request,
            $session,
            'Please enter the invoice number you would like to pay.'
        );
    }

    // ── POST /client/payment-assistant/clear ─────────────────────────────────

    /**
     * "Clear History" — start a fresh conversation. DELETES NOTHING.
     *
     * The assistant's messages were never rows: they are rendered from the
     * state each response carries and live only in the open tab. What is
     * persisted is this session row (transient flow state) and the permanent
     * activity trail in `system_audit_logs`, action `payment_assistant.*`,
     * written by PaymentAssistantService::audit().
     *
     * So clearing is a CONVERSATION RESET, exactly as the spec asks for: the
     * session gets a new conversation_id, and because every audit row is
     * stamped with the id that was live when it was written, all past activity
     * stays where it is and stays attributable to the conversation it came
     * from. Invoices, payments, receipts, documents and the Sales Chat are not
     * touched by this endpoint at all — it writes to one row and reads nothing
     * else.
     *
     * The chosen company survives, because clearing the thread is not the same
     * as changing who you are paying (changeCompany() is that, and is still a
     * separate action).
     */
    public function clearHistory(Request $request): JsonResponse
    {
        $session = $this->session($request);

        // Audited BEFORE rotating, so the clear is recorded against the
        // conversation being cleared rather than the empty new one — which is
        // what makes the old activity readable as a closed conversation later.
        PaymentAssistantService::audit($session, 'history_cleared');

        $session->startConversation();

        return $this->stateResponse($request, $session, $this->welcomeFor($session), ['cleared' => true]);
    }

    /** Go back to the company list (only meaningful with more than one). */
    public function changeCompany(Request $request): JsonResponse
    {
        $session = $this->session($request);

        if (count(PaymentAssistantService::payableCompanies($request->user())) < 2) {
            return $this->stateResponse($request, $session, 'Your account is linked to a single company.');
        }

        $session->update([
            'company_id' => null,
            'client_id'  => null,
            'invoice_id' => null,
            'state'      => PaymentAssistantSession::COMPANY_SELECTION,
        ]);

        return $this->stateResponse($request, $session, 'Which company would you like to make a payment to?');
    }
}
