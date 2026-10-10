<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Payment;
use App\Services\DealEligibilityService;
use App\Services\InvoicePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    use ScopesToActiveCompany;

    private function companyIds(): array
    {
        return auth('admin')->user()->companies()->pluck('id')->toArray();
    }

    // Recomputes the linked Deal's (Lead's) fulfillment_status after
    // anything that could move net_paid_amount — payment recorded/confirmed/
    // removed. If it just crossed INTO eligible_for_project, tells the
    // seller they can now create the project (spec §15); if it dropped back
    // below the requirement (e.g. a payment was removed), the seller is
    // told eligibility was lost. The actor here is always a Company Admin
    // (not a `users` row), never the seller themselves, so there's no
    // self-notification case to skip — unlike the User-guard mirror below.
    private function recomputeDealEligibility(?Invoice $invoice): void
    {
        if (!$invoice || !$invoice->lead_id) {
            return;
        }

        $lead   = $invoice->lead;
        if (!$lead) return;

        $before = $lead->fulfillment_status;
        $after  = DealEligibilityService::recomputeFulfillmentStatus($lead);

        if ($before === $after || !$lead->assigned_to) {
            return;
        }

        if ($after === 'eligible_for_project') {
            Notification::create([
                'user_id'    => $lead->assigned_to,
                'company_id' => $lead->company_id,
                'type'       => 'deal_eligible_for_project',
                'title'      => 'Deal eligible for project creation',
                'body'       => "The required payment for \"{$lead->proposed_project_title}\" has been received. You may now create the Project.",
                'data'       => ['lead_id' => $lead->id, 'link' => "/leads/{$lead->id}"],
            ]);
        } elseif ($before === 'eligible_for_project' && in_array($after, ['partially_paid', 'awaiting_payment'])) {
            Notification::create([
                'user_id'    => $lead->assigned_to,
                'company_id' => $lead->company_id,
                'type'       => 'deal_eligibility_lost',
                'title'      => 'Project eligibility changed',
                'body'       => "Payment eligibility for {$lead->deal_reference} has changed because a payment was refunded, reversed, or removed.",
                'data'       => ['lead_id' => $lead->id, 'link' => "/leads/{$lead->id}"],
            ]);
        } elseif ($after === 'partially_paid') {
            $net       = DealEligibilityService::netPaidAmount($lead);
            $required  = DealEligibilityService::requiredAmount($lead);
            $remaining = max(0, round($required - $net, 2));
            Notification::create([
                'user_id'    => $lead->assigned_to,
                'company_id' => $lead->company_id,
                'type'       => 'deal_partial_payment',
                'title'      => 'Partial payment received',
                'body'       => number_format($net, 2) . " received. " . number_format($remaining, 2) . " remains before project activation.",
                'data'       => ['lead_id' => $lead->id, 'link' => "/leads/{$lead->id}"],
            ]);
        }
    }

    // GET /admin/payments
    public function index(Request $request): JsonResponse
    {
        // Company-Wise Dashboard Filtering — scoped to the active company;
        // $summary below is computed from this same already-scoped $payments
        // collection, so it's correct automatically.
        $companyIds = $this->activeCompanyIds();

        $query = Payment::whereHas('invoice', fn($q) => $q->whereIn('company_id', $companyIds))
            ->with([
                'invoice:id,invoice_number,client_id,lead_id,currency',
                // A guest/lead-only invoice (no client_id yet) still needs a
                // name here — the frontend falls back to invoice.lead.
                'invoice.client:id,name',
                'invoice.lead:id,name',
                'companyGateway:id,label,gateway',
            ])
            ->orderByDesc('payment_date')
            ->orderByDesc('id');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->whereHas('invoice', fn($iq) =>
                $iq->where('invoice_number', 'like', "%{$s}%")
                   ->orWhereHas('client', fn($cq) => $cq->where('name', 'like', "%{$s}%"))
            );
        }

        if ($request->filled('method') && $request->method !== 'all') {
            $query->where('method', $request->method);
        }

        if ($request->filled('from')) {
            $query->whereDate('payment_date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('payment_date', '<=', $request->to);
        }

        $payments = $query->get();

        // "Total Received" must reflect money actually received — a pending
        // (unconfirmed) or failed/rejected claim was never received, so only
        // confirmed payments count toward the summary. The full $payments
        // list above (with its status badges) still includes every status.
        $received = $payments->where('status', 'confirmed');

        // Grouped by currency, not blended — this company can have payments
        // in more than one currency (e.g. after a Settings currency change),
        // and summing raw amounts across currencies as one number is
        // meaningless. 'currency' falls back to the invoice's own currency
        // for any payment recorded before that column existed on `payments`.
        $byCurrency = $received->groupBy(fn($p) => $p->currency ?? $p->invoice?->currency ?? 'USD')
            ->map(fn($g, $currency) => [
                'currency'  => $currency,
                'total'     => (float) $g->sum('amount'),
                'count'     => $g->count(),
                'by_method' => $g->groupBy('method')->map(fn($m) => (float) $m->sum('amount'))->toArray(),
            ])
            ->values();

        $summary = [
            'count'       => $received->count(),
            'by_currency' => $byCurrency,
        ];

        return ApiResponse::success([
            'payments' => $payments,
            'summary'  => $summary,
        ]);
    }

    // POST /admin/invoices/{invoice}/payments
    public function store(Request $request, Invoice $invoice): JsonResponse
    {
        if (!in_array($invoice->company_id, $this->companyIds())) {
            return ApiResponse::error('Not found', 404);
        }
        if (in_array($invoice->status, ['paid', 'cancelled'])) {
            return ApiResponse::error('Invoice is already ' . $invoice->status, 422);
        }

        // Same "Full Payment Only" policy enforced on the client-facing pay
        // page (see Api\PublicInvoiceController::pay()) — a Company Admin
        // manually recording a payment is still choosing an amount, so a
        // partial figure has to be refused here too, not just on the client's
        // own checkout. The bounds now come from
        // InvoicePaymentService::recordableRange() so the Finance Area's own
        // Record Payment action enforces the identical policy.
        ['min' => $minAmount, 'max' => $outstanding, 'requires_full' => $requiresFullPayment]
            = InvoicePaymentService::recordableRange($invoice);

        $data = $request->validate([
            'amount'       => "required|numeric|min:{$minAmount}|max:{$outstanding}",
            'method'       => 'nullable|in:bank_transfer,cash,card,cheque,gateway',
            'payment_date' => 'nullable|date',
            'notes'        => 'nullable|string|max:500',
            'gateway'      => 'nullable|string|max:50',
            'gateway_ref'  => 'nullable|string|max:255',
        ], $requiresFullPayment ? [
            'amount.min' => "{$invoice->currency} " . number_format($outstanding, 2)
                . ' is due and this invoice has to be paid in full — part payments are not accepted under the current Payment Policy.',
        ] : []);

        // The row creation, currency stamping, receipt number and
        // applyToInvoice() call all moved into
        // InvoicePaymentService::record() (2026-09-21) so this path and
        // Api\Finance\PaymentController::record() cannot drift apart.
        // Behaviour here is unchanged except that the payment now also
        // records WHICH admin entered it (recorded_by_admin_id), which was
        // previously unrecorded because recorded_by only accepts a users id.
        $payment = InvoicePaymentService::record($invoice, $data, ['admin_id' => auth('admin')->id()]);

        InvoicePaymentService::logPayment($invoice, $payment, 'admin');
        $this->recomputeDealEligibility($invoice);

        return ApiResponse::success([
            'payment' => $payment,
            'invoice' => [
                'status'       => $invoice->status,
                'paid_amount'  => $invoice->paid_amount,
                'total_amount' => $invoice->total_amount,
            ],
        ], 'Payment recorded');
    }

    // PATCH /admin/payments/{payment}/confirm
    // Confirms a customer-submitted payment claim (public link/bank transfer/
    // gateway reference) — this is the point the invoice actually becomes
    // paid/partially_paid, a receipt is issued, and stakeholders are notified.
    public function confirm(Payment $payment): JsonResponse
    {
        $invoice = $payment->invoice;
        if (!in_array($invoice->company_id, $this->companyIds())) {
            return ApiResponse::error('Not found', 404);
        }
        if ($payment->status !== 'pending') {
            return ApiResponse::error('Only a pending payment can be confirmed', 422);
        }

        $payment->update([
            'status'         => 'confirmed',
            'receipt_number' => $payment->receipt_number ?? InvoicePaymentService::nextReceiptNumber($invoice->company_id),
        ]);

        InvoicePaymentService::applyToInvoice($invoice, $payment);
        InvoicePaymentService::logDecision($invoice, $payment, 'confirmed');
        InvoicePaymentService::notifyStakeholders($invoice, $payment, auth('admin')->user()->id);
        InvoicePaymentService::logClientActivity($invoice, $payment);
        InvoicePaymentService::logLeadActivity($invoice, $payment);
        $this->recomputeDealEligibility($invoice);

        return ApiResponse::success([
            'payment' => $payment->fresh(),
            'invoice' => [
                'status'       => $invoice->status,
                'paid_amount'  => $invoice->paid_amount,
                'total_amount' => $invoice->total_amount,
            ],
        ], 'Payment confirmed');
    }

    // PATCH /admin/payments/{payment}/reject
    // Rejects a customer-submitted payment claim — invoice status/paid_amount
    // is untouched (it was never applied for a pending claim in the first
    // place), the claim is just marked failed for the audit trail.
    public function reject(Request $request, Payment $payment): JsonResponse
    {
        $invoice = $payment->invoice;
        if (!in_array($invoice->company_id, $this->companyIds())) {
            return ApiResponse::error('Not found', 404);
        }
        if ($payment->status !== 'pending') {
            return ApiResponse::error('Only a pending payment can be rejected', 422);
        }

        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        $payment->update([
            'status' => 'failed',
            'notes'  => trim(($payment->notes ? $payment->notes . ' — ' : '') . ($data['reason'] ?? 'Rejected by admin')),
        ]);

        InvoicePaymentService::logDecision($invoice, $payment, 'rejected');
        InvoicePaymentService::notifyPaymentFailed($invoice, $payment, $data['reason'] ?? null, auth('admin')->user()->id);

        return ApiResponse::success(['payment' => $payment->fresh()], 'Payment rejected');
    }

    // DELETE /admin/payments/{payment}
    public function destroy(Payment $payment): JsonResponse
    {
        $invoice = $payment->invoice;
        if (!in_array($invoice->company_id, $this->companyIds())) {
            return ApiResponse::error('Not found', 404);
        }

        // The receipt is an artifact of this payment, so it goes with it —
        // otherwise the client is left holding a receipt in Portal → Documents
        // for money the company has since removed from the invoice. A no-op
        // when no receipt was ever issued (pending/failed claims never get one).
        \App\Services\PaymentReceiptService::forgetPayment($payment->id);

        $payment->delete();

        // Only confirmed payments count toward paid_amount — pending/failed
        // claims never affected it in the first place (see confirm()/reject()).
        $newPaid = (float) $invoice->payments()->where('status', 'confirmed')->sum('amount');
        $invoice->paid_amount = $newPaid;

        if ($newPaid <= 0) {
            $invoice->status = $invoice->sent_at ? 'sent' : 'draft';
        } elseif ($newPaid < (float) $invoice->total_amount) {
            $invoice->status = 'partially_paid';
        } else {
            $invoice->status = 'paid';
        }
        $invoice->save();

        $this->recomputeDealEligibility($invoice);

        return ApiResponse::success(null, 'Payment removed');
    }
}
