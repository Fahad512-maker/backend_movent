<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Collection;

// Single source of truth for "how much has this set of invoices actually
// billed/collected" — used anywhere a simple invoiced/paid/outstanding/
// overdue rollup is needed (Client detail page's invoice stats, Sales
// Dashboard's Invoice Summary). total_amount/paid_amount are already kept
// correct by InvoicePaymentService on every recorded payment (partial or
// full), so this never re-derives them from raw Payment rows — it only
// aggregates the two columns that already reflect real, successful
// payments.
class InvoiceStatsService
{
    /**
     * @param Collection $invoices Any collection of Invoice models (or rows
     *                             with id/due_date/total_amount/paid_amount/
     *                             status loaded) — company/date scoping is
     *                             the caller's responsibility.
     */
    public static function summarize(Collection $invoices): array
    {
        self::refreshOverdueStatus($invoices);

        // A cancelled invoice is void — it was never really billed, so it must
        // not inflate the money rollups below. total_count still counts every
        // document in the set (it answers "how many invoices am I looking at",
        // not "how much money"); the status-derived counts further down are
        // unaffected either way, since a cancelled invoice's status is
        // 'cancelled' and so never matches 'overdue'/partially-paid.
        $billable = $invoices->where('status', '!=', 'cancelled');

        return [
            'total_count'            => $invoices->count(),
            'total_invoiced'         => (float) $billable->sum('total_amount'),
            'total_paid'             => (float) $billable->sum('paid_amount'),
            'total_outstanding'      => (float) $billable->sum(fn ($i) => max((float) $i->total_amount - (float) $i->paid_amount, 0)),
            'overdue_count'          => $invoices->where('status', 'overdue')->count(),
            // Computed directly from the amounts (paid_amount > 0 AND still
            // something left owing), not the `status` column — a genuinely
            // partial invoice stays partial regardless of also being
            // 'overdue', and this can never disagree with the status label
            // going stale the way overdue_count could before the self-heal
            // above.
            'partially_paid_count'  => $invoices->filter(
                fn ($i) => (float) $i->paid_amount > 0 && ((float) $i->total_amount - (float) $i->paid_amount) > 0
            )->count(),
        ];
    }

    // `invoices:mark-overdue` is scheduled ->daily() (routes/console.php),
    // but Laravel's scheduler only ever fires if something hits
    // `schedule:run` every minute via a real OS cron — this host has none
    // (no SSH access on movent.io, nothing set up in local dev either), so a
    // sent/partially_paid invoice can sit well past its due_date forever
    // without ever flipping to 'overdue'. Self-heals here instead: any
    // invoice in the set that's actually overdue gets updated on the spot
    // (both in the DB and in-memory, so overdue_count is correct THIS
    // request even though the row was stale a moment ago) — cheap since
    // it's scoped to whatever set the caller already fetched, not a
    // full-table scan.
    private static function refreshOverdueStatus(Collection $invoices): void
    {
        $today = now()->toDateString();
        $staleIds = [];

        foreach ($invoices as $invoice) {
            if (in_array($invoice->status, ['sent', 'partially_paid'], true)
                && $invoice->due_date
                && $invoice->due_date->toDateString() < $today) {
                $invoice->status = 'overdue';
                $staleIds[] = $invoice->id;
            }
        }

        if ($staleIds) {
            Invoice::whereIn('id', $staleIds)->update(['status' => 'overdue']);
        }
    }
}
