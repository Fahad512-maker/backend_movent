<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The only way the Finance Area is allowed to reach a row.
 *
 * Every Finance query — list, detail, dashboard, report, export, and every
 * write's "may I touch this?" check — starts from one of the three builders
 * here. Nothing in the module composes its own `Invoice::where(...)`, so
 * there is a single place where tenancy is applied and a single place to read
 * if you want to know what a given user can see.
 *
 * Two filters, always, in this order:
 *
 *   1. COMPANY. whereIn(company_id, $ctx->companyIds) — the ids
 *      FinanceContext already proved this caller holds. This is the hard
 *      tenancy boundary: an id typed into the URL by hand simply falls
 *      outside the IN list, the row is not found, and the caller gets the
 *      project's standard 404 rather than a 403 that would confirm the row
 *      exists in some other company.
 *
 *   2. OWNERSHIP. Unless the caller holds a company-wide grant
 *      (FinanceContext::scopeAll()), narrowed to records they are tied to.
 *      The tie test is copied from Api\User\InvoiceController::
 *      visibleInvoices() deliberately, not reinvented — an invoice a Seller
 *      can see in the Invoice module is exactly the invoice they can see in
 *      the Finance Area, so opening Finance to a Seller never shows them a
 *      row they could not already reach.
 *
 * Payments and reminders inherit both filters through their invoice rather
 * than carrying their own copy of the rule, so the three can never disagree.
 */
class FinanceScope
{
    /** Invoice statuses the money rollups ignore — a cancelled invoice is void. */
    public const VOID_STATUSES = ['cancelled'];

    /**
     * The invoices this caller may see, already company- and ownership-scoped.
     */
    public static function invoices(FinanceContext $ctx): Builder
    {
        $query = Invoice::query()->whereIn('invoices.company_id', $ctx->companyIds ?: [0]);

        if ($ctx->scopeAll()) {
            return $query;
        }

        $userId = $ctx->actorUserId();
        if (!$userId) {
            // A Company Admin always passes scopeAll() above, so reaching here
            // without a users id means an unauthenticated or malformed
            // context. Return a builder that matches nothing rather than one
            // that matches everything.
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($userId) {
            $q->where('invoices.created_by', $userId)
                ->orWhereHas('lead', fn ($l) => $l->where('assigned_to', $userId)->orWhere('transferred_to', $userId))
                ->orWhereHas('client', fn ($c) => $c->where('account_manager', $userId))
                ->orWhereHas('project', fn ($p) => $p->where('seller_id', $userId));
        });
    }

    /**
     * The payments this caller may see — every payment of every invoice
     * invoices() returns, and nothing else.
     */
    public static function payments(FinanceContext $ctx): Builder
    {
        return Payment::query()->whereIn(
            'payments.invoice_id',
            self::invoices($ctx)->select('invoices.id')
        );
    }

    /** The reminders this caller may see. Same derivation as payments(). */
    public static function reminders(FinanceContext $ctx): Builder
    {
        return InvoiceReminder::query()->whereIn(
            'invoice_reminders.invoice_id',
            self::invoices($ctx)->select('invoices.id')
        );
    }

    // ── Single-record lookups ───────────────────────────────────────────────
    //
    // Null means "not found" for every reason — wrong company, not yours, or
    // genuinely does not exist — and every caller turns that into one
    // identical 404. Callers must never distinguish the three: telling
    // someone "that invoice exists but is not yours" is itself a disclosure
    // about another company's data.

    public static function findInvoice(FinanceContext $ctx, int $id, array $with = []): ?Invoice
    {
        return self::invoices($ctx)->with($with)->find($id);
    }

    public static function findPayment(FinanceContext $ctx, int $id, array $with = []): ?Payment
    {
        return self::payments($ctx)->with($with)->find($id);
    }

    public static function findReminder(FinanceContext $ctx, int $id, array $with = []): ?InvoiceReminder
    {
        return self::reminders($ctx)->with($with)->find($id);
    }

    // ── Shared filters ──────────────────────────────────────────────────────
    //
    // Applied identically by the list endpoint, the CSV export and the
    // reports, so an export can never contain a row the list on screen did
    // not — the single most common way an export ends up leaking more than
    // the UI showed.

    public static function applyInvoiceFilters(Builder $query, Request $request, FinanceContext $ctx): Builder
    {
        // A company filter can only ever NARROW what companyIds() already
        // allowed — intersected, never substituted.
        if ($request->filled('company_id') && $ctx->ownsCompany((int) $request->company_id)) {
            $query->where('invoices.company_id', (int) $request->company_id);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('invoices.status', $request->status);
        }

        if ($request->filled('currency') && $request->currency !== 'all') {
            $query->where('invoices.currency', $request->currency);
        }

        if ($request->filled('client_id')) {
            $query->where('invoices.client_id', (int) $request->client_id);
        }

        // Date range reads the invoice's own issue date (created_at), which is
        // what "Invoice date" means on every screen in this app.
        if ($request->filled('from')) {
            $query->whereDate('invoices.created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('invoices.created_at', '<=', $request->to);
        }

        // Overdue is not a filterable value of invoices.status alone — an
        // invoice can be 'sent' or 'partially_paid' and still be past due
        // (see Invoice::isPastDue()), so the flag is offered separately.
        if ($request->boolean('overdue_only')) {
            $query->whereNotNull('invoices.due_date')
                ->whereDate('invoices.due_date', '<', now()->toDateString())
                ->whereColumn('invoices.paid_amount', '<', 'invoices.total_amount')
                ->whereNotIn('invoices.status', ['cancelled', 'draft']);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($x) use ($s) {
                $x->where('invoices.invoice_number', 'like', "%{$s}%")
                    ->orWhere('invoices.customer_name', 'like', "%{$s}%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$s}%")
                        ->orWhere('company_name', 'like', "%{$s}%"));
            });
        }

        return $query;
    }

    public static function applyPaymentFilters(Builder $query, Request $request, FinanceContext $ctx): Builder
    {
        if ($request->filled('company_id') && $ctx->ownsCompany((int) $request->company_id)) {
            $query->whereHas('invoice', fn ($i) => $i->where('company_id', (int) $request->company_id));
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('payments.status', $request->status);
        }

        if ($request->filled('reconciliation_status') && $request->reconciliation_status !== 'all') {
            $query->where('payments.reconciliation_status', $request->reconciliation_status);
        }

        if ($request->filled('method') && $request->method !== 'all') {
            $query->where('payments.method', $request->method);
        }

        if ($request->filled('gateway') && $request->gateway !== 'all') {
            $query->where('payments.gateway', $request->gateway);
        }

        if ($request->filled('currency') && $request->currency !== 'all') {
            $query->where('payments.currency', $request->currency);
        }

        if ($request->filled('client_id')) {
            $query->whereHas('invoice', fn ($i) => $i->where('client_id', (int) $request->client_id));
        }

        if ($request->filled('from')) {
            $query->whereDate('payments.payment_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('payments.payment_date', '<=', $request->to);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($x) use ($s) {
                $x->where('payments.receipt_number', 'like', "%{$s}%")
                    ->orWhere('payments.gateway_ref', 'like', "%{$s}%")
                    ->orWhereHas('invoice', fn ($i) => $i->where('invoice_number', 'like', "%{$s}%")
                        ->orWhere('customer_name', 'like', "%{$s}%")
                        ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$s}%")));
            });
        }

        return $query;
    }
}
