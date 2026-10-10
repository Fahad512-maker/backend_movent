<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Lead;

/**
 * How much of a Lead's budget has already been invoiced.
 *
 * Compared on SUBTOTAL, never total_amount. A lead's budget is what the work
 * was quoted at; an invoice's total adds tax and subtracts any discount on top
 * of that, so a 100,000 lead legitimately produces a 118,000 invoice. Checking
 * the total against the budget would flag every taxed invoice as over budget,
 * which is why the comparable figure is the pre-tax subtotal on both sides.
 *
 * ADVISORY, not a limit. Milestone and advance invoicing are normal — one lead
 * can carry several invoices — and a deal can genuinely grow past its original
 * estimate, so going over is reported and never refused. The one hard rule the
 * Payment Policy enforces is on the paying side: whether a client may settle an
 * invoice in instalments (CompanyDealSettings::requiresFullPayment()).
 *
 * Cancelled invoices are excluded: a withdrawn invoice never consumed budget.
 * Drafts are counted — the money is intended even if the invoice hasn't gone
 * out yet, and leaving them out would let someone quietly draft their way past
 * the estimate with no warning anywhere.
 */
class LeadBudgetContext
{
    /**
     * @param  int|null  $excludeInvoiceId  The invoice being edited, so its own
     *                                      current subtotal isn't counted
     *                                      against the budget it is changing.
     * @return array{budget: float|null, invoiced_subtotal: float, remaining_budget: float|null, over_budget: bool, currency: string|null}
     */
    public static function for(Lead $lead, ?int $excludeInvoiceId = null): array
    {
        $invoiced = round((float) Invoice::where('lead_id', $lead->id)
            ->where('status', '!=', 'cancelled')
            ->when($excludeInvoiceId, fn ($q) => $q->where('id', '!=', $excludeInvoiceId))
            ->sum('subtotal'), 2);

        // No estimate on the lead at all — nothing to measure against, so
        // there is no budget context rather than a budget of zero (which
        // would report every invoice as over budget).
        $budget = $lead->estimated_value !== null ? round((float) $lead->estimated_value, 2) : null;

        return [
            'budget'            => $budget,
            'invoiced_subtotal' => $invoiced,
            'remaining_budget'  => $budget === null ? null : round($budget - $invoiced, 2),
            'over_budget'       => $budget !== null && $invoiced > $budget + 0.005,
            'currency'          => $lead->company?->currency,
        ];
    }
}
