<?php

namespace App\Services;

use App\Models\Lead;

// Single source of truth for "how much Won/Pending revenue does this Lead
// represent" — for Sales Dashboard reporting. Distinct from
// DealEligibilityService, which only sums CONFIRMED payments against
// invoices flagged counts_toward_project_activation (the narrower "is this
// deal eligible for project creation yet" gate). This looks at every
// non-draft/non-cancelled invoice tied to the lead via invoices.lead_id,
// regardless of that flag.
class LeadRevenueService
{
    // 'draft' (never sent, not a real commitment yet) and 'cancelled'
    // (voided) invoices carry no revenue signal in either direction.
    private const VALID_STATUSES = ['sent', 'partially_paid', 'paid', 'overdue'];

    // Won vs Pending revenue this lead currently represents:
    //  - No valid invoice yet -> the lead's own estimated_value counts as
    //    provisional Won revenue (a Won deal not yet invoiced is still a win).
    //  - One or more valid invoices -> each invoice's own paid_amount is
    //    real, already-received Won revenue; whatever remains on it
    //    (total_amount - paid_amount) is Pending revenue. A multi-invoice
    //    lead sums across all of them.
    // Callers iterating many leads must eager-load
    // invoices:id,lead_id,status,total_amount,paid_amount first to avoid N+1.
    public static function wonAndPending(Lead $lead): array
    {
        $invoices = $lead->invoices->whereIn('status', self::VALID_STATUSES);

        if ($invoices->isEmpty()) {
            return ['won' => (float) $lead->estimated_value, 'pending' => 0.0];
        }

        return [
            'won'     => (float) $invoices->sum('paid_amount'),
            'pending' => (float) $invoices->sum(fn ($i) => max(0, $i->total_amount - $i->paid_amount)),
        ];
    }
}
