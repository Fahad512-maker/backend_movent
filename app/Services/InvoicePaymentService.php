<?php

namespace App\Services;

use App\Mail\PaymentConfirmedMail;
use App\Mail\PaymentReceivedMail;
use App\Models\AuditTrail;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\PaymentGatewayWebhookEvent;
use App\Models\SystemAuditLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class InvoicePaymentService
{
    /**
     * Generate the next receipt number for a company: RCP-YYYY-NNNN
     */
    public static function nextReceiptNumber(int $companyId): string
    {
        $year   = now()->year;
        $prefix = 'RCP';

        $last = Payment::whereHas('invoice', fn($q) => $q->where('company_id', $companyId))
            ->whereYear('created_at', $year)
            ->where('receipt_number', 'like', "{$prefix}-{$year}-%")
            ->latest('id')
            ->value('receipt_number');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        do {
            $number = sprintf('%s-%d-%04d', $prefix, $year, $seq++);
        } while (Payment::where('receipt_number', $number)->exists());

        return $number;
    }

    /**
     * Update invoice paid_amount and status after a payment is recorded.
     * Used by public link and client portal flows.
     *
     * This is the single place an invoice becomes paid/partially_paid, so it is
     * also where the Deal Workflow's project kickoff hangs off — see
     * PaymentProjectStartService, which is a no-op unless the tenant switched
     * "Automatically create project after payment" on. It is safe to reach here
     * more than once for the same invoice: the service keys off
     * invoices.project_id and won't start a second project.
     *
     * It is also where a lead-linked invoice being paid IN FULL auto-marks its
     * Lead Won — see LeadDealService::markWonFromPayment(), a no-op for an
     * invoice not yet fully settled or a Lead already Won/Lost.
     *
     * And where the Client Portal's persisted AI-suggested-services batch
     * gets updated — see ClientAiServiceBatchService::markTakenIfPaid()/
     * generateIfNeeded(), unlike the Deal Workflow above these run for every
     * company unconditionally, on both 'paid' and 'partially_paid'.
     */
    public static function applyToInvoice(Invoice $invoice, Payment $payment): void
    {
        // A payment recorded without its own currency captured (e.g. by a
        // path that predates that column being populated) is assumed to be
        // in the invoice's own currency, same as every current
        // payment-creation path already assumes — backfilled here rather
        // than left null, so the mismatch check below is never skipped.
        if (!$payment->currency) {
            $payment->currency = $invoice->currency;
            $payment->save();
        }

        // Payment.amount/currency must always be in the invoice's own
        // currency (converted_amount/converted_currency are separate audit
        // fields for whatever was actually sent to the gateway) — reject
        // rather than silently corrupt paid_amount/status against the wrong
        // currency's number.
        if ($invoice->currency && strcasecmp($payment->currency, $invoice->currency) !== 0) {
            Log::error('Refusing to apply payment with mismatched currency to invoice', [
                'invoice_id' => $invoice->id, 'payment_id' => $payment->id,
                'invoice_currency' => $invoice->currency, 'payment_currency' => $payment->currency,
            ]);
            throw new \RuntimeException('Payment currency does not match invoice currency.');
        }

        // Derived fresh from every confirmed payment row (Remaining Due =
        // Invoice Total - Total Successful Payments), not incrementally
        // added to the previous paid_amount — matches Admin\PaymentController
        // ::destroy()'s recompute exactly, so paid_amount can never drift
        // from the sum of what's actually on the payments table (e.g. if
        // this were ever called twice for the same payment).
        $newPaid = round((float) $invoice->payments()->where('status', 'confirmed')->sum('amount'), 2);
        $invoice->paid_amount = $newPaid;
        $invoice->status      = $newPaid >= (float) $invoice->total_amount
            ? 'paid'
            : 'partially_paid';
        $invoice->save();

        // The payment receipt artifact (see App\Services\PaymentReceiptService).
        // Placed here, immediately after the invoice's own status and
        // paid_amount are settled, because this is the single point every
        // payment path converges on — gateway return, webhook, the Admin's and
        // the Seller's manual confirm — so one hook covers all of them and the
        // receipt always states the status the invoice actually ended up in.
        //
        // generateQuietly() swallows everything and the payment_id unique key
        // makes it a no-op on a second pass, which matters because this method
        // is documented as safe to reach more than once for the same payment.
        // A receipt is an artifact OF a payment; it must never be able to stop
        // one being recorded.
        PaymentReceiptService::generateQuietly($invoice, $payment);

        // Client Portal's persisted AI-suggested-services batch (see
        // App\Services\ClientAiServiceBatchService) — deliberately NOT
        // nested inside PaymentProjectStartService below, so this runs for
        // every company regardless of whether Deal Workflow
        // auto-project-creation is configured. Neither call ever throws;
        // wrapped here anyway as defense in depth, same posture as every
        // other side effect on this path — an AI/DB hiccup here must never
        // stop a payment from being recorded.
        try {
            ClientAiServiceBatchService::markTakenIfPaid($invoice);
        } catch (\Throwable $e) {
            Log::warning('[client-ai-services] mark-taken invoice ' . $invoice->id . ': ' . $e->getMessage());
        }
        try {
            ClientAiServiceBatchService::generateIfNeeded($invoice);
        } catch (\Throwable $e) {
            Log::warning('[client-ai-services] generate invoice ' . $invoice->id . ': ' . $e->getMessage());
        }

        if ($invoice->lead_id && ($lead = $invoice->lead)) {
            LeadDealService::markWonFromPayment($lead, $invoice);
        }

        PaymentProjectStartService::handle($invoice);
    }

    /**
     * Record a payment somebody received off-platform and is entering by
     * hand — a bank transfer, cash, a cheque, or a gateway charge being
     * captured after the fact.
     *
     * Extracted from Api\Admin\PaymentController::store(), which now calls
     * it, so the Finance Area's own Record Payment action runs the identical
     * code rather than a second copy that would drift. Everything that made
     * that method correct is preserved here: the Payment Policy check, the
     * outstanding-balance ceiling, the invoice's own currency being stamped
     * on the row, a receipt number issued up front, and applyToInvoice()
     * doing the crediting (and with it the receipt artifact, the Deal
     * kickoff hook and the lead auto-Won rule).
     *
     * $actor is whoever is entering it: ['user_id' => int] for a staff
     * member, ['admin_id' => int] for a Company Admin. Both columns stay
     * null for the automated paths, which do not come through here.
     *
     * Validation of the REQUEST belongs to the caller — it owns the error
     * shape and the field names. What this method guarantees is that a
     * recorded payment is always consistent with its invoice.
     *
     * @param array{amount: float|string, method?: ?string, payment_date?: ?string, notes?: ?string, gateway?: ?string, gateway_ref?: ?string} $data
     * @param array{user_id?: ?int, admin_id?: ?int} $actor
     */
    public static function record(Invoice $invoice, array $data, array $actor = []): Payment
    {
        $payment = Payment::create([
            'invoice_id'           => $invoice->id,
            'receipt_number'       => self::nextReceiptNumber($invoice->company_id),
            'amount'               => $data['amount'],
            // Always the invoice's own currency. The caller has already
            // validated the amount against that invoice's outstanding
            // balance, so there is no second currency to capture — and
            // stamping it explicitly (rather than leaving it null) is what
            // lets every report group payments by currency correctly.
            'currency'             => $invoice->currency,
            'method'               => $data['method']       ?? null,
            'payment_date'         => $data['payment_date'] ?? now()->toDateString(),
            'notes'                => $data['notes']        ?? null,
            'gateway'              => $data['gateway']      ?? null,
            'gateway_ref'          => $data['gateway_ref']  ?? null,
            'recorded_by'          => $actor['user_id']  ?? null,
            'recorded_by_admin_id' => $actor['admin_id'] ?? null,
            // A payment entered by hand by someone who can see the money is
            // already confirmed — this is not a customer's unverified claim
            // (those arrive via the public link as 'pending'). It is still
            // UNreconciled: recording and signing off are separate acts by
            // design, which is the whole point of the Finance reconciliation
            // column.
            'status'               => 'confirmed',
        ]);

        self::applyToInvoice($invoice, $payment);

        return $payment;
    }

    /**
     * The largest payment this invoice can still accept, and the smallest it
     * is allowed to — the two bounds every manual Record Payment form
     * validates against.
     *
     * The floor is the tenant's Payment Policy: under "Full Payment Only" a
     * part payment has to be refused here exactly as it is on the client's
     * own checkout (see Api\PublicInvoiceController::pay()), because an admin
     * or finance user typing an amount is still choosing one.
     *
     * @return array{min: float, max: float, requires_full: bool}
     */
    public static function recordableRange(Invoice $invoice): array
    {
        $outstanding = round((float) $invoice->total_amount - (float) $invoice->paid_amount, 2);
        $requiresFull = \App\Models\CompanyDealSettings::forInvoice($invoice)?->requiresFullPayment() ?? false;

        return [
            'min'           => $requiresFull ? $outstanding : 0.01,
            'max'           => $outstanding,
            'requires_full' => $requiresFull,
        ];
    }

    /**
     * Write a payment audit entry.
     * channel: 'public_link' | 'client_portal' | 'admin'
     */
    public static function logPayment(
        Invoice $invoice,
        Payment $payment,
        string  $channel,
        ?string $ip = null
    ): void {
        try {
            AuditTrail::create([
                'company_id'  => $invoice->company_id,
                'module'      => 'invoices',
                'action'      => 'payment_received',
                'entity_type' => 'payment',
                'entity_id'   => $payment->id,
                'new_values'  => [
                    'invoice_number' => $invoice->invoice_number,
                    'amount'         => (float) $payment->amount,
                    'method'         => $payment->method,
                    'receipt_number' => $payment->receipt_number,
                    'status'         => $payment->status,
                    'channel'        => $channel,
                    'invoice_status' => $invoice->status,
                ],
                'ip_address' => $ip,
            ]);
        } catch (\Throwable) {}
    }

    /**
     * Log a public invoice link view.
     */
    public static function logView(Invoice $invoice, ?string $ip = null): void
    {
        try {
            AuditTrail::create([
                'company_id'  => $invoice->company_id,
                'module'      => 'invoices',
                'action'      => 'public_link_viewed',
                'entity_type' => 'invoice',
                'entity_id'   => $invoice->id,
                'new_values'  => [
                    'invoice_number' => $invoice->invoice_number,
                    'status'         => $invoice->status,
                ],
                'ip_address' => $ip,
            ]);
        } catch (\Throwable) {}
    }

    /**
     * Notify the company admin that a payment claim was submitted via the
     * public link and is awaiting their verification — an email, plus an
     * in-app SystemAuditLog row (Admin\NotificationController reads all
     * SystemAuditLog rows for the company, unfiltered by action, as its bell).
     */
    public static function notifyAdmin(Invoice $invoice, Payment $payment): void
    {
        try {
            $invoice->loadMissing('company.admin');
            $admin = $invoice->company->admin ?? null;
            if ($admin?->email) {
                Mail::to($admin->email)->send(new PaymentReceivedMail($invoice, $payment));
            }
        } catch (\Throwable) {}

        try {
            SystemAuditLog::create([
                'company_id'  => $invoice->company_id,
                'user_id'     => null,
                'action'      => 'invoice_payment_submitted',
                'module_key'  => 'invoice',
                'entity_type' => 'Invoice',
                'entity_id'   => $invoice->id,
                'new_values'  => [
                    'invoice_number' => $invoice->invoice_number,
                    'amount'         => (float) $payment->amount,
                    'preview'        => "Payment claim of {$invoice->currency} {$payment->amount} awaiting confirmation",
                    'sender'         => $invoice->customer_name,
                ],
            ]);
        } catch (\Throwable) {}
    }

    /**
     * Log the admin's confirm/reject decision on a payment claim — feeds both
     * the compliance AuditTrail and the Admin's own SystemAuditLog bell.
     * decision: 'confirmed' | 'rejected'
     */
    public static function logDecision(Invoice $invoice, Payment $payment, string $decision, ?int $adminUserId = null): void
    {
        try {
            AuditTrail::create([
                'company_id'  => $invoice->company_id,
                'module'      => 'invoices',
                'action'      => $decision === 'confirmed' ? 'payment_confirmed' : 'payment_rejected',
                'entity_type' => 'payment',
                'entity_id'   => $payment->id,
                'new_values'  => [
                    'invoice_number' => $invoice->invoice_number,
                    'amount'         => (float) $payment->amount,
                    'status'         => $payment->status,
                    'invoice_status' => $invoice->status,
                ],
            ]);
        } catch (\Throwable) {}

        try {
            SystemAuditLog::create([
                'company_id'  => $invoice->company_id,
                'user_id'     => null, // acted by Company Admin, not a `users` row
                'action'      => $decision === 'confirmed' ? 'invoice_payment_confirmed' : 'invoice_payment_rejected',
                'module_key'  => 'invoice',
                'entity_type' => 'Invoice',
                'entity_id'   => $invoice->id,
                'new_values'  => [
                    'invoice_number' => $invoice->invoice_number,
                    'amount'         => (float) $payment->amount,
                    'preview'        => $decision === 'confirmed'
                        ? "Invoice {$invoice->invoice_number} paid"
                        : "Payment claim on {$invoice->invoice_number} rejected",
                    'sender'         => $invoice->customer_name,
                ],
            ]);
        } catch (\Throwable) {}
    }

    /**
     * Notify the invoice's creator (seller) once a payment has actually been
     * confirmed. Also notifies Company Admin — structurally impossible via a
     * plain Notification::create() (Admin isn't a `users` row), so this goes
     * through NotificationService instead. $actorAdminId is set only when a
     * Company Admin manually confirmed the payment (Admin\PaymentController::confirm()) —
     * left null for the automated gateway/webhook/client-portal paths, which
     * have no human actor to exclude and where Admin should always see it.
     *
     * Previously also notified every company user holding the 'finance'
     * module's canRecordPayments permission — but that permission is granted
     * by default to every Seller and Lead Manager (RoleDefaultPermissions),
     * so in practice this fired for the whole team on every payment instead
     * of just this invoice's own seller. Scoped down to only the invoice's
     * creator, as reported.
     */
    public static function notifyStakeholders(Invoice $invoice, Payment $payment, ?int $actorAdminId = null): void
    {
        $recipients = collect([$invoice->created_by])->filter()->unique();

        // A handoff-eligible invoice (came from a lead, no project linked
        // yet) gets a Seller-facing "you can now create a project handoff"
        // nudge instead of the generic paid copy — same notification call,
        // same recipients, just conditionally different title/body. No new
        // notification type or recipient added.
        $handoffEligible = $invoice->lead_id && !$invoice->project_id;
        $title = $handoffEligible ? 'Invoice paid' : "Invoice {$invoice->invoice_number} paid";
        $body  = $handoffEligible
            ? "Invoice {$invoice->invoice_number} has been paid. You can now create a project handoff."
            : "Invoice {$invoice->invoice_number} has been paid by {$invoice->customer_name}.";

        foreach ($recipients as $uid) {
            try {
                Notification::create([
                    'user_id'    => $uid,
                    'company_id' => $invoice->company_id,
                    'type'       => 'invoice_paid',
                    'title'      => $title,
                    'body'       => $body,
                    'data'       => [
                        'invoice_id' => $invoice->id,
                        'payment_id' => $payment->id,
                        'link'       => "/invoices/{$invoice->id}",
                    ],
                ]);
            } catch (\Throwable) {}
        }

        try {
            NotificationService::notifyCompanyAdmins($invoice->company_id, $actorAdminId, [
                'module'      => 'finance',
                'type'        => 'invoice_paid',
                'title'       => 'Invoice paid',
                'message'     => "Invoice '{$invoice->invoice_number}' has been paid by {$invoice->customer_name}.",
                'entity_type' => 'Invoice',
                'entity_id'   => $invoice->id,
                'url'         => "/admin/invoices/{$invoice->id}",
            ]);
        } catch (\Throwable) {}

        // The Client Portal side of this same event — reached from every
        // payment-confirmation path via this one method (gateway webhook,
        // Admin's manual confirm, Seller's manual confirm), so the client is
        // notified exactly once per confirmed payment regardless of channel.
        InvoiceNotificationService::notifyClientInvoicePaid($invoice, $payment);
    }

    /**
     * Notify the invoice creator + Company Admin when a payment attempt
     * fails/is rejected — no notification of any kind existed for this event
     * before (only the same generic 'invoice_payment_rejected' audit-log
     * action used for a manual reject, with nobody actually alerted).
     */
    public static function notifyPaymentFailed(Invoice $invoice, Payment $payment, ?string $reason = null, ?int $actorAdminId = null): void
    {
        $message = $reason
            ? "Payment attempt for invoice '{$invoice->invoice_number}' failed: {$reason}"
            : "Payment attempt for invoice '{$invoice->invoice_number}' failed or was rejected.";

        if ($invoice->created_by) {
            try {
                Notification::create([
                    'user_id'    => $invoice->created_by,
                    'company_id' => $invoice->company_id,
                    'type'       => 'invoice_payment_failed',
                    'title'      => 'Invoice payment failed',
                    'body'       => $message,
                    'data'       => [
                        'invoice_id' => $invoice->id,
                        'payment_id' => $payment->id,
                        'link'       => "/invoices/{$invoice->id}",
                    ],
                ]);
            } catch (\Throwable) {}
        }

        try {
            NotificationService::notifyCompanyAdmins($invoice->company_id, $actorAdminId, [
                'module'      => 'finance',
                'type'        => 'invoice_payment_failed',
                'title'       => 'Invoice payment failed',
                'message'     => $message,
                'entity_type' => 'Invoice',
                'entity_id'   => $invoice->id,
                'url'         => "/admin/invoices/{$invoice->id}",
            ]);
        } catch (\Throwable) {}
    }

    /**
     * Update the linked client's activity trail once their invoice is paid —
     * a no-op when the invoice isn't linked to a client (e.g. one-off/manual
     * customer details only).
     */
    public static function logClientActivity(Invoice $invoice, Payment $payment): void
    {
        if (!$invoice->client_id) return;

        try {
            SystemAuditLog::create([
                'company_id'  => $invoice->company_id,
                'user_id'     => null,
                'action'      => 'client_invoice_paid',
                'module_key'  => 'client',
                'entity_type' => 'Client',
                'entity_id'   => $invoice->client_id,
                'new_values'  => [
                    'invoice_number' => $invoice->invoice_number,
                    'amount'         => (float) $payment->amount,
                    'preview'        => "Invoice {$invoice->invoice_number} paid in full or in part",
                ],
            ]);
        } catch (\Throwable) {}
    }

    /**
     * Note a paid (or partially paid) invoice on its originating lead's
     * activity timeline — a no-op when the invoice isn't linked to a lead.
     * Mirrors logClientActivity() above (same trigger, same "full or in
     * part" wording) so Lead-activity and Client-activity stay in parity.
     */
    public static function logLeadActivity(Invoice $invoice, Payment $payment): void
    {
        if (!$invoice->lead_id) return;

        try {
            Lead::find($invoice->lead_id)?->logActivity(
                'note_added',
                "Invoice {$invoice->invoice_number} payment of {$invoice->currency} {$payment->amount} received"
            );
        } catch (\Throwable) {}
    }

    /**
     * Emails the client that their payment succeeded, and separately notifies
     * the Company Admin by email too (in addition to the in-app bell entry
     * logDecision() already writes).
     */
    public static function notifyPaymentConfirmedEmails(Invoice $invoice, Payment $payment): void
    {
        try {
            $clientEmail = $invoice->client?->email ?? $invoice->customer_email;
            if ($clientEmail) {
                Mail::to($clientEmail)->send(new PaymentConfirmedMail($invoice, $payment, forAdmin: false));
            }
        } catch (\Throwable) {}

        try {
            $invoice->loadMissing('company.admin');
            $adminEmail = $invoice->company->admin->email ?? null;
            if ($adminEmail) {
                Mail::to($adminEmail)->send(new PaymentConfirmedMail($invoice, $payment, forAdmin: true));
            }
        } catch (\Throwable) {}
    }

    /**
     * Finalize a real-gateway payment as successful — idempotent, safe to
     * call more than once (e.g. once from the customer's return-callback and
     * again from the authoritative webhook): a payment that's already
     * confirmed or failed is left alone and this returns false.
     */
    public static function finalizeGatewaySuccess(Payment $payment, string $transactionId): bool
    {
        if ($payment->status !== 'pending') return false;

        $invoice = $payment->invoice;

        // Defense in depth against two pending payments for the same invoice
        // both somehow resolving successfully (e.g. a race between two
        // checkout attempts) — never credit an invoice that's already paid.
        if ($invoice->status === 'paid') return false;

        $payment->update([
            'status'         => 'confirmed',
            'gateway_ref'    => $transactionId,
            'receipt_number' => $payment->receipt_number ?? self::nextReceiptNumber($invoice->company_id),
        ]);

        self::applyToInvoice($invoice, $payment);
        self::logDecision($invoice, $payment, 'confirmed');
        self::notifyStakeholders($invoice, $payment);
        self::logClientActivity($invoice, $payment);
        self::logLeadActivity($invoice, $payment);
        self::notifyPaymentConfirmedEmails($invoice, $payment);

        return true;
    }

    /**
     * Finalize a real-gateway payment as failed/cancelled — invoice status
     * and paid_amount are left untouched (they were never applied for a
     * pending gateway payment in the first place).
     */
    public static function finalizeGatewayFailure(Payment $payment, ?string $reason = null): bool
    {
        if ($payment->status !== 'pending') return false;

        $payment->update([
            'status' => 'failed',
            'notes'  => trim(($payment->notes ? $payment->notes . ' — ' : '') . ($reason ?? 'Gateway reported failure')),
        ]);

        self::logDecision($payment->invoice, $payment, 'rejected');
        self::notifyPaymentFailed($payment->invoice, $payment, $reason);

        return true;
    }

    // Webhook idempotency — gateways retry delivery on anything but a clean
    // 2xx response, so the same event id can legitimately arrive more than once.
    public static function webhookAlreadyProcessed(string $gateway, string $eventId): bool
    {
        return PaymentGatewayWebhookEvent::where('gateway', $gateway)->where('event_id', $eventId)->exists();
    }

    public static function markWebhookProcessed(string $gateway, string $eventId): void
    {
        PaymentGatewayWebhookEvent::firstOrCreate(['gateway' => $gateway, 'event_id' => $eventId]);
    }
}
