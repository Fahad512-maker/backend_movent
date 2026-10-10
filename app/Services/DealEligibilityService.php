<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Payment;

// Single source of truth for "is this Deal (Won Lead) eligible for project
// creation yet" — every place that needs to know reads through here rather
// than re-deriving the sum/comparison locally, so the rule can only ever be
// defined once.
class DealEligibilityService
{
    // Sum of CONFIRMED payments against invoices linked to this Lead that
    // are flagged to count toward activation — reuses Payment.status =
    // 'confirmed' as the sole "verified and counted" signal (the same
    // signal Payment::destroy() already resums from), so refunded/failed/
    // pending/cancelled payments are excluded by construction with no
    // separate verification column needed.
    //
    // Converted to each invoice's PRE-TAX equivalent (proportional to
    // subtotal/total_amount) before summing — requiredAmount() below is a
    // pre-tax deal value (Lead.estimated_value / required_kickoff_amount),
    // and a Payment.amount is money applied against an invoice's post-tax
    // total. Comparing the raw post-tax figure against the pre-tax target
    // overcounts by the tax portion — see App\Services\LeadBudgetContext,
    // which guards against the same mismatch on the invoice-creation side.
    public static function netPaidAmount(Lead $lead): float
    {
        $invoices = $lead->invoices()->where('counts_toward_project_activation', true)
            ->get(['id', 'subtotal', 'total_amount'])->keyBy('id');
        if ($invoices->isEmpty()) {
            return 0.0;
        }

        $payments = Payment::whereIn('invoice_id', $invoices->keys())
            ->where('status', 'confirmed')
            ->get(['invoice_id', 'amount']);

        return round($payments->sum(function (Payment $payment) use ($invoices) {
            $invoice = $invoices->get($payment->invoice_id);
            if (!$invoice || (float) $invoice->total_amount <= 0) {
                return 0.0;
            }

            $preTaxRatio = (float) $invoice->subtotal / (float) $invoice->total_amount;
            return (float) $payment->amount * $preTaxRatio;
        }), 2);
    }

    // The kickoff amount this Deal must clear — an explicitly configured
    // required_kickoff_amount, else the lead's own estimated deal value
    // (never silently 0, so an unconfigured Deal never "passes" on nothing).
    public static function requiredAmount(Lead $lead): float
    {
        return (float) ($lead->required_kickoff_amount ?? $lead->estimated_value ?? 0);
    }

    public static function isEligible(Lead $lead): bool
    {
        $required = self::requiredAmount($lead);
        if ($required <= 0) {
            return false;
        }

        return self::netPaidAmount($lead) >= $required;
    }

    // Recomputes and PERSISTS the Deal's fulfillment_status, returning the
    // new value. Call after anything that could move the needle: invoice
    // create/update, payment confirm/reject/delete.
    public static function recomputeFulfillmentStatus(Lead $lead): string
    {
        if (in_array($lead->status, ['lost']) || $lead->fulfillment_status === 'cancelled') {
            $status = 'cancelled';
        } elseif ($lead->projects()->exists()) {
            $status = 'project_created';
        } elseif (!$lead->invoices()->exists()) {
            $status = 'awaiting_invoice';
        } else {
            $netPaid   = self::netPaidAmount($lead);
            $required  = self::requiredAmount($lead);
            $status = match (true) {
                $required > 0 && $netPaid >= $required => 'eligible_for_project',
                $netPaid > 0                            => 'partially_paid',
                default                                  => 'awaiting_payment',
            };
        }

        if ($lead->fulfillment_status !== $status) {
            $lead->update(['fulfillment_status' => $status]);
        }

        return $status;
    }

    public static function summary(Lead $lead): array
    {
        $fulfillmentStatus = self::recomputeFulfillmentStatus($lead);
        $netPaid  = self::netPaidAmount($lead);
        $required = self::requiredAmount($lead);
        $project  = $lead->projects()->first();
        $latestInvoice = $lead->invoices()->latest()->first([
            'id', 'invoice_number', 'status', 'total_amount', 'paid_amount', 'due_date',
        ]);

        return [
            'deal_reference'           => $lead->deal_reference,
            'proposed_project_title'   => $lead->proposed_project_title,
            'required_kickoff_amount'  => $required,
            'net_paid_amount'          => $netPaid,
            'remaining_amount'         => max(0, round($required - $netPaid, 2)),
            'fulfillment_status'       => $fulfillmentStatus,
            'project_creation_eligible' => self::isEligible($lead),
            'invoice_count'            => $lead->invoices()->count(),
            'latest_invoice'           => $latestInvoice ? [
                'id'             => $latestInvoice->id,
                'invoice_number' => $latestInvoice->invoice_number,
                'status'         => $latestInvoice->status,
                'total_amount'   => (float) $latestInvoice->total_amount,
                'paid_amount'    => (float) $latestInvoice->paid_amount,
                'due_date'       => $latestInvoice->due_date?->toDateString(),
            ] : null,
            'has_project'              => (bool) $project,
            'project_id'               => $project?->id,
            'project_reference'        => $project?->reference,
        ];
    }
}
