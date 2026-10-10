<?php

namespace App\Services\Finance;

use App\Models\CompanyPaymentGateway;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Models\Payment;

/**
 * The exact shape every Finance row leaves the server in.
 *
 * This is an allow-list, not a filter: each method builds a fresh array of
 * named fields rather than taking a model and removing things. That
 * distinction is the security property — a column added to `payments` or
 * `company_payment_gateways` tomorrow cannot appear in a Finance response by
 * accident, because nothing here would name it. Returning `$payment` (or
 * `$payment->toArray()`) anywhere in this module would give that guarantee
 * up, so no Finance controller does.
 *
 * What is deliberately never present, per the spec's "sensitive payment
 * information must NEVER be exposed":
 *
 *   • Card data of any kind. There is none to leak: every gateway in this app
 *     is hosted-checkout (Stripe Checkout / PayPal / Authorize.net hosted),
 *     so a PAN, expiry or CVV never reaches this server in the first place —
 *     `payments` has no card columns at all. Nothing here can therefore
 *     surface one, today or after a future column is added.
 *   • Gateway credentials. company_payment_gateways.config holds the live
 *     API keys (encrypted at rest with APP_KEY, see the model's $casts).
 *     Finance reads exactly two fields off that table — id and label — and
 *     never loads the model whole.
 *   • gateway_session_id. An internal checkout-session handle
 *     (Stripe `cs_…`), useful only for resuming a checkout; it is not the
 *     transaction reference a finance person reconciles against. gateway_ref
 *     IS that reference and is shown.
 */
class FinancePresenter
{
    /**
     * One row of Finance > Invoices.
     *
     * Expects: client, project, createdBy, company (all optional relations).
     */
    public static function invoiceRow(Invoice $invoice, array $companyNames = []): array
    {
        $progress = $invoice->paymentProgress();

        return [
            'id'                 => $invoice->id,
            'invoice_number'     => $invoice->invoice_number,
            'company_id'         => $invoice->company_id,
            'company_name'       => $companyNames[$invoice->company_id] ?? $invoice->company?->name,
            'client_id'          => $invoice->client_id,
            // Falls back through the three ways an invoice can name its
            // customer: a real Client, the originating Lead, or the free-text
            // customer_name captured for a guest invoice.
            'customer_name'      => $invoice->client?->name
                ?? $invoice->lead?->name
                ?? $invoice->customer_name
                ?? '—',
            'customer_company'   => $invoice->client?->company_name,
            'invoice_date'       => $invoice->created_at?->toDateString(),
            'due_date'           => $invoice->due_date?->toDateString(),
            'currency'           => $invoice->currency,
            'total_amount'       => (float) $invoice->total_amount,
            'paid_amount'        => (float) $invoice->paid_amount,
            'outstanding_amount' => $progress['remaining_amount'],
            // Two distinct axes, never collapsed into one — see the long note
            // on Invoice::paymentStatus(). `status` is the invoice lifecycle
            // (draft/sent/paid/overdue/cancelled); `payment_status` is what
            // the payment attempts did (full_paid/partially_paid/pending/
            // failed/refunded/overdue).
            'status'             => $invoice->status,
            'payment_status'     => $progress['payment_status'],
            'payment_percentage' => $progress['payment_percentage'],
            'is_overdue'         => $invoice->isPastDue(),
            'project_id'         => $invoice->project_id,
            'project_name'       => $invoice->project?->name ?? $invoice->project_title,
            'lead_id'            => $invoice->lead_id,
            'created_by'         => $invoice->createdBy?->name
                ?? ($invoice->created_by_admin_id ? 'Company Admin' : null),
            'created_at'         => $invoice->created_at?->toIso8601String(),
        ];
    }

