<?php

namespace App\Services\Finance;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Reconciliation — a human confirming that a recorded payment matches its
 * invoice and the money really arrived.
 *
 * Distinct from CONFIRMING a payment, which already exists
 * (Api\Admin\PaymentController::confirm() / Api\User\PaymentController::
 * confirm(), both delegating to InvoicePaymentService). That flips a pending
 * customer-submitted claim to `confirmed` and credits the invoice — it moves
 * money. Reconciliation moves nothing: it records that someone checked. The
 * two are deliberately separate columns for that reason, and this service
 * never touches payments.status, invoices.paid_amount or invoices.status.
 *
 * Because it is bookkeeping rather than money movement it is also reversible:
 * unreconcile() exists so a mistaken sign-off can be undone, and every
 * transition is written to the company audit log either way.
 */
class PaymentReconciliationService
{
    public const STATUSES = ['unreconciled', 'reconciled', 'disputed'];

    /**
     * Sign a payment off, or flag it as disputed.
     *
     * Idempotent in the sense that re-reconciling an already-reconciled
     * payment just restamps who/when — it never double-applies anything,
     * because there is nothing to apply.
     *
     * Callers MUST have already established that $payment is in scope (via
     * FinanceScope) and that the actor holds a reconcile permission. This
     * service enforces neither: it is the write, not the gate, and mixing the
     * two is how a second unguarded path gets added later.
     */
    public static function reconcile(
        FinanceContext $ctx,
        Payment $payment,
        string $status = 'reconciled',
        ?string $note = null,
    ): Payment {
        if (!in_array($status, ['reconciled', 'disputed'], true)) {
            throw new \InvalidArgumentException("Unsupported reconciliation status: {$status}");
        }

        $before = [
            'reconciliation_status' => $payment->reconciliation_status,
            'reconciled_at'         => $payment->reconciled_at?->toIso8601String(),
            'reconciled_by'         => $payment->reconciledByName(),
        ];

        // One statement, so a payment can never end up marked reconciled with
        // no record of who did it (or the reverse) if the request dies
        // mid-write.
        DB::transaction(function () use ($ctx, $payment, $status, $note) {
            $payment->forceFill([
                'reconciliation_status'  => $status,
                'reconciled_at'          => now(),
                // Exactly one of these is ever set; the other is cleared so a
                // payment reconciled by an admin and later re-reconciled by a
                // staff member does not appear to have two reconcilers.
                'reconciled_by'          => $ctx->actorUserId(),
                'reconciled_by_admin_id' => $ctx->actorAdminId(),
                'reconciliation_note'    => $note,
            ])->save();
        });

        $payment->refresh();

        FinanceAudit::log(
            $ctx,
            (int) $payment->invoice?->company_id,
            $status === 'disputed' ? 'finance_payment_disputed' : 'finance_payment_reconciled',
            'Payment',
            $payment->id,
            $status === 'disputed'
                ? "Payment {$payment->receipt_number} on invoice {$payment->invoice?->invoice_number} marked disputed"
                : "Payment {$payment->receipt_number} on invoice {$payment->invoice?->invoice_number} reconciled",
            [
                'invoice_number' => $payment->invoice?->invoice_number,
                'amount'         => (float) $payment->amount,
                'currency'       => $payment->currency,
                'note'           => $note,
            ],
            $before,
        );

        return $payment;
    }

    /** Undo a sign-off, back to 'unreconciled'. */
    public static function unreconcile(FinanceContext $ctx, Payment $payment, ?string $note = null): Payment
    {
        $before = [
            'reconciliation_status' => $payment->reconciliation_status,
            'reconciled_at'         => $payment->reconciled_at?->toIso8601String(),
            'reconciled_by'         => $payment->reconciledByName(),
        ];

        DB::transaction(function () use ($payment, $note) {
            $payment->forceFill([
                'reconciliation_status'  => 'unreconciled',
                'reconciled_at'          => null,
                'reconciled_by'          => null,
                'reconciled_by_admin_id' => null,
                'reconciliation_note'    => $note,
            ])->save();
        });

        $payment->refresh();

        FinanceAudit::log(
            $ctx,
            (int) $payment->invoice?->company_id,
            'finance_payment_unreconciled',
            'Payment',
            $payment->id,
            "Reconciliation removed from payment {$payment->receipt_number} on invoice {$payment->invoice?->invoice_number}",
            ['invoice_number' => $payment->invoice?->invoice_number, 'note' => $note],
            $before,
        );

        return $payment;
    }
}