    /**
     * One row of Finance > Payments.
     *
     * Expects: invoice (+ invoice.client, invoice.lead), companyGateway,
     * recordedBy, reconciledBy/reconciledByAdmin.
     */
    public static function paymentRow(Payment $payment, array $companyNames = []): array
    {
        $invoice   = $payment->invoice;
        $companyId = $invoice?->company_id;

        return [
            'id'                    => $payment->id,
            // The human-facing payment reference. receipt_number is issued
            // only once a payment is confirmed, so a pending claim falls back
            // to its row id — never null, since the UI keys off it.
            'reference'             => $payment->receipt_number ?: "PAY-{$payment->id}",
            'receipt_number'        => $payment->receipt_number,
            'invoice_id'            => $payment->invoice_id,
            'invoice_number'        => $invoice?->invoice_number,
            'company_id'            => $companyId,
            'company_name'          => $companyId ? ($companyNames[$companyId] ?? null) : null,
            'client_id'             => $invoice?->client_id,
            'customer_name'         => $invoice?->client?->name
                ?? $invoice?->lead?->name
                ?? $invoice?->customer_name
                ?? '—',
            'amount'                => (float) $payment->amount,
            'currency'              => $payment->currency ?? $invoice?->currency,
            'method'                => $payment->method,
            'gateway'               => $payment->gateway,
            'gateway_name'          => self::gatewayName($payment),
            // The transaction reference a finance person reconciles against.
            // Safe: it is the id the gateway itself prints on the merchant's
            // own statement, not a credential.
            'transaction_id'        => $payment->gateway_ref,
            'status'                => $payment->status,
            'payment_date'          => $payment->payment_date?->toDateString(),
            'recorded_by'           => $payment->recordedByName(),
            'reconciliation_status' => $payment->reconciliation_status ?? 'unreconciled',
            'reconciled_at'         => $payment->reconciled_at?->toIso8601String(),
            'reconciled_by'         => $payment->reconciledByName(),
            'reconciliation_note'   => $payment->reconciliation_note,
            'notes'                 => $payment->notes,
            'created_at'            => $payment->created_at?->toIso8601String(),
        ];
    }

    /**
     * Finance > Payment Details — everything paymentRow() carries plus the
     * invoice/project/lead context, and the invoice-vs-payment comparison a
     * reconciliation decision is actually made on.
     */
    public static function paymentDetail(Payment $payment, array $companyNames = []): array
    {
        $invoice = $payment->invoice;

        $row = self::paymentRow($payment, $companyNames);

        $row['gateway_mode']     = $payment->gateway_mode;
        $row['converted_amount'] = $payment->converted_amount !== null ? (float) $payment->converted_amount : null;
        $row['converted_currency'] = $payment->converted_currency;
        $row['exchange_rate']    = $payment->exchange_rate !== null ? (float) $payment->exchange_rate : null;

        $row['invoice'] = $invoice ? [
            'id'                 => $invoice->id,
            'invoice_number'     => $invoice->invoice_number,
            'status'             => $invoice->status,
            'payment_status'     => $invoice->paymentStatus(),
            'currency'           => $invoice->currency,
            'total_amount'       => (float) $invoice->total_amount,
            'paid_amount'        => (float) $invoice->paid_amount,
            'outstanding_amount' => $invoice->paymentProgress()['remaining_amount'],
            'invoice_date'       => $invoice->created_at?->toDateString(),
            'due_date'           => $invoice->due_date?->toDateString(),
            'purpose'            => $invoice->invoice_purpose,
        ] : null;

        // Compare payment against invoice — the one thing "reconcile" means.
        // Computed here so the screen, the export and any future automation
        // all read the same verdict rather than three re-derivations.
        $row['comparison'] = $invoice ? self::comparison($payment, $invoice) : null;

        $row['related'] = [
            'client'  => $invoice?->client ? [
                'id'           => $invoice->client->id,
                'name'         => $invoice->client->name,
                'company_name' => $invoice->client->company_name,
                'email'        => $invoice->client->email,
            ] : null,
            'lead'    => $invoice?->lead ? [
                'id'   => $invoice->lead->id,
                'name' => $invoice->lead->name,
            ] : null,
            'project' => $invoice?->project ? [
                'id'     => $invoice->project->id,
                'name'   => $invoice->project->name,
                'status' => $invoice->project->status,
            ] : null,
        ];

        return $row;
    }

    /**
     * Does this payment line up with its invoice?
     *
     * Deliberately advisory, never enforced: a legitimate part payment is
     * "less than the invoice total" and must not be blocked. The flags tell a
     * human what to look at before they sign it off.
     */
    private static function comparison(Payment $payment, Invoice $invoice): array
    {
        $amount = round((float) $payment->amount, 2);
        $total  = round((float) $invoice->total_amount, 2);
        // Every confirmed payment on this invoice, this one included — the
        // figure that decides whether the invoice is now settled.
        $paid   = round((float) $invoice->paid_amount, 2);

        $paymentCurrency = $payment->currency ?? $invoice->currency;

        return [
            'payment_amount'      => $amount,
            'invoice_total'       => $total,
            'invoice_paid'        => $paid,
            'invoice_outstanding' => max(0, round($total - $paid, 2)),
            'currency_matches'    => $paymentCurrency === null
                || $invoice->currency === null
                || strcasecmp($paymentCurrency, $invoice->currency) === 0,
            'covers_full_invoice' => $amount >= $total - 0.005,
            // Half a cent of tolerance, the same threshold
            // Invoice::paymentStatus() uses, so the two never disagree about
            // whether an invoice is settled.
            'invoice_settled'     => $total > 0 && $paid >= $total - 0.005,
            'overpaid'            => $paid > $total + 0.005,
        ];
    }

    /** One row of Finance > Invoice Reminders. */
    public static function reminderRow(InvoiceReminder $reminder, array $companyNames = []): array
    {
        $invoice = $reminder->invoice;

        return [
            'id'             => $reminder->id,
            'invoice_id'     => $reminder->invoice_id,
            'invoice_number' => $invoice?->invoice_number,
            'company_id'     => $reminder->company_id,
            'company_name'   => $reminder->company_id ? ($companyNames[$reminder->company_id] ?? null) : null,
            'client_id'      => $invoice?->client_id,
            'customer_name'  => $invoice?->client?->name
                ?? $invoice?->lead?->name
                ?? $invoice?->customer_name
                ?? '—',
            'type'           => $reminder->type,
            'reminder_date'  => $reminder->reminder_date?->toDateString(),
            'due_date'       => $invoice?->due_date?->toDateString(),
            'subject'        => $reminder->subject,
            'message'        => $reminder->message,
            'status'         => $reminder->status,
            'sent_at'        => $reminder->sent_at?->toIso8601String(),
            'sent_to'        => $reminder->sent_to,
            'sent_by'        => $reminder->sentByName(),
            'failure_reason' => $reminder->failure_reason,
            'created_by'     => $reminder->createdByName(),
            'created_at'     => $reminder->created_at?->toIso8601String(),
            // The invoice's live balance, so Track can show at a glance
            // whether chasing this one is still worth it.
            'invoice_currency'    => $invoice?->currency,
            'invoice_total'       => $invoice ? (float) $invoice->total_amount : null,
            'invoice_outstanding' => $invoice?->paymentProgress()['remaining_amount'],
            'invoice_status'      => $invoice?->status,
        ];
    }

    /**
     * A gateway account's display name, and nothing else off that table.
     *
     * Only meaningful for an online charge — bank_transfer/cash/cheque have
     * no gateway account behind them. Mirrors the same fallback chain
     * Api\User\InvoiceController::report() already uses.
     */
    private static function gatewayName(Payment $payment): ?string
    {
        if ($payment->method !== 'gateway') {
            return null;
        }

        return $payment->companyGateway?->label
            ?: (CompanyPaymentGateway::GATEWAYS[$payment->gateway] ?? ($payment->gateway ? ucfirst($payment->gateway) : null));
    }
}
